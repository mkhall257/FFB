<?php
/**
 * Commissioner manual roster-edit. Expects:
 *
 * @var list<array{id:int,name:string,roster:list<array<string,mixed>>}> $teams
 * @var list<array<string,mixed>> $freeAgents
 * @var ?string $error
 * @var ?string $flash
 */
?>
<h1>Roster Edit</h1>
<p><a href="/admin">Commissioner tools</a> · <a href="/transactions">Activity</a></p>
<p>Move, add, or drop any player. This bypasses the normal roster limit and
lock rules and is recorded as a reversible commissioner edit.</p>

<?php if ($error !== null): ?><p role="alert"><strong><?= e($error) ?></strong></p><?php endif; ?>
<?php if ($flash !== null): ?><p><?= e($flash) ?></p><?php endif; ?>

<?php foreach ($teams as $t): ?>
    <h2><?= e($t['name']) ?></h2>
    <?php if ($t['roster'] === []): ?>
        <p>(no players)</p>
    <?php else: ?>
        <table>
            <tbody>
            <?php foreach ($t['roster'] as $p): ?>
                <tr>
                    <td><?= e((string) $p['full_name']) ?> (<?= e((string) $p['position']) ?>)</td>
                    <td>
                        <form method="post" action="/admin/roster-edit" style="display:inline">
                            <input type="hidden" name="player_id" value="<?= e((string) $p['player_id']) ?>">
                            <select name="to_team_id">
                                <option value="">Free agents (drop)</option>
                                <?php foreach ($teams as $dest): ?>
                                    <?php if ($dest['id'] === $t['id']) { continue; } ?>
                                    <option value="<?= e((string) $dest['id']) ?>">to <?= e($dest['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Move</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endforeach; ?>

<h2>Add a free agent to a team</h2>
<form method="post" action="/admin/roster-edit">
    <select name="player_id" required>
        <option value="">— choose a player —</option>
        <?php foreach ($freeAgents as $p): ?>
            <option value="<?= e((string) $p['sleeper_id']) ?>">
                <?= e((string) $p['full_name']) ?> (<?= e((string) $p['position']) ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <select name="to_team_id" required>
        <option value="">— to team —</option>
        <?php foreach ($teams as $t): ?>
            <option value="<?= e((string) $t['id']) ?>"><?= e($t['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Add</button>
</form>
