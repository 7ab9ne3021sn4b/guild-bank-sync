<?php
declare(strict_types=1);

const REQUEST_STATUSES = ['pending', 'approved', 'denied', 'fulfilled', 'cancelled'];

/** Total count of an item across the tabs this rank can see. */
function available_quantity(int $itemId, array $tabs): array
{
    if (!$tabs) {
        return ['count' => 0, 'name' => null, 'quality' => null];
    }
    $in = implode(',', array_fill(0, count($tabs), '?'));
    $row = q("SELECT SUM(count) AS total, MAX(name) AS name, MAX(quality) AS quality
              FROM bank_items WHERE item_id = ? AND tab_index IN ($in)", array_merge([$itemId], $tabs))->fetch();
    return ['count' => (int)($row['total'] ?? 0), 'name' => $row['name'] ?? null, 'quality' => $row['quality'] ?? null];
}

/** Returns an error message, or null on success. */
function create_request(array $user, array $rank, int $itemId, int $quantity, string $note): ?string
{
    if (!can($rank, 'can_request')) {
        return 'Your rank cannot request withdrawals.';
    }
    if (trim((string)$user['character_name']) === '') {
        return 'Set your character name on your profile first, so officers know who to give it to.';
    }
    if ($quantity < 1) {
        return 'Pick a quantity of at least 1.';
    }
    $avail = available_quantity($itemId, rank_tabs($rank));
    if ($avail['count'] < 1) {
        return 'That item is not in the bank (or not in a tab you can see).';
    }
    if ($quantity > $avail['count']) {
        return "Only {$avail['count']} available.";
    }
    $openCount = (int)q("SELECT COUNT(*) FROM requests WHERE user_id = ? AND status IN ('pending','approved')",
        [$user['id']])->fetchColumn();
    if ($openCount >= 10) {
        return 'You already have 10 open requests. Wait for some to be handled first.';
    }
    $note = mb_substr(trim($note), 0, 300);
    q('INSERT INTO requests (user_id, character_name, item_id, item_name, quality, quantity, note, status, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $user['id'], $user['character_name'], $itemId, $avail['name'], $avail['quality'], $quantity,
        $note !== '' ? $note : null, 'pending', time(),
    ]);
    $id = (int)db()->lastInsertId();
    notify("New withdrawal request #$id",
        sprintf("**%s** (%s) wants %d× %s%s", $user['character_name'], $rank['name'], $quantity,
            $avail['name'] ?: "item #$itemId", $note !== '' ? "\n> " . $note : ''),
        0xf1c40f, cfg('base_url') . '/' . url('requests', ['view' => 'officer']));
    return null;
}

/** Officer decisions and member cancellations. Returns an error message, or null. */
function act_on_request(array $user, ?array $rank, int $requestId, string $action, string $note = ''): ?string
{
    $req = q('SELECT * FROM requests WHERE id = ?', [$requestId])->fetch();
    if (!$req) {
        return 'Request not found.';
    }
    $note = mb_substr(trim($note), 0, 300);
    $item = $req['item_name'] ?: 'item #' . $req['item_id'];

    if ($action === 'cancel') {
        if ((int)$req['user_id'] !== (int)$user['id'] && !can($rank, 'can_approve')) {
            return 'You can only cancel your own requests.';
        }
        if (!in_array($req['status'], ['pending', 'approved'], true)) {
            return 'This request is already closed.';
        }
        q("UPDATE requests SET status = 'cancelled', decided_at = ? WHERE id = ?", [time(), $requestId]);
        return null;
    }

    if (!can($rank, 'can_approve')) {
        return 'Only officers can do that.';
    }
    $officer = $user['character_name'] ?: $user['username'];

    switch ($action) {
        case 'approve':
            if ($req['status'] !== 'pending') {
                return 'Only pending requests can be approved.';
            }
            q("UPDATE requests SET status = 'approved', decided_by = ?, decision_note = ?, decided_at = ? WHERE id = ?",
                [$user['id'], $note ?: null, time(), $requestId]);
            notify("Request #$requestId approved",
                sprintf('%s approved %d× %s for **%s**.%s', $officer, $req['quantity'], $item, $req['character_name'],
                    $note ? "\n> $note" : ''), 0x3498db);
            return null;
        case 'deny':
            if (!in_array($req['status'], ['pending', 'approved'], true)) {
                return 'This request is already closed.';
            }
            q("UPDATE requests SET status = 'denied', decided_by = ?, decision_note = ?, decided_at = ? WHERE id = ?",
                [$user['id'], $note ?: null, time(), $requestId]);
            notify("Request #$requestId denied",
                sprintf('%s denied %d× %s for **%s**.%s', $officer, $req['quantity'], $item, $req['character_name'],
                    $note ? "\n> $note" : ''), 0xe74c3c);
            return null;
        case 'fulfil':
            if (!in_array($req['status'], ['pending', 'approved'], true)) {
                return 'This request is already closed.';
            }
            q("UPDATE requests SET status = 'fulfilled', fulfilled_qty = quantity, fulfilled_at = ?,
               decided_by = COALESCE(decided_by, ?), decided_at = COALESCE(decided_at, ?) WHERE id = ?",
                [time(), $user['id'], time(), $requestId]);
            notify("Request #$requestId marked done",
                sprintf('%s marked %d× %s for **%s** as handed over.', $officer, $req['quantity'], $item,
                    $req['character_name']), 0x2ecc71);
            return null;
    }
    return 'Unknown action.';
}
