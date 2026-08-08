<?php
/**
 * Unmatched Players review.
 *
 * @var list<array<string,mixed>> $players
 * @var int $playerCount
 * @var int $linkedCount
 * @var array<string,mixed>|null $lastSync
 */
?>
<h1>Unmatched players</h1>
<p><a href="/admin">Back to Commissioner tools</a></p>

<h2>Last sync</h2>
<?php if ($lastSync === null): ?>
    <p>No player sync has run yet.</p>
<?php else: ?>
    <ul>
        <li>Run #<?= (int) $lastSync['id'] ?> — <strong><?= e((string) $lastSync['outcome']) ?></strong></li>
        <li>Started: <?= e((string) $lastSync['started_at']) ?></li>
        <li>Finished: <?= e($lastSync['finished_at'] !== null ? (string) $lastSync['finished_at'] : '—') ?></li>
        <li>Players upserted: <?= (int) $lastSync['players_upserted'] ?></li>
        <li>Unmatched at sync time: <?= (int) $lastSync['unmatched_count'] ?></li>
        <?php if ($lastSync['message'] !== null): ?>
            <li>Message: <?= e((string) $lastSync['message']) ?></li>
        <?php endif; ?>
    </ul>
<?php endif; ?>

<p><?= $linkedCount ?> of <?= $playerCount ?> catalog players are linked to nflverse.</p>

<h2>Unmatched now (<?= count($players) ?>)</h2>
<p>These rosterable players have no nflverse link, so they will not score until matched. Most are undrafted/practice-squad players with no NFL stats yet.</p>
<table>
    <thead>
        <tr><th>Player</th><th>Pos</th><th>Team</th><th>Status</th><th>Sleeper id</th></tr>
    </thead>
    <tbody>
    <?php foreach ($players as $p): ?>
        <tr>
            <td><?= e((string) $p['full_name']) ?></td>
            <td><?= e((string) $p['position']) ?></td>
            <td><?= e((string) $p['nfl_team']) ?></td>
            <td><?= e($p['status'] !== null ? (string) $p['status'] : '—') ?></td>
            <td><?= e((string) $p['sleeper_id']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($players === []): ?>
        <tr><td colspan="5">Every rosterable player is linked. 🎉</td></tr>
    <?php endif; ?>
    </tbody>
</table>
