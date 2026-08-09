<?php
/**
 * Self-service profile page. Expects:
 *
 * @var string $displayName
 * @var string $username
 * @var ?string $flash
 * @var ?string $error
 */
?>
<h1>My Profile</h1>
<p><a href="/">Home</a></p>

<?php if ($error !== null): ?><p role="alert"><strong><?= e($error) ?></strong></p><?php endif; ?>
<?php if ($flash !== null): ?><p role="status"><?= e($flash) ?></p><?php endif; ?>

<p>Signed in as <strong><?= e($username) ?></strong>.</p>

<form method="post" action="/profile">
    <h2>Display name</h2>
    <p>The name shown to the rest of your league.</p>
    <label>Display name
        <input type="text" name="display_name" value="<?= e($displayName) ?>" required>
    </label>

    <h2>Change password</h2>
    <p>Leave these blank to keep your current password.</p>
    <label>Current password
        <input type="password" name="current_password" autocomplete="current-password">
    </label>
    <label>New password
        <input type="password" name="new_password" autocomplete="new-password" minlength="6">
    </label>

    <p><button type="submit">Save changes</button></p>
</form>
