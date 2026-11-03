<?php
declare(strict_types=1);

const DISCORD_API = 'https://discord.com/api/v10';

function discord_redirect_uri(): string
{
    return rtrim((string)cfg('base_url'), '/') . '/index.php?route=callback';
}

function discord_login_url(): string
{
    $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
    return 'https://discord.com/oauth2/authorize?' . http_build_query([
        'client_id' => cfg('discord.client_id'),
        'redirect_uri' => discord_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'identify guilds.members.read',
        'state' => $_SESSION['oauth_state'],
        'prompt' => 'none',
    ]);
}

function discord_token_request(array $fields): ?array
{
    $fields += [
        'client_id' => cfg('discord.client_id'),
        'client_secret' => cfg('discord.client_secret'),
    ];
    $res = http_request('POST', DISCORD_API . '/oauth2/token',
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query($fields));
    if ($res['status'] !== 200 || empty($res['body']['access_token'])) {
        return null;
    }
    return $res['body'];
}

/**
 * Returns ['ok' => bool, 'roles' => string[], 'in_guild' => bool].
 */
function discord_fetch_member(string $accessToken): array
{
    $guild = rawurlencode((string)cfg('discord.guild_id'));
    $res = http_request('GET', DISCORD_API . "/users/@me/guilds/{$guild}/member",
        ['Authorization: Bearer ' . $accessToken]);
    if ($res['status'] === 200) {
        return ['ok' => true, 'in_guild' => true, 'roles' => array_map('strval', $res['body']['roles'] ?? [])];
    }
    if ($res['status'] === 404) {
        return ['ok' => true, 'in_guild' => false, 'roles' => []];
    }
    return ['ok' => false, 'in_guild' => false, 'roles' => []];
}

/** Handles the OAuth callback. Returns the user id or an error string. */
function discord_handle_callback(): int|string
{
    $state = $_GET['state'] ?? '';
    if (!is_string($state) || empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
        return 'The login link expired. Please try again.';
    }
    unset($_SESSION['oauth_state']);
    if (!empty($_GET['error'])) {
        return 'Discord login was cancelled.';
    }
    $code = $_GET['code'] ?? '';
    if (!is_string($code) || $code === '') {
        return 'Discord did not send a login code.';
    }

    $token = discord_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => discord_redirect_uri(),
    ]);
    if (!$token) {
        return 'Could not complete the Discord login. Check the client ID, secret and redirect URL in config.php.';
    }

    $me = http_request('GET', DISCORD_API . '/users/@me', ['Authorization: Bearer ' . $token['access_token']]);
    if ($me['status'] !== 200 || empty($me['body']['id'])) {
        return 'Could not read your Discord profile.';
    }
    $member = discord_fetch_member($token['access_token']);
    if (!$member['ok']) {
        return 'Discord is busy right now. Please try again in a minute.';
    }

    $d = $me['body'];
    $username = $d['global_name'] ?? $d['username'] ?? 'Unknown';
    $now = time();
    $fields = [
        $username,
        $d['avatar'] ?? null,
        json_encode($member['roles']),
        $token['access_token'],
        $token['refresh_token'] ?? null,
        $now + (int)($token['expires_in'] ?? 3600) - 60,
        $now,
        $now,
    ];

    $existing = q('SELECT id FROM users WHERE discord_id = ?', [(string)$d['id']])->fetch();
    if ($existing) {
        q('UPDATE users SET username = ?, avatar = ?, roles = ?, access_token = ?, refresh_token = ?,
           token_expires = ?, roles_checked_at = ?, last_seen_at = ? WHERE id = ?',
            array_merge($fields, [$existing['id']]));
        $id = (int)$existing['id'];
    } else {
        q('INSERT INTO users (username, avatar, roles, access_token, refresh_token, token_expires,
           roles_checked_at, last_seen_at, discord_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge($fields, [(string)$d['id'], $now]));
        $id = (int)db()->lastInsertId();
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    return $id;
}

/** Re-reads someone's Discord roles. Returns false when they need to log in again. */
function discord_refresh_roles(array $user): bool
{
    $access = (string)$user['access_token'];
    if ($access === '' || time() >= (int)$user['token_expires']) {
        if (empty($user['refresh_token'])) {
            return false;
        }
        $token = discord_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $user['refresh_token']]);
        if (!$token) {
            return false;
        }
        $access = $token['access_token'];
        q('UPDATE users SET access_token = ?, refresh_token = ?, token_expires = ? WHERE id = ?', [
            $access, $token['refresh_token'] ?? $user['refresh_token'],
            time() + (int)($token['expires_in'] ?? 3600) - 60, $user['id'],
        ]);
    }
    $member = discord_fetch_member($access);
    if (!$member['ok']) {
        // Discord hiccup or rate limit: keep the old roles, try again in a minute.
        q('UPDATE users SET roles_checked_at = ? WHERE id = ?',
            [time() - (int)cfg('discord.role_refresh_seconds', 900) + 60, $user['id']]);
        return true;
    }
    q('UPDATE users SET roles = ?, roles_checked_at = ? WHERE id = ?',
        [json_encode($member['roles']), time(), $user['id']]);
    return true;
}

function avatar_url(array $user): string
{
    if (!empty($user['avatar'])) {
        return 'https://cdn.discordapp.com/avatars/' . rawurlencode($user['discord_id']) . '/' . rawurlencode($user['avatar']) . '.png?size=64';
    }
    $n = ((int)substr((string)$user['discord_id'], -3)) % 6;
    return "https://cdn.discordapp.com/embed/avatars/{$n}.png";
}
