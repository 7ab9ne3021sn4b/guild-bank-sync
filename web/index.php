<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$route = (string)($_GET['route'] ?? '');

// The uploader API doesn't use sessions.
if ($route === 'api/upload') {
    handle_upload_api();
    exit;
}
if ($route === 'api/handouts') {
    handle_handouts_api();
    exit;
}

start_session();

switch ($route) {
    case 'login':
        if (current_user()) {
            redirect(url());
        }
        redirect(discord_login_url());

    case 'callback':
        $result = discord_handle_callback();
        if (is_string($result)) {
            layout('Login problem', page_message('Login problem', e($result) . ' <a href="' . e(url('login')) . '">Try again</a>.'));
            exit;
        }
        $user = current_user();
        if ($user && trim((string)$user['character_name']) === '' && user_rank($user)) {
            flash('Welcome! Set your character name so officers know who to hand items to.');
            redirect(url('profile'));
        }
        redirect(url());

    case 'logout':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            $_SESSION = [];
            session_destroy();
        }
        redirect(url());
}

$user = current_user();
if (!$user) {
    layout(cfg('site_name', 'Guild Bank'), page_login());
    exit;
}
$rank = user_rank($user);

if ($route === 'profile') {
    route_profile($user, $rank);
    exit;
}
if (!$rank) {
    layout('No access', page_message('No access yet',
        'You are logged in as <b>' . e($user['username']) . '</b>, but you don\'t have a guild rank role on our Discord server. '
        . 'Ask an officer to give you your rank role, then <a href="' . e(url('login')) . '">log in again</a>.'), $user, null);
    exit;
}

switch ($route) {
    case '':
    case 'bank':
        route_bank($user, $rank);
        break;
    case 'history':
        route_history($user, $rank);
        break;
    case 'requests':
        route_requests($user, $rank);
        break;
    default:
        http_response_code(404);
        layout('Not found', page_message('Not found', 'That page doesn\'t exist. <a href="index.php">Back to the bank</a>.'), $user, $rank);
}
exit;

// ---------------------------------------------------------------------------

function route_profile(array $user, ?array $rank): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = (string)($_POST['action'] ?? 'name');
        if ($action === 'kit') {
            if (!can_sync($rank)) {
                flash('Your rank cannot sync the bank.', 'warn');
                redirect(url('profile'));
            }
            send_sync_kit($user, create_sync_key($user));
            exit;
        }
        if ($action === 'revoke') {
            $keyId = (int)($_POST['key_id'] ?? 0);
            $own = q('SELECT id FROM sync_keys WHERE id = ? AND user_id = ?', [$keyId, $user['id']])->fetch();
            if ($own || can($rank, 'can_approve')) {
                revoke_sync_key($keyId);
                flash('Sync key turned off. That uploader can no longer send data.');
            }
            redirect(url('profile'));
        }
        $name = trim((string)($_POST['character_name'] ?? ''));
        if ($name !== '' && !preg_match('/^[\p{L}]{2,12}$/u', $name)) {
            flash('Character names are 2–12 letters, no spaces or numbers.', 'warn');
        } else {
            $name = $name === '' ? null : mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
            q('UPDATE users SET character_name = ? WHERE id = ?', [$name, $user['id']]);
            flash('Saved.');
        }
        redirect(url('profile'));
    }
    $tabs = $rank ? rank_tabs($rank) : [];
    $tabNames = tab_names();
    $tabList = $tabs ? implode(', ', array_map(fn($i) => e($tabNames[$i] ?? "Tab $i"), $tabs)) : 'none';
    $perms = [];
    if ($rank) {
        $perms[] = can($rank, 'can_request') ? 'request withdrawals' : null;
        $perms[] = can($rank, 'can_approve') ? 'approve requests' : null;
        $perms[] = can($rank, 'money_log') ? 'see the gold log' : null;
    }
    $perms = array_filter($perms);
    $body = '<h1>Profile</h1><div class="card narrow">'
        . '<form method="post" class="stack">' . csrf_field()
        . '<label for="cn">Main character name</label>'
        . '<input id="cn" name="character_name" maxlength="12" autocomplete="off" value="' . e($user['character_name']) . '" placeholder="e.g. Thrallson">'
        . '<p class="fine">Used for your requests. The site marks a request done automatically when this character withdraws the item.</p>'
        . '<button class="btn">Save</button></form></div>'
        . '<div class="card narrow"><dl class="facts">'
        . '<dt>Discord</dt><dd>' . e($user['username']) . '</dd>'
        . '<dt>Rank</dt><dd>' . e($rank['name'] ?? 'None') . '</dd>'
        . '<dt>Tabs you can see</dt><dd>' . $tabList . '</dd>'
        . '<dt>You can</dt><dd>' . ($perms ? e(implode(', ', $perms)) : 'view only') . '</dd>'
        . '</dl><p class="fine">Ranks come from your Discord roles and refresh every few minutes.</p>'
        . '<form method="post" action="' . e(url('logout')) . '">' . csrf_field() . '<button class="btn ghost">Log out</button></form></div>';
    $body .= sync_section($user, $rank);
    layout('Profile', $body, $user, $rank, 'profile');
}

