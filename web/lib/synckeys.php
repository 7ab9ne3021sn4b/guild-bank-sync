<?php
declare(strict_types=1);

// Per-officer sync keys and downloadable sync kits.
// Keys are stored only as SHA-256 hashes; the key itself exists only inside the kit the officer downloads.

const KIT_DIR = __DIR__ . '/../kit';

/** Creates the sync_keys table on first use, so upgrades need no manual SQL. */
function ensure_sync_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if ((int)setting('schema_version', 1) >= 2) {
        return;
    }
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        db()->exec('CREATE TABLE IF NOT EXISTS sync_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT NOT NULL,
            key_hash CHAR(64) NOT NULL, key_hint VARCHAR(8) NOT NULL, created_at INT NOT NULL,
            last_used_at INT NULL, revoked_at INT NULL)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_sync_hash ON sync_keys (key_hash)');
    } else {
        db()->exec('CREATE TABLE IF NOT EXISTS sync_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            key_hash CHAR(64) NOT NULL,
            key_hint VARCHAR(8) NOT NULL,
            created_at INT NOT NULL,
            last_used_at INT NULL,
            revoked_at INT NULL,
            KEY idx_sync_hash (key_hash),
            KEY idx_sync_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    set_setting('schema_version', 2);
}

/** Ranks may set 'can_sync'; otherwise everyone who can approve requests may sync. */
function can_sync(?array $rank): bool
{
    if ($rank === null) {
        return false;
    }
    return array_key_exists('can_sync', $rank) ? (bool)$rank['can_sync'] : can($rank, 'can_approve');
}

function active_sync_key(int $userId): ?array
{
    ensure_sync_schema();
    $row = q('SELECT * FROM sync_keys WHERE user_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1', [$userId])->fetch();
    return $row ?: null;
}

/** Makes a new key for this user (replacing any old one) and returns it in plain text. */
function create_sync_key(array $user): string
{
    ensure_sync_schema();
    $key = 'gbs_' . rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
    q('UPDATE sync_keys SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [time(), $user['id']]);
    q('INSERT INTO sync_keys (user_id, key_hash, key_hint, created_at) VALUES (?, ?, ?, ?)',
        [$user['id'], hash('sha256', $key), substr($key, -4), time()]);
    return $key;
}

function revoke_sync_key(int $keyId): void
{
    ensure_sync_schema();
    q('UPDATE sync_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [time(), $keyId]);
}

/**
 * Checks an uploader key. Returns a label for who it belongs to, or null if it isn't valid.
 * Accepts the fixed keys in config.php as well as officers' own keys from the website.
 */
function authenticate_upload_key(string $key): ?string
{
    if ($key === '') {
        return null;
    }
    foreach ((array)cfg('upload_keys', []) as $label => $candidate) {
        if (is_string($candidate) && strlen($candidate) >= 16 && hash_equals($candidate, $key)) {
            return 'config:' . $label;
        }
    }
    if (!str_starts_with($key, 'gbs_')) {
        return null;
    }
    ensure_sync_schema();
    $row = q('SELECT k.id, k.last_used_at, u.* FROM sync_keys k JOIN users u ON u.id = k.user_id
              WHERE k.key_hash = ? AND k.revoked_at IS NULL', [hash('sha256', $key)])->fetch();
    if (!$row) {
        return null;
    }
    // The officer must still hold a rank that may sync (roles as of their last visit to the site).
    if (!can_sync(user_rank($row))) {
        return null;
    }
    if (time() - (int)$row['last_used_at'] > 60) {
        q('UPDATE sync_keys SET last_used_at = ? WHERE id = ?', [time(), $row['id']]);
    }
    return 'user:' . ($row['character_name'] ?: $row['username']);
}

/** Reads the Bearer key from the request. */
function request_bearer_key(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower((string)$k) === 'authorization') {
                $auth = $v;
            }
        }
    }
    return preg_match('/^Bearer\s+(.+)$/i', (string)$auth, $m) ? trim($m[1]) : '';
}

/** Builds a sync kit zip for this key and sends it to the browser. */
function send_sync_kit(array $user, string $key): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is not enabled on this hosting.');
    }
    $guild = (string)cfg('guild_name', 'Guild');
    $site = rtrim((string)cfg('base_url'), '/');
    $top = preg_replace('/[^A-Za-z0-9 _-]/', '', $guild) ?: 'Guild';
    $top .= ' Bank Kit';

    $tmp = tempnam(sys_get_temp_dir(), 'gbskit');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the kit zip.');
    }

    $crlf = fn(string $s): string => preg_replace("/\r?\n/", "\r\n", $s);
    // Quote for a PowerShell single-quoted string, then escape for use in a preg_replace replacement.
    $psQuote = fn(string $s): string => strtr(str_replace("'", "''", $s), ['\\' => '\\\\', '$' => '\\$']);

    foreach (glob(KIT_DIR . '/addon/GuildBankSync/*') as $file) {
        $zip->addFile($file, "$top/1 - Copy into AddOns/GuildBankSync/" . basename($file));
    }

    // The uploader files are stored as .txt on the server (so hosting virus scanners don't flag
    // script files) and get their real names inside the kit.
    $ps = (string)file_get_contents(KIT_DIR . '/uploader/gbs-uploader.ps1.txt');
    $ps = preg_replace('/^\$GuildName = \'.*\'$/m', '$GuildName = \'' . $psQuote($guild) . '\'', $ps, 1);
    $ps = preg_replace('/^\$SiteUrl   = \'.*\'$/m', '$SiteUrl   = \'' . $psQuote($site) . '\'', $ps, 1);
    $ps = preg_replace('/^\$ApiKey    = \'.*\'$/m', '$ApiKey    = \'' . $psQuote($key) . '\'', $ps, 1);
    $zip->addFromString("$top/2 - Uploader/gbs-uploader.ps1", $crlf($ps));
    $zip->addFromString("$top/2 - Uploader/start-uploader.bat",
        $crlf((string)file_get_contents(KIT_DIR . '/uploader/start-uploader.bat.txt')));
    $bat = (string)file_get_contents(KIT_DIR . '/uploader/start-uploader-console.bat.txt');
    $bat = str_replace('title Guild Bank Uploader', 'title ' . $top . ' Uploader', $bat);
    $zip->addFromString("$top/2 - Uploader/start-uploader-console.bat", $crlf($bat));

    $readme = (string)file_get_contents(KIT_DIR . '/READ ME FIRST.txt');
    $readme = strtr($readme, ['{GUILD}' => $guild, '{SITE}' => $site,
        '{OWNER}' => $user['character_name'] ?: $user['username']]);
    $zip->addFromString("$top/READ ME FIRST.txt", $crlf($readme));
    $zip->close();

    $fileName = strtolower(str_replace(' ', '-', $top)) . '-' .
        strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)($user['character_name'] ?: $user['username'])) ?: 'officer') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
}

/** GET index.php?route=api/handouts — approved requests for the in-game hand-out list. */
function handle_handouts_api(): void
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (authenticate_upload_key(request_bearer_key()) === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid upload key']);
        return;
    }
    $rows = q("SELECT r.id, r.character_name, r.item_id, r.item_name, r.quality, r.quantity, r.fulfilled_qty,
                      r.decided_at, u.character_name AS approver_char, u.username AS approver_name
               FROM requests r LEFT JOIN users u ON u.id = r.decided_by
               WHERE r.status = 'approved' ORDER BY r.decided_at ASC LIMIT 50")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'character' => $r['character_name'],
            'item_id' => (int)$r['item_id'],
            'item_name' => $r['item_name'],
            'quality' => $r['quality'] === null ? null : (int)$r['quality'],
            'quantity' => (int)$r['quantity'] - (int)$r['fulfilled_qty'],
            'approved_by' => $r['approver_char'] ?: $r['approver_name'],
            'approved_at' => (int)$r['decided_at'],
        ];
    }
    echo json_encode(['ok' => true, 'time' => time(), 'requests' => $out]);
}
