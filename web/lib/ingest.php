<?php
declare(strict_types=1);

const MONEY_TAB = 0;
const MATCH_TOLERANCE = 7200; // log times are hour-precision; allow 2h either way

/**
 * Applies one addon snapshot. Returns a summary array, or throws InvalidArgumentException.
 */
function ingest_snapshot(array $snap): array
{
    if (($snap['v'] ?? null) !== 1 || !isset($snap['tabs']) || !is_array($snap['tabs'])) {
        throw new InvalidArgumentException('Not a GuildBankSync snapshot.');
    }
    $guild = trim((string)($snap['guild'] ?? ''));
    $expected = trim((string)cfg('guild_name', ''));
    if ($expected !== '' && mb_strtolower($guild) !== mb_strtolower($expected)) {
        throw new InvalidArgumentException("Snapshot is from guild '{$guild}', expected '{$expected}'.");
    }

    $now = time();
    $scanTime = (int)($snap['time'] ?? 0);
    if ($scanTime <= 0 || $scanTime > $now + 300) {
        $scanTime = $now;
    }
    $scanTime = min($scanTime, $now);
    $scanner = mb_substr((string)($snap['scanner'] ?? ''), 0, 50) ?: null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tabsUpdated = 0;
        $newRows = [];

        foreach ($snap['tabs'] as $tab) {
            $index = (int)($tab['index'] ?? 0);
            if ($index < 1 || $index > 20) {
                continue;
            }
            $current = q('SELECT updated_at FROM bank_tabs WHERE tab_index = ?', [$index])->fetch();
            // Never let an older snapshot overwrite newer contents.
            if (!$current || (int)$current['updated_at'] <= $scanTime) {
                q('DELETE FROM bank_tabs WHERE tab_index = ?', [$index]);
                q('INSERT INTO bank_tabs (tab_index, name, icon, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)', [
                    $index, mb_substr((string)($tab['name'] ?? "Tab $index"), 0, 100),
                    isset($tab['icon']) ? mb_substr((string)$tab['icon'], 0, 100) : null, $scanTime, $scanner,
                ]);
                q('DELETE FROM bank_items WHERE tab_index = ?', [$index]);
                $ins = $pdo->prepare('INSERT INTO bank_items (tab_index, slot, item_id, name, quality, count) VALUES (?, ?, ?, ?, ?, ?)');
                $seenSlots = [];
                foreach ((array)($tab['items'] ?? []) as $item) {
                    $slot = (int)($item['slot'] ?? 0);
                    $id = (int)($item['id'] ?? 0);
                    if ($slot < 1 || $id < 1 || isset($seenSlots[$slot])) {
                        continue;
                    }
                    $seenSlots[$slot] = true;
                    $ins->execute([$index, $slot, $id, clean_name($item['name'] ?? null),
                        nullable_int($item['q'] ?? null), max(1, (int)($item['count'] ?? 1))]);
                }
                $tabsUpdated++;
            }

            $rows = [];
            foreach ((array)($tab['log'] ?? []) as $entry) {
                $rows[] = [
                    'tab_index' => $index,
                    'type' => mb_substr((string)($entry['type'] ?? 'unknown'), 0, 30),
                    'player' => mb_substr((string)($entry['player'] ?? 'Unknown'), 0, 50),
                    'item_id' => nullable_int($entry['id'] ?? null),
                    'item_name' => clean_name($entry['name'] ?? null),
                    'quality' => nullable_int($entry['q'] ?? null),
                    'count' => nullable_int($entry['count'] ?? null),
                    'from_tab' => nullable_int($entry['from'] ?? null),
                    'to_tab' => nullable_int($entry['to'] ?? null),
                    'copper' => null,
                    'approx_time' => $scanTime - max(0, (int)($entry['ago'] ?? 0)) * 3600,
                ];
            }
            $newRows = array_merge($newRows, insert_transactions($rows));
        }

        $moneyRows = [];
        foreach ((array)($snap['moneyLog'] ?? []) as $entry) {
            $moneyRows[] = [
                'tab_index' => MONEY_TAB,
                'type' => mb_substr((string)($entry['type'] ?? 'unknown'), 0, 30),
                'player' => mb_substr((string)($entry['player'] ?? 'Unknown'), 0, 50),
                'item_id' => null, 'item_name' => null, 'quality' => null, 'count' => null,
                'from_tab' => null, 'to_tab' => null,
                'copper' => (int)($entry['amount'] ?? 0),
                'approx_time' => $scanTime - max(0, (int)($entry['ago'] ?? 0)) * 3600,
            ];
        }
        $newRows = array_merge($newRows, insert_transactions($moneyRows));

        if (isset($snap['money']) && is_numeric($snap['money']) && (int)setting('money_updated_at', 0) <= $scanTime) {
            set_setting('money', (int)$snap['money']);
            set_setting('money_updated_at', $scanTime);
        }
        set_setting('last_upload_at', $now);
        if ($scanner) {
            set_setting('last_upload_by', $scanner);
        }

        $fulfilled = fulfil_requests_from($newRows);

        q('INSERT INTO uploads (scanner, scanned_at, received_at, tabs_updated, new_transactions) VALUES (?, ?, ?, ?, ?)',
            [$scanner, $scanTime, $now, $tabsUpdated, count($newRows)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    foreach ($fulfilled as $req) {
        notify('Withdrawal completed', sprintf('%s picked up %d× %s (request #%d).',
            $req['character_name'], $req['quantity'], $req['item_name'] ?: 'item #' . $req['item_id'], $req['id']),
            0x2ecc71, cfg('base_url') . '/' . url('requests'));
    }

    return [
        'tabs_updated' => $tabsUpdated,
        'new_transactions' => count($newRows),
        'requests_fulfilled' => count($fulfilled),
    ];
}

function clean_name($name): ?string
{
    if ($name === null || $name === '') {
        return null;
    }
    return mb_substr((string)$name, 0, 150);
}

function nullable_int($v): ?int
{
    return ($v === null || $v === '') ? null : (int)$v;
}

function transaction_fingerprint(array $r): string
{
    return sha1(implode('|', [
        $r['tab_index'], $r['type'], mb_strtolower($r['player']), $r['item_id'] ?? '',
        $r['count'] ?? '', $r['from_tab'] ?? '', $r['to_tab'] ?? '', $r['copper'] ?? '',
    ]));
}

/**
 * The bank log has no IDs and only hour-precision times, and every upload repeats
 * entries we already have. An entry counts as known when an identical entry exists
 * within the tolerance window and hasn't been matched by another entry of this upload.
 * Returns the rows that were actually new.
 */
function insert_transactions(array $rows): array
{
    $find = db()->prepare('SELECT id FROM transactions WHERE fingerprint = ? AND approx_time BETWEEN ? AND ?
                           ORDER BY ABS(approx_time - ?) ASC');
    $insert = db()->prepare('INSERT INTO transactions (tab_index, type, player, item_id, item_name, quality, count,
        from_tab, to_tab, copper, approx_time, fingerprint, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $claimed = [];
    $new = [];
    foreach ($rows as $r) {
        $fp = transaction_fingerprint($r);
        $t = $r['approx_time'];
        $find->execute([$fp, $t - MATCH_TOLERANCE, $t + MATCH_TOLERANCE, $t]);
        $match = null;
        foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!isset($claimed[$id])) {
                $match = (int)$id;
                break;
            }
        }
        if ($match !== null) {
            $claimed[$match] = true;
            continue;
        }
        $insert->execute([$r['tab_index'], $r['type'], $r['player'], $r['item_id'], $r['item_name'], $r['quality'],
            $r['count'], $r['from_tab'], $r['to_tab'], $r['copper'], $t, $fp, time()]);
        $id = (int)db()->lastInsertId();
        $claimed[$id] = true;
        $r['id'] = $id;
        $new[] = $r;
    }
    return $new;
}

/** Matches new withdrawals against approved requests. Returns requests that became fulfilled. */
function fulfil_requests_from(array $newRows): array
{
    $done = [];
    foreach ($newRows as $r) {
        if ($r['type'] !== 'withdraw' || empty($r['item_id']) || empty($r['count'])) {
            continue;
        }
        $remaining = (int)$r['count'];
        $open = q("SELECT * FROM requests WHERE status = 'approved' AND item_id = ? AND LOWER(character_name) = ?
                   AND created_at <= ? ORDER BY created_at ASC",
            [$r['item_id'], mb_strtolower($r['player']), $r['approx_time'] + MATCH_TOLERANCE])->fetchAll();
        foreach ($open as $req) {
            if ($remaining <= 0) {
                break;
            }
            $need = (int)$req['quantity'] - (int)$req['fulfilled_qty'];
            $take = min($need, $remaining);
            $remaining -= $take;
            $filled = (int)$req['fulfilled_qty'] + $take;
            if ($filled >= (int)$req['quantity']) {
                q("UPDATE requests SET fulfilled_qty = ?, status = 'fulfilled', fulfilled_at = ? WHERE id = ?",
                    [$filled, time(), $req['id']]);
                $req['fulfilled_qty'] = $filled;
                $done[] = $req;
            } else {
                q('UPDATE requests SET fulfilled_qty = ? WHERE id = ?', [$filled, $req['id']]);
            }
        }
    }
    return $done;
}

/** POST index.php?route=api/upload — called by the desktop uploader. */
function handle_upload_api(): void
{
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST only']);
        return;
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $auth = $v;
            }
        }
    }
    $key = preg_match('/^Bearer\s+(.+)$/i', (string)$auth, $m) ? trim($m[1]) : '';
    $valid = false;
    foreach ((array)cfg('upload_keys', []) as $candidate) {
        if ($key !== '' && is_string($candidate) && strlen($candidate) >= 16 && hash_equals($candidate, $key)) {
            $valid = true;
        }
    }
    if (!$valid) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid upload key']);
        return;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 2_000_000) {
        http_response_code(413);
        echo json_encode(['error' => 'Snapshot too large']);
        return;
    }
    $snap = json_decode($raw, true);
    if (!is_array($snap)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    try {
        $summary = ingest_snapshot($snap);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
        return;
    }
    echo json_encode(['ok' => true, 'summary' => $summary]);
}