function sync_section(array $user, ?array $rank): string
{
    if (!can_sync($rank)) {
        return '';
    }
    $mine = active_sync_key((int)$user['id']);
    $h = '<h2 class="section">Sync the bank</h2><div class="card narrow">'
        . '<p>The sync kit has the in-game addon and the uploader, with your own personal key already filled in. '
        . 'Open the guild vault in game, then reload, and the bank updates here.</p>';
    if ($mine) {
        $h .= '<p class="fine">Your key (ending in <code>' . e($mine['key_hint']) . '</code>) was created ' . when((int)$mine['created_at'])
            . ($mine['last_used_at'] ? ', last used ' . when((int)$mine['last_used_at']) : ', not used yet') . '.</p>';
    }
    $h .= '<form method="post" class="row-actions">' . csrf_field() . '<input type="hidden" name="action" value="kit">'
        . '<button class="btn">' . ($mine ? 'Download a new sync kit' : 'Download my sync kit') . '</button></form>';
    if ($mine) {
        $h .= '<p class="fine">A new download replaces your old key, so the previous kit stops working. Install the new one over it.</p>'
            . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="revoke">'
            . '<input type="hidden" name="key_id" value="' . (int)$mine['id'] . '">'
            . '<button class="btn ghost small">Turn off my key</button></form>';
    }
    $h .= '<p class="fine">Keep the kit to yourself: anyone with it can upload bank data as you.</p></div>';

    if (can($rank, 'can_approve')) {
        $rows = q('SELECT k.*, u.username, u.character_name FROM sync_keys k JOIN users u ON u.id = k.user_id
                   WHERE k.revoked_at IS NULL ORDER BY k.last_used_at DESC, k.created_at DESC')->fetchAll();
        $h .= '<h2 class="section">Active sync keys</h2><div class="card narrow">';
        if (!$rows) {
            $h .= '<p class="fine">Nobody has downloaded a sync kit from the site yet.</p>';
        } else {
            $h .= '<ul class="keys">';
            foreach ($rows as $k) {
                $h .= '<li><div><b>' . e($k['character_name'] ?: $k['username']) . '</b> <small>…' . e($k['key_hint']) . '</small>'
                    . '<span class="meta">created ' . when((int)$k['created_at'])
                    . ($k['last_used_at'] ? ' · last sync ' . when((int)$k['last_used_at']) : ' · never used') . '</span></div>'
                    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="revoke">'
                    . '<input type="hidden" name="key_id" value="' . (int)$k['id'] . '">'
                    . '<button class="btn danger small">Turn off</button></form></li>';
            }
            $h .= '</ul>';
        }
        if (cfg('upload_keys')) {
            $h .= '<p class="fine">Keys written in config.php also still work. Remove them there to turn them off.</p>';
        }
        $h .= '</div>';
    }
    return $h;
}

function tab_names(): array
{
    $names = [];
    foreach (q('SELECT tab_index, name FROM bank_tabs ORDER BY tab_index')->fetchAll() as $t) {
        $names[(int)$t['tab_index']] = $t['name'];
    }
    return $names;
}

function in_list(array $ints): string
{
    return implode(',', array_map('intval', $ints ?: [-1]));
}

function route_bank(array $user, array $rank): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $err = create_request($user, $rank, (int)($_POST['item_id'] ?? 0), (int)($_POST['quantity'] ?? 0), (string)($_POST['note'] ?? ''));
        if ($err) {
            flash($err, 'warn');
        } else {
            flash('Request sent to the officers.');
        }
        redirect(url('', array_filter(['q' => $_POST['q'] ?? '', 'view' => $_POST['view'] ?? ''])));
    }

    $tabs = rank_tabs($rank);
    $tabNames = tab_names();
    $search = trim((string)($_GET['q'] ?? ''));
    $view = ($_GET['view'] ?? '') === 'tabs' ? 'tabs' : 'items';
    $onlyTab = isset($_GET['tab']) ? (int)$_GET['tab'] : 0;
    $showTabs = $onlyTab && in_array($onlyTab, $tabs, true) ? [$onlyTab] : $tabs;

    $where = 'tab_index IN (' . in_list($showTabs) . ')';
    $params = [];
    if ($search !== '') {
        $where .= ' AND LOWER(name) LIKE ?';
        $params[] = '%' . mb_strtolower($search) . '%';
    }

    $money = setting('money');
    $updated = (int)setting('money_updated_at', 0);
    $tabUpdates = $tabs ? q('SELECT MAX(updated_at) FROM bank_tabs WHERE tab_index IN (' . in_list($tabs) . ')')->fetchColumn() : null;
    $lastSeen = max($updated, (int)$tabUpdates);
    $stats = q("SELECT COUNT(DISTINCT item_id) AS kinds, COALESCE(SUM(count),0) AS total FROM bank_items WHERE tab_index IN (" . in_list($tabs) . ")")->fetch();

    $h = '<section class="summary">'
        . '<div class="stat"><span class="label">Gold</span><span class="value">' . ($money !== null ? money_html($money) : '—') . '</span></div>'
        . '<div class="stat"><span class="label">Items</span><span class="value">' . number_format((int)$stats['total']) . ' <small>(' . (int)$stats['kinds'] . ' kinds)</small></span></div>'
        . '<div class="stat"><span class="label">Last synced</span><span class="value small">' . ($lastSeen ? when($lastSeen) . (setting('last_upload_by') ? ' <small>by ' . e(setting('last_upload_by')) . '</small>' : '') : 'never') . '</span></div>'
        . '</section>';

    if (!$tabNames) {
        $h .= page_message('The bank is empty here so far', 'Once an officer with the addon and uploader opens the guild vault and types /reload, the contents show up here.');
        layout('Bank', $h, $user, $rank, 'bank');
        return;
    }

    // Toolbar
    $h .= '<form class="toolbar" method="get" role="search">'
        . '<input type="search" name="q" value="' . e($search) . '" placeholder="Search items…" aria-label="Search items">'
        . '<select name="tab" aria-label="Tab"><option value="">All my tabs</option>';
    foreach ($tabs as $t) {
        $h .= '<option value="' . $t . '"' . ($t === $onlyTab ? ' selected' : '') . '>' . e($tabNames[$t] ?? "Tab $t") . '</option>';
    }
    $h .= '</select><input type="hidden" name="view" value="' . e($view) . '"><button class="btn">Search</button></form>';

    $base = array_filter(['q' => $search, 'tab' => $onlyTab ?: null]);
    $h .= '<div class="seg" role="tablist">'
        . '<a role="tab" href="' . e(url('', $base)) . '"' . ($view === 'items' ? ' aria-selected="true" class="on"' : '') . '>By item</a>'
        . '<a role="tab" href="' . e(url('', $base + ['view' => 'tabs'])) . '"' . ($view === 'tabs' ? ' aria-selected="true" class="on"' : '') . '>By tab</a>'
        . '</div>';

    $canRequest = can($rank, 'can_request');
    $hidden = '<input type="hidden" name="q" value="' . e($search) . '"><input type="hidden" name="view" value="' . e($view) . '">';

    if ($view === 'items') {
        $rows = q("SELECT item_id, MAX(name) AS name, MAX(quality) AS quality, SUM(count) AS total,
                   GROUP_CONCAT(DISTINCT tab_index) AS tabs
                   FROM bank_items WHERE $where GROUP BY item_id ORDER BY MAX(quality) DESC, MAX(name) ASC", $params)->fetchAll();
        if (!$rows) {
            $h .= '<p class="empty">Nothing matches' . ($search !== '' ? ' “' . e($search) . '”' : '') . '.</p>';
        }
        $h .= '<ul class="items">';
        foreach ($rows as $r) {
            $inTabs = array_map(fn($i) => e($tabNames[(int)$i] ?? "Tab $i"), explode(',', (string)$r['tabs']));
            $h .= '<li class="it">'
                . '<div class="it-main">' . item_html($r['name'], $r['quality'], $r['item_id'])
                . '<span class="count">×' . number_format((int)$r['total']) . '</span></div>'
                . '<div class="it-sub">' . implode(' · ', $inTabs) . '</div>';
            if ($canRequest) {
                $h .= request_form((int)$r['item_id'], (int)$r['total'], $hidden);
            }
            $h .= '</li>';
        }
        $h .= '</ul>';
    } else {
        $items = q("SELECT * FROM bank_items WHERE $where ORDER BY tab_index, slot", $params)->fetchAll();
        $byTab = [];
        foreach ($items as $it) {
            $byTab[(int)$it['tab_index']][] = $it;
        }
        $updatedAt = [];
        foreach (q('SELECT tab_index, updated_at FROM bank_tabs')->fetchAll() as $t) {
            $updatedAt[(int)$t['tab_index']] = (int)$t['updated_at'];
        }
        foreach ($showTabs as $t) {
            if (!isset($tabNames[$t])) {
                continue;
            }
            $list = $byTab[$t] ?? [];
            if ($search !== '' && !$list) {
                continue;
            }
            $h .= '<section class="tab"><h2>' . e($tabNames[$t]) . ' <small>' . count($list) . ' / 98 slots · ' . when($updatedAt[$t] ?? 0) . '</small></h2>';
            $h .= $list ? '<ul class="items">' : '<p class="empty">Empty.</p>';
            foreach ($list as $it) {
                $h .= '<li class="it"><div class="it-main">' . item_html($it['name'], $it['quality'], $it['item_id'])
                    . '<span class="count">×' . (int)$it['count'] . '</span></div>';
                if ($canRequest) {
                    $avail = available_quantity((int)$it['item_id'], $tabs)['count'];
                    $h .= request_form((int)$it['item_id'], $avail, $hidden);
                }
                $h .= '</li>';
            }
            $h .= $list ? '</ul></section>' : '</section>';
        }
    }
    layout('Bank', $h, $user, $rank, 'bank');
}

