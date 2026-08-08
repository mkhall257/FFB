<?php
/**
 * Free Agents / Add Players page. Expects:
 *
 * @var bool $hasTeam
 * @var list<array<string,mixed>> $available  unrostered players (sleeper_id, full_name, position, nfl_team)
 * @var list<array<string,mixed>> $myRoster   this team's players (player_id, full_name, position)
 * @var int $cap
 * @var int $rosterSize
 * @var string $search
 * @var string $position
 * @var ?string $error
 * @var ?string $flash
 */
$positions = ['QB', 'RB', 'WR', 'TE', 'K', 'DEF'];
$full = $rosterSize >= $cap;
?>
<h1>Free Agents</h1>
<p><a href="/">Home</a> · <a href="/lineup">My Lineup</a> · <a href="/trades">Trades</a> · <a href="/transactions">Activity</a></p>

<?php if ($error !== null): ?><p role="alert"><strong><?= e($error) ?></strong></p><?php endif; ?>
<?php if ($flash !== null): ?><p><?= e($flash) ?></p><?php endif; ?>

<?php if (!$hasTeam): ?>
    <p>You do not manage a team in this league.</p>
<?php else: ?>
    <p>Your roster: <?= (int) $rosterSize ?> / <?= (int) $cap ?><?= $full ? ' (full — you must drop to add)' : '' ?></p>

    <form method="get" action="/players">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search players">
        <select name="pos">
            <option value="">All positions</option>
            <?php foreach ($positions as $p): ?>
                <option value="<?= e($p) ?>"<?= $position === $p ? ' selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
    </form>

    <table>
        <thead><tr><th>Player</th><th>Pos</th><th>Team</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($available as $p): ?>
            <?php $pid = (string) $p['sleeper_id']; ?>
            <tr data-player="<?= e($pid) ?>">
                <td><?= e((string) $p['full_name']) ?></td>
                <td><?= e((string) $p['position']) ?></td>
                <td><?= e((string) ($p['nfl_team'] ?? '')) ?></td>
                <td>
                    <form method="post" action="/players/add">
                        <input type="hidden" name="add_player_id" value="<?= e($pid) ?>">
                        <?php if ($full): ?>
                            <select name="drop_player_id" required>
                                <option value="">— drop a player —</option>
                                <?php foreach ($myRoster as $r): ?>
                                    <option value="<?= e((string) $r['player_id']) ?>">
                                        <?= e((string) $r['full_name']) ?> (<?= e((string) $r['position']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="submit">Add</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
