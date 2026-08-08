<?php
/**
 * Authenticated landing page. Expects:
 *
 * @var string $displayName
 * @var string $role
 * @var int $incomingOffers
 */
?>
<h1>Welcome, <?= e($displayName) ?></h1>
<p>You are logged in as <?= e($role) ?>.</p>
<p><a href="/draft">Draft room</a></p>
<p><a href="/scoreboard">Scoreboard</a> · <a href="/standings">Standings</a> · <a href="/playoffs">Playoffs</a> · <a href="/lineup">My Lineup</a></p>
<p>
    <a href="/players">Free Agents</a> ·
    <a href="/trades">Trades<?= $incomingOffers > 0 ? ' (' . (int) $incomingOffers . ')' : '' ?></a> ·
    <a href="/transactions">Activity</a>
</p>
<?php if ($role === 'commissioner'): ?>
    <p><a href="/admin">Commissioner tools</a></p>
<?php endif; ?>
<form method="post" action="/logout">
    <button type="submit">Log out</button>
</form>
