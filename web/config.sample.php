<?php
// Copy this file to config.php and fill it in. Never share config.php.
return [
    // Shown in the header and browser tab.
    'site_name' => 'Guild Bank',
    // Must match the in-game guild name exactly; uploads from other guilds are rejected.
    'guild_name' => 'Your Guild Name',
    // Full public address of the site, no trailing slash.
    'base_url' => 'https://bank.your-guild-domain.com',
    'timezone' => 'Europe/Amsterdam',

    // Database (cyberfolks: create it in the control panel, then import schema.sql).
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=YOUR_DB_NAME;charset=utf8mb4',
        'user' => 'YOUR_DB_USER',
        'pass' => 'YOUR_DB_PASSWORD',
    ],

    // Discord application (https://discord.com/developers/applications → OAuth2).
    // Add this redirect there: {base_url}/index.php?route=callback
    'discord' => [
        'client_id' => '',
        'client_secret' => '',
        // Your Discord server's ID (right-click the server icon → Copy Server ID, needs Developer Mode).
        'guild_id' => '',
        // How often (seconds) to re-check someone's roles while they are logged in.
        'role_refresh_seconds' => 900,
    ],

    // Officer notifications: Discord channel → Edit → Integrations → Webhooks → New → Copy URL.
    // Leave empty to turn notifications off.
    'webhook_url' => '',

    // Keys the uploader uses. Generate long random strings, one per person who runs the uploader.
    'upload_keys' => [
        // 'officer-1' => 'replace-with-a-long-random-string',
    ],

    // Ranks, highest first. Someone gets the FIRST rank whose Discord role they have.
    // People with none of these roles can log in but see nothing.
    //  tabs:        'all' or a list of tab numbers they may see (same as their in-game tab access)
    //  money_log:   may see gold deposits/withdrawals
    //  can_request: may request withdrawals
    //  can_approve: may approve/deny requests and see everyone's requests
    'ranks' => [
        ['name' => 'Guild Master', 'role_id' => '000000000000000001', 'tabs' => 'all',
         'money_log' => true, 'can_request' => true, 'can_approve' => true],
        ['name' => 'Officer', 'role_id' => '000000000000000002', 'tabs' => 'all',
         'money_log' => true, 'can_request' => true, 'can_approve' => true],
        ['name' => 'Raider', 'role_id' => '000000000000000003', 'tabs' => [1, 2, 3],
         'money_log' => false, 'can_request' => true, 'can_approve' => false],
        ['name' => 'Member', 'role_id' => '000000000000000004', 'tabs' => [1],
         'money_log' => false, 'can_request' => true, 'can_approve' => false],
    ],
];
