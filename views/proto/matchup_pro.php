<?php
/**
 * PROTOTYPE — "broadcast" style Matchup. Self-contained page, sample data only.
 * Not wired to real scoring; used to agree the look/feel. Original dragon identity.
 */
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $s = '';
    foreach ($parts as $p) {
        $p = ltrim($p, '.');
        if ($p !== '') { $s .= strtoupper($p[0]); }
        if (strlen($s) >= 2) { break; }
    }
    return $s === '' ? '?' : $s;
};
$pill = ['active' => ['ACTIVE', 'pill-active'], 'quest' => ['QUEST', 'pill-quest'], 'out' => ['OUT', 'pill-out']];

$home = ['name' => 'DRAGON', 'accent' => 'SLAYERS', 'rec' => '7-0', 'mgr' => 'LIAM B.', 'proj' => '142.6', 'live' => 98.4, 'left' => 6, 'crest' => '/assets/img/crest-shadow.png'];
$away = ['name' => 'GRIDIRON', 'accent' => 'KINGS', 'rec' => '5-2', 'mgr' => 'NOAH K.', 'proj' => '118.3', 'live' => 76.2, 'left' => 3, 'crest' => '/assets/img/crest-roaring.png'];
$share = (int) round($home['live'] / ($home['live'] + $away['live']) * 100);

$rows = [
    ['QB',  ['J. Allen','BUF','@ KC · Q3 6:12','24.8','active'],       ['P. Mahomes','KC','vs BUF · Q3 6:12','18.6','active']],
    ['RB',  ['J. Cook','BUF','@ KC · Q3 6:12','19.6','active'],        ['I. Pacheco','KC','vs BUF · Q3 6:12','9.4','quest']],
    ['RB',  ['J. Mixon','HOU','@ JAX · Q2 1:40','14.3','active'],      ['K. Williams','ARI','vs SEA · Q2 8:05','6.7','active']],
    ['WR',  ['A. St. Brown','DET','vs GB · Q3 2:12','12.1','active'],  ['J. Jefferson','MIN','@ CHI · Q3 9:44','15.2','active']],
    ['WR',  ['G. Wilson','NYJ','@ NE · Q4 5:01','8.7','quest'],        ['C. Lamb','DAL','vs PHI · Q4 3:30','11.8','quest']],
    ['TE',  ['T. Kelce','KC','vs BUF · Q3 6:12','6.4','active'],       ['D. Kincaid','BUF','@ KC · Q3 6:12','5.6','active']],
    ['FLEX',['D. Achane','MIA','@ NYJ · Q4 5:01','12.5','active'],     ['R. Rice','KC','vs BUF · Q3 6:12','7.1','out']],
    ['D/ST',['Cowboys','DAL','vs PHI · Q4 3:30','8.0','active'],       ['49ers','SF','@ SEA · Q2 8:05','4.6','active']],
    ['K',   ['H. Butker','KC','vs BUF · Q3 6:12','2.0','active'],      ['J. Bass','BUF','@ KC · Q3 6:12','1.8','active']],
];

