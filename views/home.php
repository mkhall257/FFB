<?php
/**
 * Authenticated landing page (broadcast chrome). Expects:
 *
 * @var string $displayName
 * @var string $role
 * @var int $incomingOffers
 */
?>
<section class="home-hero">
    <div class="home-hero__glass">
        <div class="eyebrow">Signed in as <?= e($role) ?></div>
        <h1>Welcome, <?= e($displayName) ?>.<br><span>Dominate the league.</span></h1>
        <p>Your season command center — draft, set your Lineup, track Matchups and chase the championship.</p>
        <div class="home-hero__cta">
            <a class="btn btn--primary" href="/scoreboard">This week's Matchup</a>
            <a class="btn btn--ghost" href="/lineup">My Lineup</a>
        </div>
    </div>
</section>

<div class="home-tiles">
    <a class="panel home-tile home-tile--matchups" href="/scoreboard">
        <div class="eyebrow">This week</div>
        <h2>Matchups</h2>
    </a>
    <a class="panel home-tile home-tile--standings" href="/standings">
        <div class="eyebrow">League</div>
        <h2>Standings</h2>
    </a>
    <a class="panel home-tile home-tile--playoffs" href="/playoffs">
        <div class="eyebrow">Postseason</div>
        <h2>Playoffs</h2>
    </a>
</div>

<section class="panel panel--pad home-actions">
    <a class="btn" href="/players">Free Agents</a>
    <a class="btn" href="/trades">Trades<?= $incomingOffers > 0 ? ' (' . (int) $incomingOffers . ')' : '' ?></a>
    <a class="btn" href="/transactions">Activity</a>
<?php if ($role === 'commissioner'): ?>
    <a class="btn" href="/admin">Commissioner tools</a>
<?php endif; ?>
    <form method="post" action="/logout" style="margin-left:auto">
        <button type="submit" class="btn btn--ghost">Log out</button>
    </form>
</section>
