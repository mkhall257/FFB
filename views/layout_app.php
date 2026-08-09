<?php
/**
 * Broadcast app chrome (layout_app). Full-bleed atmosphere, top logo bar,
 * a slide-in navigation drawer, desktop secondary nav, and a mobile-primary
 * bottom tab bar. Expects:
 *
 * @var string $title    Page title (before the " · FFB" suffix).
 * @var string $content  Already-rendered inner HTML.
 * @var string $active   Nav key to highlight ('' for none).
 * @var string $pageCss  Page stylesheet basename under /assets/css/pages ('' for none).
 * @var ?string $navRole Signed-in user's role ('commissioner'/'manager'), or null if anonymous.
 * @var ?string $navName Signed-in user's display name, or null.
 */
$active = $active ?? '';
$pageCss = $pageCss ?? '';
$navRole = $navRole ?? null;
$navName = $navName ?? null;
$isAuthed = $navRole !== null;
$isCommissioner = $navRole === 'commissioner';
// Pages with their own stylesheet own their markup; plain pages get the shared
// broadcast document styling (headings, tables, forms) via the .doc wrapper.
$docWrap = $pageCss === '';

/** Desktop secondary nav: label => [href, nav-key]. */
$nav = [
    'Home' => ['/', 'home'],
    'Matchup' => ['/scoreboard', 'matchup'],
    'Standings' => ['/standings', 'standings'],
    'My Team' => ['/lineup', 'lineup'],
    'Playoffs' => ['/playoffs', 'playoffs'],
];

/** Full navigation drawer (everyone): label => [href, nav-key]. */
$menu = [
    'Home' => ['/', 'home'],
    'Matchup' => ['/scoreboard', 'matchup'],
    'My Team' => ['/lineup', 'lineup'],
    'Standings' => ['/standings', 'standings'],
    'Playoffs' => ['/playoffs', 'playoffs'],
    'Free Agents' => ['/players', 'players'],
    'Trades' => ['/trades', 'trades'],
    'Activity' => ['/transactions', 'transactions'],
    'Draft Room' => ['/draft', 'draft'],
    'My Profile' => ['/profile', 'profile'],
];

/** Commissioner-only drawer section. */
$commishMenu = [
    'Commissioner Tools' => ['/admin', 'admin'],
    'Season Control' => ['/admin/season', 'season'],
    'Draft Setup' => ['/admin/draft', 'draft-setup'],
    'Roster Edit' => ['/admin/roster-edit', 'roster'],
    'Unmatched Players' => ['/admin/unmatched-players', 'unmatched'],
];

/** Bottom tab bar: label => [href, nav-key, inner-svg]. */
$tabs = [
    'Home' => ['/', 'home', '<path d="M3 12l9-9 9 9M5 10v10h14V10"/>'],
    'My Team' => ['/lineup', 'lineup', '<circle cx="9" cy="8" r="3"/><path d="M2 21v-1a5 5 0 0 1 10 0v1M16 6a3 3 0 0 1 0 6M15 21v-1a5 5 0 0 1 6-5"/>'],
    'Matchup' => ['/scoreboard', 'matchup', '<path d="M4 4l5 16M20 4l-5 16"/>'],
    'Standings' => ['/standings', 'standings', '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/>'],
    'Playoffs' => ['/playoffs', 'playoffs', '<path d="M8 4h8v3a4 4 0 0 1-8 0zM6 4H4v1.5a3 3 0 0 0 3 3M18 4h2v1.5a3 3 0 0 0-3 3M9.5 13h5l-1 5h-3z"/>'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · FFB</title>
    <link rel="icon" type="image/png" href="/assets/img/favicon-dragon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/typography.css">
    <link rel="stylesheet" href="/assets/css/app.css">
<?php if ($pageCss !== ''): ?>
    <link rel="stylesheet" href="/assets/css/pages/<?= e($pageCss) ?>.css">
<?php endif; ?>
</head>
<body class="app">
    <div class="wrap">
        <div class="topbar">
            <button class="icnbtn" id="menuBtn" aria-label="Open menu" aria-controls="drawer" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
            <a class="wordmark" href="/"><img src="/assets/img/logo-peak-dragon.png" alt="Peak Dragon Fantasy Football League"></a>
            <span class="topbar__spacer" aria-hidden="true"></span>
        </div>

        <nav class="topnav">
<?php foreach ($nav as $label => [$href, $key]): ?>
            <a href="<?= e($href) ?>"<?= $key === $active ? ' class="active"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
        </nav>

<?php if ($docWrap): ?><div class="doc"><?= $content ?></div><?php else: ?><?= $content ?><?php endif; ?>

        <div class="site-footer">
            <div class="monogram">PD</div>
            <span>Peak Dragon Fantasy Football League</span>
        </div>
    </div>

    <nav class="tabbar">
<?php foreach ($tabs as $label => [$href, $key, $icon]): ?>
        <a class="tab<?= $key === $active ? ' active' : '' ?>" href="<?= e($href) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $icon ?></svg><?= e(strtoupper($label)) ?></a>
<?php endforeach; ?>
    </nav>

    <div class="drawer" id="drawer" hidden>
        <div class="drawer__scrim" data-close></div>
        <aside class="drawer__panel" role="dialog" aria-label="Navigation" aria-modal="true">
            <div class="drawer__head">
                <span class="drawer__brand">Peak Dragon</span>
                <button class="icnbtn" data-close aria-label="Close menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </div>
<?php if ($isAuthed): ?>
            <?php if ($navName !== null): ?><div class="drawer__user">Signed in as <b><?= e($navName) ?></b></div><?php endif; ?>
            <nav class="drawer__nav">
<?php foreach ($menu as $label => [$href, $key]): ?>
                <a href="<?= e($href) ?>"<?= $key === $active ? ' class="active"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
            </nav>
<?php if ($isCommissioner): ?>
            <div class="drawer__section">Commissioner</div>
            <nav class="drawer__nav">
<?php foreach ($commishMenu as $label => [$href, $key]): ?>
                <a href="<?= e($href) ?>"<?= $key === $active ? ' class="active"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
            </nav>
<?php endif; ?>
            <form class="drawer__logout" method="post" action="/logout">
                <button type="submit" class="btn btn--ghost">Log out</button>
            </form>
<?php else: ?>
            <nav class="drawer__nav">
                <a href="/login">Log in</a>
            </nav>
<?php endif; ?>
        </aside>
    </div>

    <script>
    (function () {
        var btn = document.getElementById('menuBtn');
        var drawer = document.getElementById('drawer');
        if (!btn || !drawer) { return; }
        function open() {
            drawer.hidden = false;
            requestAnimationFrame(function () { drawer.classList.add('is-open'); });
            btn.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            drawer.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
            setTimeout(function () { drawer.hidden = true; }, 200);
        }
        btn.addEventListener('click', open);
        drawer.addEventListener('click', function (e) {
            if (e.target.closest('[data-close]')) { close(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !drawer.hidden) { close(); }
        });
        if (location.hash === '#menu') { open(); }
    })();
    </script>
</body>
</html>
