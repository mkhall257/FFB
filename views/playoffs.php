<?php
/**
 * Read-only Playoff bracket. Expects:
 *
 * @var array<string,mixed> $bracket  the PlayoffService::bracket() read model
 */

/** Render "Name (#seed)" for one side, bold if they won. */
$sideLabel = static function (array $side): string {
    $seed = $side['seed'] !== null ? ' (#' . (int) $side['seed'] . ')' : '';

    return e($side['name']) . $seed;
};
?>
<h1>Playoffs</h1>
<p><a href="/">Home</a> · <a href="/standings">Standings</a> · <a href="/scoreboard">Scoreboard</a></p>

<?php if (($bracket['exists'] ?? false) !== true): ?>
    <p>The playoff bracket hasn't been created yet. It's set once the regular
    season is finished.</p>
<?php else: ?>

    <?php if ($bracket['champion'] !== null): ?>
        <p role="status" class="champion">🏆 <strong><?= e($bracket['champion']['name']) ?></strong>
        <?php if ($bracket['champion']['seed'] !== null): ?>
            (#<?= (int) $bracket['champion']['seed'] ?> seed)
        <?php endif; ?>
        are your league champions!</p>
    <?php endif; ?>

    <p><?= (int) $bracket['fieldSize'] ?> teams · single elimination</p>

    <?php foreach ($bracket['rounds'] as $round): ?>
        <h2><?= e($round['label']) ?></h2>

        <?php if ($round['byes'] !== []): ?>
            <p><em>Bye:
            <?php $byeNames = array_map(static fn ($b) => $b['name'] . ' (#' . $b['seed'] . ')', $round['byes']); ?>
            <?= e(implode(', ', $byeNames)) ?></em></p>
        <?php endif; ?>

        <?php if ($round['games'] === []): ?>
            <p>No games yet.</p>
        <?php else: ?>
        <table>
            <tbody>
            <?php foreach ($round['games'] as $game): ?>
                <?php
                $homeWon = $game['winner_team_id'] === $game['home']['team_id'];
                $awayWon = $game['winner_team_id'] === $game['away']['team_id'];
                $fmt = static fn ($s) => $s['score'] === null ? '—' : number_format((float) $s['score'], 2);
                ?>
                <tr>
                    <td><?= $homeWon ? '<strong>' . $sideLabel($game['home']) . '</strong>' : $sideLabel($game['home']) ?></td>
                    <td><?= e($fmt($game['home'])) ?></td>
                    <td>vs</td>
                    <td><?= $awayWon ? '<strong>' . $sideLabel($game['away']) . '</strong>' : $sideLabel($game['away']) ?></td>
                    <td><?= e($fmt($game['away'])) ?></td>
                    <td>
                        <?php if ($game['status'] === 'final'): ?>Final
                        <?php elseif ($game['status'] === 'live'): ?>Live
                        <?php else: ?>Scheduled
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($bracket['champion'] === null): ?>
        <p><em>The bracket fills in as the Commissioner advances each round.</em></p>
    <?php endif; ?>
<?php endif; ?>
