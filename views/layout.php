<?php
/**
 * Site layout / page chrome. Expects:
 *
 * @var string $title    Page title (before the " · FFB" suffix).
 * @var string $content  Already-rendered inner HTML.
 * @var string $active   Nav key to highlight ('' for none).
 * @var string $pageCss  Page stylesheet basename under /assets/css/pages ('' for none).
 */
$active = $active ?? '';
$pageCss = $pageCss ?? '';

/** Global navigation: label => [href, nav-key]. Role-agnostic for now. */
$nav = [
    'Home' => ['/', 'home'],
    'Matchup' => ['/scoreboard', 'matchup'],
    'Standings' => ['/standings', 'standings'],
    'Draft' => ['/draft', 'draft'],
    'My Team' => ['/lineup', 'lineup'],
    'Playoffs' => ['/playoffs', 'playoffs'],
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
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/layout.css">
<?php if ($pageCss !== ''): ?>
    <link rel="stylesheet" href="/assets/css/pages/<?= e($pageCss) ?>.css">
<?php endif; ?>
</head>
<body>
    <header class="top-nav">
        <div class="top-nav__inner">
            <a href="/" class="brand">DRAGON <span>FANTASY FOOTBALL</span></a>
            <nav class="nav-links">
<?php foreach ($nav as $label => [$href, $key]): ?>
                <a href="<?= e($href) ?>"<?= $key === $active ? ' class="active"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
            </nav>
        </div>
    </header>
    <main class="page">
        <div class="container">
<?= $content ?>
        </div>
    </main>
</body>
</html>
