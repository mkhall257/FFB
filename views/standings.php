<?php
/**
 * Standings table. Expects:
 *
 * @var list<array{team_id:int,wins:int,losses:int,ties:int,points_for:float,win_pct:float}> $rows
 * @var array<int,string> $names
 */
?>
<h1>Standings</h1>
<p><a href="/">Home</a> · <a href="/scoreboard">Scoreboard</a></p>
<?php if ($rows === []): ?>
    <p>No results yet — standings appear once weeks are settled.</p>
<?php else: ?>
<table>
    <thead>
        <tr><th>#</th><th>Team</th><th>W-L-T</th><th>Points For</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= e($names[$r['team_id']] ?? ('Team ' . $r['team_id'])) ?></td>
            <td><?= (int) $r['wins'] ?>-<?= (int) $r['losses'] ?>-<?= (int) $r['ties'] ?></td>
            <td><?= number_format((float) $r['points_for'], 2) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
