<?php
/**
 * Authenticated landing page. Expects: string $displayName, string $role
 *
 * @var string $displayName
 * @var string $role
 */
?>
<h1>Welcome, <?= e($displayName) ?></h1>
<p>You are logged in as <?= e($role) ?>.</p>
<p><a href="/draft">Draft room</a></p>
<?php if ($role === 'commissioner'): ?>
    <p><a href="/admin">Commissioner tools</a></p>
<?php endif; ?>
<form method="post" action="/logout">
    <button type="submit">Log out</button>
</form>
