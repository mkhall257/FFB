<?php
/**
 * Weekly scoreboard. Expects:
 *
 * @var int $week
 * @var list<array<string,mixed>> $matchups
 * @var array<int,string> $names
 * @var array<string,string> $stateLabels
 */
$name = static fn (int $id): string => $names[$id] ?? ('Team ' . $id);
$score = static fn ($v): string => $v === null ? '—' : number_format((float) $v, 2);
?>
<h1>Scoreboard — Week <?= (int) $week ?></h1>
<p>
    <a href="/">Home</a> · <a href="/standings">Standings</a>
    <?php if ($week > 1): ?> · <a href="/scoreboard?week=<?= $week - 1 ?>">← Week <?= $week - 1 ?></a><?php endif; ?>
    · <a href="/scoreboard?week=<?= $week + 1 ?>">Week <?= $week + 1 ?> →</a>
</p>
<?php if ($matchups === []): ?>
    <p>No matchups scheduled for this week.</p>
<?php else: ?>
    <ul>
    <?php foreach ($matchups as $m): ?>
        <?php $state = (string) $m['status']; ?>
        <li>
            <?= e($name((int) $m['home_team_id'])) ?>
            <strong><?= $score($m['home_score']) ?></strong>
            —
            <strong><?= $score($m['away_score']) ?></strong>
            <?= e($name((int) $m['away_team_id'])) ?>
            <em>[<?= e($stateLabels[$state] ?? ucfirst($state)) ?>]</em>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