function request_form(int $itemId, int $max, string $hidden): string
{
    $max = max(1, $max);
    return '<details class="req"><summary>Request</summary>'
        . '<form method="post" class="req-form">' . csrf_field() . $hidden
        . '<input type="hidden" name="item_id" value="' . $itemId . '">'
        . '<label>Amount <input type="number" name="quantity" min="1" max="' . $max . '" value="1" required></label>'
        . '<label class="grow">Note <input name="note" maxlength="300" placeholder="What is it for? (optional)"></label>'
        . '<button class="btn small">Send request</button></form></details>';
}

function route_history(array $user, array $rank): void
{
    $tabs = rank_tabs($rank);
    $tabNames = tab_names();
    $allowed = $tabs;
    if (can($rank, 'money_log')) {
        $allowed[] = MONEY_TAB;
    }

    $tabFilter = isset($_GET['tab']) && $_GET['tab'] !== '' ? (int)$_GET['tab'] : null;
    $player = trim((string)($_GET['player'] ?? ''));
    $search = trim((string)($_GET['q'] ?? ''));
    $type = (string)($_GET['type'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 50;

    $showTabs = $tabFilter !== null && in_array($tabFilter, $allowed, true) ? [$tabFilter] : $allowed;
    $where = ['tab_index IN (' . in_list($showTabs) . ')'];
    $params = [];
    if ($player !== '') {
        $where[] = 'LOWER(player) LIKE ?';
        $params[] = '%' . mb_strtolower($player) . '%';
    }
    if ($search !== '') {
        $where[] = 'LOWER(item_name) LIKE ?';
        $params[] = '%' . mb_strtolower($search) . '%';
    }
    if (in_array($type, ['deposit', 'withdraw', 'move', 'repair'], true)) {
        $where[] = 'type = ?';
        $params[] = $type;
    }
    $whereSql = implode(' AND ', $where);
    $total = (int)q("SELECT COUNT(*) FROM transactions WHERE $whereSql", $params)->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $rows = q("SELECT * FROM transactions WHERE $whereSql ORDER BY approx_time DESC, id DESC LIMIT $perPage OFFSET $offset", $params)->fetchAll();

    $h = '<h1>History</h1><form class="toolbar" method="get"><input type="hidden" name="route" value="history">'
        . '<input name="player" value="' . e($player) . '" placeholder="Player" aria-label="Player">'
        . '<input type="search" name="q" value="' . e($search) . '" placeholder="Item" aria-label="Item">'
        . '<select name="tab" aria-label="Tab"><option value="">All</option>';
    foreach ($allowed as $t) {
        $label = $t === MONEY_TAB ? 'Gold' : ($tabNames[$t] ?? "Tab $t");
        $h .= '<option value="' . $t . '"' . ($t === $tabFilter ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $h .= '</select><select name="type" aria-label="Type"><option value="">Any action</option>';
    foreach (['deposit' => 'Deposits', 'withdraw' => 'Withdrawals', 'move' => 'Moves', 'repair' => 'Repairs'] as $k => $v) {
        $h .= '<option value="' . $k . '"' . ($type === $k ? ' selected' : '') . '>' . $v . '</option>';
    }
    $h .= '</select><button class="btn">Filter</button></form>';

    if (!$rows) {
        $h .= '<p class="empty">No bank activity recorded yet' . ($player || $search || $type ? ' for these filters' : '') . '.</p>';
    } else {
        $h .= '<ol class="log">';
        foreach ($rows as $t) {
            $where = (int)$t['tab_index'] === MONEY_TAB ? 'Gold' : ($tabNames[(int)$t['tab_index']] ?? 'Tab ' . (int)$t['tab_index']);
            $h .= '<li class="' . e($t['type']) . '"><span class="who-n">' . e($t['player']) . '</span> ' . tx_description($t, $tabNames)
                . '<span class="meta">' . e($where) . ' · ~' . when((int)$t['approx_time']) . '</span></li>';
        }
        $h .= '</ol>';
        $pages = (int)ceil($total / $perPage);
        if ($pages > 1) {
            $qs = array_filter(['player' => $player, 'q' => $search, 'tab' => $tabFilter, 'type' => $type], fn($v) => $v !== '' && $v !== null);
            $h .= '<nav class="pager">';
            if ($page > 1) {
                $h .= '<a class="btn ghost small" href="' . e(url('history', $qs + ['page' => $page - 1])) . '">Newer</a>';
            }
            $h .= '<span>Page ' . $page . ' of ' . $pages . '</span>';
            if ($page < $pages) {
                $h .= '<a class="btn ghost small" href="' . e(url('history', $qs + ['page' => $page + 1])) . '">Older</a>';
            }
            $h .= '</nav>';
        }
    }
    $h .= '<p class="fine">Times come from the in-game bank log, which is only accurate to about an hour. The game keeps the last 25 entries per tab, so activity that happens between syncs can be missed if a tab is very busy.</p>';
    layout('History', $h, $user, $rank, 'history');
}

function route_requests(array $user, array $rank): void
{
    $isOfficer = can($rank, 'can_approve');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $err = act_on_request($user, $rank, (int)($_POST['id'] ?? 0), (string)($_POST['action'] ?? ''), (string)($_POST['note'] ?? ''));
        flash($err ?? 'Updated.', $err ? 'warn' : 'ok');
        redirect(url('requests', array_filter(['view' => $_POST['view'] ?? ''])));
    }

    $view = (string)($_GET['view'] ?? ($isOfficer ? 'officer' : 'mine'));
    if (!$isOfficer || !in_array($view, ['officer', 'all', 'mine'], true)) {
        $view = 'mine';
    }

    $h = '<h1>Withdrawal requests</h1>';
    if ($isOfficer) {
        $h .= '<div class="seg" role="tablist">';
        foreach (['officer' => 'To handle', 'all' => 'All', 'mine' => 'Mine'] as $k => $label) {
            $h .= '<a role="tab" href="' . e(url('requests', ['view' => $k])) . '"' . ($view === $k ? ' aria-selected="true" class="on"' : '') . '>' . $label . '</a>';
        }
        $h .= '</div>';
    }

    if ($view === 'officer') {
        $rows = q("SELECT r.*, u.username FROM requests r JOIN users u ON u.id = r.user_id
                   WHERE r.status IN ('pending','approved') ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END, r.created_at ASC")->fetchAll();
    } elseif ($view === 'all') {
        $rows = q('SELECT r.*, u.username FROM requests r JOIN users u ON u.id = r.user_id ORDER BY r.created_at DESC LIMIT 200')->fetchAll();
    } else {
        $rows = q('SELECT r.*, u.username FROM requests r JOIN users u ON u.id = r.user_id WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 100', [$user['id']])->fetchAll();
    }

    if (!$rows) {
        $h .= '<p class="empty">' . ($view === 'officer' ? 'Nothing waiting. Nice.' : 'No requests yet. Find an item on the Bank page and tap Request.') . '</p>';
    }
    $deciders = [];
    foreach (q('SELECT id, username, character_name FROM users')->fetchAll() as $u) {
        $deciders[(int)$u['id']] = $u['character_name'] ?: $u['username'];
    }
    $tabs = rank_tabs($rank);
    $h .= '<ul class="reqs">';
    foreach ($rows as $r) {
        $avail = available_quantity((int)$r['item_id'], $tabs)['count'];
        $h .= '<li class="card rq">'
            . '<div class="rq-head">' . status_pill($r['status']) . '<span class="rq-id">#' . (int)$r['id'] . '</span>' . when((int)$r['created_at']) . '</div>'
            . '<div class="rq-body"><b>' . e($r['character_name']) . '</b> wants ' . (int)$r['quantity'] . '× ' . item_html($r['item_name'], $r['quality'], $r['item_id']);
        if ((int)$r['fulfilled_qty'] > 0 && $r['status'] !== 'fulfilled') {
            $h .= ' <small>(' . (int)$r['fulfilled_qty'] . ' taken so far)</small>';
        }
        $h .= '</div>';
        if ($r['note']) {
            $h .= '<blockquote>' . e($r['note']) . '</blockquote>';
        }
        if ($r['decided_by'] && in_array($r['status'], ['approved', 'denied', 'fulfilled'], true)) {
            $h .= '<p class="fine">' . e(ucfirst($r['status'] === 'fulfilled' ? 'handled' : $r['status'])) . ' by ' . e($deciders[(int)$r['decided_by']] ?? 'an officer')
                . ($r['decision_note'] ? ': “' . e($r['decision_note']) . '”' : '') . '</p>';
        }
        $open = in_array($r['status'], ['pending', 'approved'], true);
        if ($open && $isOfficer && $view !== 'mine') {
            if ($r['status'] === 'pending' && $avail < (int)$r['quantity']) {
                $h .= '<p class="fine warn">Only ' . $avail . ' left in the bank.</p>';
            }
            $h .= '<form method="post" class="rq-actions">' . csrf_field()
                . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="view" value="' . e($view) . '">'
                . '<input name="note" maxlength="300" placeholder="Note to member (optional)" aria-label="Note">';
            if ($r['status'] === 'pending') {
                $h .= '<button class="btn small" name="action" value="approve">Approve</button>';
            }
            $h .= '<button class="btn small ghost" name="action" value="fulfil" title="Use when you mailed it or handed it over outside the bank">Mark done</button>'
                . '<button class="btn small danger" name="action" value="deny">Deny</button></form>';
            if ($r['status'] === 'approved') {
                $h .= '<p class="fine">Closes automatically when ' . e($r['character_name']) . ' takes it from the bank and the next sync comes in.</p>';
            }
        } elseif ($open && (int)$r['user_id'] === (int)$user['id']) {
            $h .= '<form method="post" class="rq-actions">' . csrf_field()
                . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="view" value="' . e($view) . '">'
                . '<button class="btn small ghost" name="action" value="cancel">Cancel request</button></form>';
            if ($r['status'] === 'approved') {
                $h .= '<p class="fine">Approved. Grab it from the guild bank with ' . e($r['character_name']) . ' and it will close itself.</p>';
            }
        }
        $h .= '</li>';
    }
    $h .= '</ul>';
    layout('Requests', $h, $user, $rank, 'requests');
}
