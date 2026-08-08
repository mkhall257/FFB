<?php
/**
 * Weekly lineup editor. Expects:
 *
 * @var bool $hasTeam
 * @var int $week
 * @var list<array{roster_slot:string,slot_index:int,player_id:?string}> $slots
 * @var list<array<string,mixed>> $roster   rostered players (player_id, full_name, position)
 * @var bool $locked
 * @var ?string $error
 * @var ?string $flash
 */
$flexEligible = ['RB', 'WR', 'TE'];
$eligibleFor = static function (string $slot) use ($flexEligible): array {
    return $slot === 'FLEX' ? $flexEligible : [$slot];
};
?>
<h1>My Lineup — Week <?= (int) $week ?></h1>
<p><a href="/">Home</a> · <a href="/scoreboard">Scoreboard</a> · <a href="/standings">Standings</a></p>

<?php if ($error !== null): ?><p role="alert"><strong><?= e($error) ?></strong></p><?php endif; ?>
<?php if ($flash !== null): ?><p><?= e($flash) ?></p><?php endif; ?>

<?php if (!$hasTeam): ?>
    <p>You do not manage a team in this league.</p>
<?php else: ?>
    <?php if ($locked): ?>
        <p><strong>Lineups are locked for this week.</strong></p>
    <?php endif; ?>
    <form method="post" action="/lineup">
        <table>
            <thead><tr><th>Slot</th><th>Player</th></tr></thead>
            <tbody>
            <?php foreach ($slots as $s): ?>
                <?php $key = $s['roster_slot'] . ':' . $s['slot_index']; ?>
                <?php $allowed = $eligibleFor($s['roster_slot']); ?>
                <tr>
                    <td><?= e($s['roster_slot']) ?></td>
                    <td>
                        <select name="players[<?= e($key) ?>]"<?= $locked ? ' disabled' : '' ?>>
                            <option value="">— empty —</option>
                            <?php foreach ($roster as $p): ?>
                                <?php if (!in_array($p['position'], $allowed, true)) { continue; } ?>
                                <option value="<?= e((string) $p['player_id']) ?>"
                                    <?= $s['player_id'] === $p['player_id'] ? ' selected' : '' ?>>
                                    <?= e((string) $p['full_name']) ?> (<?= e((string) $p['position']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$locked): ?><button type="submit">Save lineup</button><?php endif; ?>
    </form>
<?php endif; ?>
