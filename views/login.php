<?php
/**
 * Login form. Expects: ?string $error
 *
 * @var string|null $error
 */
?>
<h1>FFB — Log in</h1>
<?php if (!empty($error)): ?>
    <p role="alert"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="/login">
    <label>Username
        <input type="text" name="username" autocomplete="username" required>
    </label>
    <label>Password
        <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit">Log in</button>
</form>
