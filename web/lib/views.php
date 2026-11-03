<?php
declare(strict_types=1);

function layout(string $title, string $body, ?array $user = null, ?array $rank = null, string $active = ''): void
{
    $site = (string)cfg('site_name', 'Guild Bank');
    $flash = flash();
    $nav = '';
    if ($user && $rank) {
        $links = ['bank' => 'Bank', 'history' => 'History', 'requests' => 'Requests'];
        $pending = 0;
        if (can($rank, 'can_approve')) {
            $pending = (int)q("SELECT COUNT(*) FROM requests WHERE status = 'pending'")->fetchColumn();
        }
        foreach ($links as $route => $label) {
            $badge = ($route === 'requests' && $pending) ? ' <span class="badge">' . $pending . '</span>' : '';
            $nav .= '<a href="' . e(url($route === 'bank' ? '' : $route)) . '"' . ($active === $route ? ' class="on"' : '') . '>'
                . e($label) . $badge . '</a>';
        }
    }
    $who = '';
    if ($user) {
        $who = '<a class="who" href="' . e(url('profile')) . '"' . ($active === 'profile' ? ' aria-current="page"' : '') . '>'
            . '<img src="' . e(avatar_url($user)) . '" alt="" width="28" height="28">'
            . '<span>' . e($user['character_name'] ?: $user['username']) . '<small>' . e($rank['name'] ?? 'No rank') . '</small></span></a>';
    }
    $flashHtml = $flash ? '<div class="flash ' . e($flash['kind']) . '" role="status">' . e($flash['msg']) . '</div>' : '';
    $siteE = e($site);
    $titleE = e($title === $site ? $site : "$title · $site");
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titleE}</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='3' y='9' width='26' height='18' rx='3' fill='%23c9a227'/%3E%3Crect x='3' y='9' width='26' height='6' rx='3' fill='%23e8c65a'/%3E%3Crect x='13' y='15' width='6' height='6' rx='1' fill='%232a2016'/%3E%3C/svg%3E">
</head>
<body>
<header class="top">
  <div class="wrap top-in">
    <a class="brand" href="index.php"><span class="crest" aria-hidden="true"></span>{$siteE}</a>
    <nav>{$nav}</nav>
    {$who}
  </div>
</header>
<main class="wrap">
{$flashHtml}
{$body}
</main>
</body>
</html>
HTML;
}

function page_login(): string
{
    $site = e(cfg('site_name', 'Guild Bank'));
    $guild = e(cfg('guild_name', ''));
    return <<<HTML
<section class="hero">
  <span class="crest big" aria-hidden="true"></span>
  <h1>{$site}</h1>
  <p class="muted">The {$guild} guild bank: what's in it, what moved, and a place to ask for things.</p>
  <a class="btn discord" href="index.php?route=login">Log in with Discord</a>
  <p class="fine">You see the same tabs here as your rank can see in game.</p>
</section>
HTML;
}

function page_message(string $heading, string $text): string
{
    return '<section class="hero"><h1>' . e($heading) . '</h1><p class="muted">' . $text . '</p></section>';
}

function status_pill(string $status): string
{
    $labels = ['pending' => 'Pending', 'approved' => 'Approved', 'denied' => 'Denied',
        'fulfilled' => 'Done', 'cancelled' => 'Cancelled'];
    return '<span class="pill ' . e($status) . '">' . e($labels[$status] ?? $status) . '</span>';
}

function tx_description(array $t, array $tabNames): string
{
    $item = item_html($t['item_name'], $t['quality'], $t['item_id']);
    $count = $t['count'] !== null && (int)$t['count'] > 1 ? ' ×' . (int)$t['count'] : '';
    $tab = fn($i) => e($tabNames[(int)$i] ?? ('Tab ' . (int)$i));
    switch ($t['type']) {
        case 'deposit':
            return (int)$t['tab_index'] === MONEY_TAB
                ? 'deposited ' . money_html($t['copper'])
                : 'deposited ' . $item . $count;
        case 'withdraw':
            return (int)$t['tab_index'] === MONEY_TAB
                ? 'withdrew ' . money_html($t['copper'])
                : 'withdrew ' . $item . $count;
        case 'move':
            return 'moved ' . $item . $count . ' from ' . $tab($t['from_tab']) . ' to ' . $tab($t['to_tab']);
        case 'repair':
            return 'repaired for ' . money_html($t['copper']);
        case 'withdrawForTab':
            return 'withdrew ' . money_html($t['copper']) . ' to buy a tab';
        case 'buyTab':
            return 'bought a bank tab for ' . money_html($t['copper']);
        case 'depositSummary':
            return 'deposited ' . money_html($t['copper']) . ' (loot share)';
    }
    $extra = (int)$t['tab_index'] === MONEY_TAB ? money_html($t['copper']) : $item . $count;
    return e($t['type']) . ' ' . $extra;
}
