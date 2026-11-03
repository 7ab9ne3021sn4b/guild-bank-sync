<?php
declare(strict_types=1);

// Errors go to logs/php-errors.log, never to the visitor's screen.
const GBS_LOG_DIR = __DIR__ . '/../logs';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(GBS_LOG_DIR) || @mkdir(GBS_LOG_DIR, 0755, true)) {
    ini_set('error_log', GBS_LOG_DIR . '/php-errors.log');
}
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    error_log(sprintf('[ref %s] %s: %s in %s:%d%s%s', $ref, get_class($e), $e->getMessage(),
        $e->getFile(), $e->getLine(), PHP_EOL, $e->getTraceAsString()));
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (($_GET['route'] ?? '') === 'api/upload') {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Server error', 'ref' => $ref]);
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Something went wrong</title><link rel="stylesheet" href="assets/style.css">'
        . '<main class="wrap"><section class="hero"><h1>Something went wrong</h1>'
        . '<p class="muted">Please try again in a moment. If it keeps happening, tell an officer and mention code <b>' . $ref . '</b>.</p>'
        . '<a class="btn" href="index.php">Back to the bank</a></section></main>';
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
            . '<p style="font-family:sans-serif;padding:2rem">Something went wrong. Please try again in a moment.</p>';
    }
});

$configFile = getenv('GBS_CONFIG') ?: __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing config.php. Copy config.sample.php to config.php and fill it in.');
}
$GLOBALS['CONFIG'] = require $configFile;
date_default_timezone_set(cfg('timezone', 'UTC'));

require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/ingest.php';
require_once __DIR__ . '/requests.php';
require_once __DIR__ . '/views.php';

function cfg(string $key, $default = null)
{
    $value = $GLOBALS['CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(cfg('db.dsn'), cfg('db.user'), cfg('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function setting(string $name, $default = null)
{
    $row = q('SELECT value FROM settings WHERE name = ?', [$name])->fetch();
    return $row ? $row['value'] : $default;
}

function set_setting(string $name, $value): void
{
    q('DELETE FROM settings WHERE name = ?', [$name]);
    q('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, (string)$value]);
}

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function url(string $route = '', array $params = []): string
{
    $params = $route === '' ? $params : ['route' => $route] + $params;
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = str_starts_with((string)cfg('base_url', ''), 'https://');
    session_name('gbsid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

function flash(?string $message = null, string $kind = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['msg' => $message, 'kind' => $kind];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------------------
// Users and ranks
// ---------------------------------------------------------------------------

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }
    $row = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    if (!$row) {
        unset($_SESSION['user_id']);
        return null;
    }
    if (time() - (int)$row['roles_checked_at'] > (int)cfg('discord.role_refresh_seconds', 900)) {
        if (!discord_refresh_roles($row)) {
            unset($_SESSION['user_id']);
            flash('Please log in again.', 'warn');
            return null;
        }
        $row = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    }
    if (time() - (int)$row['last_seen_at'] > 300) {
        q('UPDATE users SET last_seen_at = ? WHERE id = ?', [time(), $id]);
    }
    $user = $row;
    return $user;
}

function rank_for_roles(array $roleIds): ?array
{
    foreach (cfg('ranks', []) as $rank) {
        if (in_array((string)$rank['role_id'], $roleIds, true)) {
            return $rank;
        }
    }
    return null;
}

function user_rank(?array $user): ?array
{
    if (!$user) {
        return null;
    }
    $roles = json_decode((string)$user['roles'], true) ?: [];
    return rank_for_roles(array_map('strval', $roles));
}

function rank_tabs(?array $rank): array
{
    if (!$rank) {
        return [];
    }
    if ($rank['tabs'] === 'all') {
        return array_map('intval', array_column(q('SELECT tab_index FROM bank_tabs ORDER BY tab_index')->fetchAll(), 'tab_index'));
    }
    return array_map('intval', (array)$rank['tabs']);
}

function can(?array $rank, string $perm): bool
{
    return $rank !== null && !empty($rank[$perm]);
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect(url('login'));
    }
    return $user;
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

function money_html($copper): string
{
    $copper = (int)$copper;
    $neg = $copper < 0;
    $copper = abs($copper);
    $g = intdiv($copper, 10000);
    $s = intdiv($copper % 10000, 100);
    $c = $copper % 100;
    $parts = [];
    if ($g) {
        $parts[] = '<span class="gold">' . number_format($g) . 'g</span>';
    }
    if ($s || $g) {
        $parts[] = '<span class="silver">' . $s . 's</span>';
    }
    $parts[] = '<span class="copper">' . $c . 'c</span>';
    return '<span class="money">' . ($neg ? '−' : '') . implode(' ', $parts) . '</span>';
}

function quality_class($q): string
{
    $q = $q === null ? 1 : (int)$q;
    return 'q' . max(0, min(7, $q));
}

function item_html(?string $name, $quality, $itemId = null): string
{
    $label = $name !== null && $name !== '' ? $name : ($itemId ? 'Item #' . (int)$itemId : 'Unknown item');
    return '<span class="item ' . quality_class($quality) . '">[' . e($label) . ']</span>';
}

function ago(int $ts): string
{
    $d = time() - $ts;
    if ($d < 60) {
        return 'just now';
    }
    if ($d < 3600) {
        return intdiv($d, 60) . ' min ago';
    }
    if ($d < 86400) {
        $h = intdiv($d, 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    $days = intdiv($d, 86400);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

function when(int $ts): string
{
    return '<time datetime="' . e(date('c', $ts)) . '" title="' . e(date('j M Y H:i', $ts)) . '">' . e(ago($ts)) . '</time>';
}

// ---------------------------------------------------------------------------
// HTTP helper + Discord webhook
// ---------------------------------------------------------------------------

function http_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: GuildBankSync (+' . cfg('base_url') . ')'], $headers),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        return ['status' => 0, 'body' => null, 'error' => $error];
    }
    return ['status' => $status, 'body' => json_decode($response, true), 'raw' => $response];
}

function notify(string $title, string $description, int $color = 0x3399ff, ?string $link = null): void
{
    $hook = (string)cfg('webhook_url', '');
    if ($hook === '') {
        return;
    }
    $embed = ['title' => mb_substr($title, 0, 250), 'description' => mb_substr($description, 0, 2000), 'color' => $color];
    if ($link) {
        $embed['url'] = $link;
    }
    $payload = json_encode(['embeds' => [$embed], 'allowed_mentions' => ['parse' => []]]);
    http_request('POST', $hook, ['Content-Type: application/json'], $payload);
}
