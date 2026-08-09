<?php
/**
 * Broadcast app chrome (layout_app). Full-bleed atmosphere, top wordmark bar,
 * desktop secondary nav, and a mobile-primary bottom tab bar. Expects:
 *
 * @var string $title    Page title (before the " · FFB" suffix).
 * @var string $content  Already-rendered inner HTML.
 * @var string $active   Nav key to highlight ('' for none).
 * @var string $pageCss  Page stylesheet basename under /assets/css/pages ('' for none).
 */
$active = $active ?? '';
$pageCss = $pageCss ?? '';
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

/** Bottom tab bar: label => [href, nav-key, inner-svg]. Icons copied from the proto. */
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
            <a class="icnbtn" href="/" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg></a>
            <a class="wordmark" href="/"><b>DRAGON</b><span>FANTASY LEAGUE</span></a>
            <a class="icnbtn chatdot" href="/" aria-label="Chat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></a>
        </div>

        <nav class="topnav">
<?php foreach ($nav as $label => [$href, $key]): ?>
            <a href="<?= e($href) ?>"<?= $key === $active ? ' class="active"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
        </nav>

<?php if ($docWrap): ?><div class="doc"><?= $content ?></div><?php else: ?><?= $content ?><?php endif; ?>

        <div class="site-footer">
            <div class="monogram">DF</div>
            <span>DRAGON FANTASY LEAGUE</span>
        </div>
    </div>

    <nav class="tabbar">
<?php foreach ($tabs as $label => [$href, $key, $icon]): ?>
        <a class="tab<?= $key === $active ? ' active' : '' ?>" href="<?= e($href) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $icon ?></svg><?= e(strtoupper($label)) ?></a>
<?php endforeach; ?>
    </nav>
</body>
</html>
