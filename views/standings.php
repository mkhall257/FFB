<?php
/**
 * Standings table (broadcast chrome). Expects:
 *
 * @var list<array{team_id:int,wins:int,losses:int,ties:int,points_for:float,win_pct:float}> $rows
 * @var array<int,string> $names
 */
?>
<div class="page-head">
    <div>
        <div class="eyebrow">League table</div>
        <h1>Standings</h1>
    </div>
</div>

<?php if ($rows === []): ?>
    <section class="panel panel--pad"><p class="label">No results yet — standings appear once weeks are settled.</p></section>
<?php else: ?>
<section class="panel">
    <table class="table st-table">
        <thead>
            <tr><th>Rank</th><th>Team</th><th>W-L-T</th><th>Points For</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr<?= $i === 0 ? ' class="rank-1"' : '' ?>>
                <td><?php if ($i === 0): ?><span class="rank-badge">1</span><?php else: ?><?= $i + 1 ?><?php endif; ?></td>
                <td class="st-team"><?= e($names[$r['team_id']] ?? ('Team ' . $r['team_id'])) ?></td>
                <td><?= (int) $r['wins'] ?>-<?= (int) $r['losses'] ?>-<?= (int) $r['ties'] ?></td>
                <td><?= number_format((float) $r['points_for'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
