<?php
/**
 * Commissioner Team & Manager management.
 *
 * @var list<array<string,mixed>> $teams  each: team_id, team_name, user_id, username, display_name, is_active
 * @var string|null $flash
 * @var string|null $error
 */
$unassigned = array_filter($teams, static fn (array $t): bool => $t['user_id'] === null);
?>
<h1>Commissioner tools</h1>
<p><a href="/">Home</a> · <a href="/admin/unmatched-players">Unmatched players</a> · <a href="/admin/draft">Draft setup</a></p>

<?php if (!empty($flash)): ?>
    <p role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <p role="alert"><?= e($error) ?></p>
<?php endif; ?>

<h2>Teams</h2>
<table>
    <thead>
        <tr><th>Team</th><th>Manager</th><th>Username</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($teams as $t): ?>
        <tr>
            <td><?= e((string) $t['team_name']) ?></td>
            <td><?= e($t['display_name'] !== null ? (string) $t['display_name'] : '—') ?></td>
            <td><?= e($t['username'] !== null ? (string) $t['username'] : '—') ?></td>
            <td>
                <?php if ($t['user_id'] === null): ?>
                    no manager
                <?php else: ?>
                    <?= ((int) $t['is_active']) === 1 ? 'active' : 'inactive' ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($t['user_id'] !== null): ?>
                    <form method="post" action="/admin/managers/reset">
                        <input type="hidden" name="user_id" value="<?= (int) $t['user_id'] ?>">
                        <input type="password" name="password" placeholder="new password" required>
                        <button type="submit">Reset password</button>
                    </form>
                    <form method="post" action="/admin/managers/status">
                        <input type="hidden" name="user_id" value="<?= (int) $t['user_id'] ?>">
                        <?php if (((int) $t['is_active']) === 1): ?>
                            <input type="hidden" name="active" value="0">
                            <button type="submit">Deactivate</button>
                        <?php else: ?>
                            <input type="hidden" name="active" value="1">
                            <button type="submit">Reactivate</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($teams === []): ?>
        <tr><td colspan="5">No teams yet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2>Add a team</h2>
<form method="post" action="/admin/teams">
    <label>Team name <input type="text" name="name" required></label>
    <button type="submit">Create team</button>
</form>

<h2>Add a manager</h2>
<?php if ($unassigned === []): ?>
    <p>Every team has a manager. Add a team first to provision another manager.</p>
<?php else: ?>
    <form method="post" action="/admin/managers">
        <label>Team
            <select name="team_id" required>
                <?php foreach ($unassigned as $t): ?>
                    <option value="<?= (int) $t['team_id'] ?>"><?= e((string) $t['team_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Display name <input type="text" name="display_name" required></label>
        <label>Username <input type="text" name="username" required></label>
        <label>Password <input type="password" name="password" required></label>
        <button type="submit">Create manager</button>
    </form>
<?php endif; ?>