$lock = '<span class="pro-lock"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Matchup · FFB (prototype)</title>
<link rel="icon" type="image/png" href="/assets/img/favicon-dragon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/typography.css">
<link rel="stylesheet" href="/assets/css/proto/matchup-pro.css">
</head>
<body class="pro">
<div class="pro-wrap">

    <div class="pro-topbar">
        <button class="pro-icnbtn" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
        <div class="pro-wordmark"><b>DRAGON</b><span>FANTASY LEAGUE</span></div>
        <button class="pro-icnbtn pro-chatdot" aria-label="Chat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></button>
    </div>

    <div class="pro-week">
        <div><b>WEEK 7</b><span>HEAD-TO-HEAD</span></div>
    </div>

    <div class="pro-head">
        <div class="pro-team">
            <img class="pro-crest" src="<?= $home['crest'] ?>" alt="">
            <h2><?= e($home['name']) ?><br><span class="pro-accent"><?= e($home['accent']) ?></span></h2>
            <div class="pro-rec"><b><?= e($home['rec']) ?></b> · <?= e($home['mgr']) ?></div>
            <div class="pro-proj"><span>PROJECTED</span><b><?= e($home['proj']) ?></b></div>
        </div>

        <div class="pro-vs">
            <svg viewBox="0 0 60 100"><defs><linearGradient id="bolt" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#30db73"/><stop offset="1" stop-color="#0b8f46"/></linearGradient></defs><path d="M34 2 L8 56 L27 56 L20 98 L54 38 L33 38 Z" fill="url(#bolt)" opacity=".55"/></svg>
            <b>VS</b>
        </div>

        <div class="pro-team">
            <img class="pro-crest" src="<?= $away['crest'] ?>" alt="">
            <h2><?= e($away['name']) ?><br><span class="pro-accent"><?= e($away['accent']) ?></span></h2>
            <div class="pro-rec"><b><?= e($away['rec']) ?></b> · <?= e($away['mgr']) ?></div>
            <div class="pro-proj"><span>PROJECTED</span><b><?= e($away['proj']) ?></b></div>
        </div>
    </div>

    <div class="pro-live">
        <div class="pro-live__score"><b><?= $home['live'] ?></b><span>LIVE</span></div>
        <div class="pro-barwrap">
            <div class="pro-bar"><i class="l" style="width:<?= $share ?>%"></i><i class="r" style="width:<?= 100 - $share ?>%"></i></div>
            <img class="pro-medal l" src="<?= $home['crest'] ?>" alt="">
            <img class="pro-medal r" src="<?= $away['crest'] ?>" alt="">
            <div class="pro-remain"><span><?= $home['left'] ?> PLAYERS REMAINING</span><span><?= $away['left'] ?> PLAYERS REMAINING</span></div>
        </div>
        <div class="pro-live__score"><b><?= $away['live'] ?></b><span>LIVE</span></div>
    </div>

    <div class="pro-list">
    <?php foreach ($rows as [$pos, $h, $a]): ?>
        <?php [$hLabel, $hCls] = $pill[$h[4]]; [$aLabel, $aCls] = $pill[$a[4]]; ?>
        <div class="pro-row">
            <div class="pro-av"><?= e($initials($h[0])) ?><span class="pro-pill <?= $hCls ?>"><?= $hLabel ?></span></div>
            <div class="pro-meta">
                <div class="nm"><?= e($h[0]) ?></div>
                <div class="sub"><?= e($h[1]) ?></div>
                <div class="gm"><?= e($h[2]) ?></div>
            </div>
            <div class="pro-pts"><?= e($h[3]) ?></div>
            <div class="pro-pos"><?= e($pos) ?></div>
            <div class="pro-pts"><?= e($a[3]) ?></div>
            <div class="pro-meta" style="text-align:right">
                <div class="nm"><?= e($a[0]) ?></div>
                <div class="sub"><?= e($a[1]) ?></div>
                <div class="gm"><?= e($a[2]) ?></div>
            </div>
            <div class="pro-av"><?= e($initials($a[0])) ?><span class="pro-pill <?= $aCls ?>"><?= $aLabel ?></span></div>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="pro-footer">
        <div class="pro-monogram">DF</div>
        <span>DRAGON FANTASY LEAGUE</span>
    </div>
</div>

<nav class="pro-tabbar">
    <a class="pro-tab" href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l9-9 9 9M5 10v10h14V10"/></svg>DASHBOARD</a>
    <a class="pro-tab" href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3"/><path d="M2 21v-1a5 5 0 0 1 10 0v1M16 6a3 3 0 0 1 0 6M15 21v-1a5 5 0 0 1 6-5"/></svg>MY TEAM</a>
    <a class="pro-tab active" href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 4l5 16M20 4l-5 16"/></svg>MATCHUP</a>
    <a class="pro-tab" href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 21V9M18 21V5M6 5V3M18 3v0M4 21h16"/><path d="M8 3h8v6H8z"/></svg>LEAGUE</a>
    <a class="pro-tab" href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/></svg>SCORES</a>
</nav>
</body>
</html>
