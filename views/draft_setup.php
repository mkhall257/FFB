<?php
/**
 * Commissioner Draft setup: timer, expiry Auto-pick toggle, display date, and
 * roster shape.
 *
 * @var array<string,mixed>|null $draft     the drafts row, or null before first save
 * @var array<string,string>     $settings  league_settings key => value
 * @var string|null $flash
 * @var string|null $error
 */
$pickSeconds = $draft !== null ? (int) $draft['pick_seconds'] : 120;
$autopick = $draft === null || (int) $draft['autopick_on_expiry'] === 1;
$scheduledAt = $draft !== null && $draft['scheduled_at'] !== null
    ? substr((string) $draft['scheduled_at'], 0, 16)
    : '';
$state = $draft !== null ? (string) $draft['state'] : 'setup';

$slots = ['qb', 'rb', 'wr', 'te', 'flex', 'k', 'def', 'bench'];
$slot = static fn (string $k): int => (int) ($settings['roster.' . $k] ?? 0);
$starters = $slot('qb') + $slot('rb') + $slot('wr') + $slot('te') + $slot('flex') + $slot('k') + $slot('def');
$rounds = $starters + $slot('bench');
?>
<h1>Draft setup</h1>
<p><a href="/admin">Commissioner tools</a> · <a href="/">Home</a></p>

<?php if (!empty($flash)): ?>
    <p role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <p role="alert"><?= e($error) ?></p>
<?php endif; ?>

<p>Draft state: <strong><?= e($state) ?></strong> · <?= (int) $rounds ?> rounds (<?= (int) $starters ?> starters + <?= $slot('bench') ?> bench)</p>

<form method="post" action="/admin/draft/config">
    <h2>Timing</h2>
    <label>Seconds per pick
        <input type="number" name="pick_seconds" min="15" max="600" value="<?= $pickSeconds ?>" required>
    </label>
    <label>
        <input type="hidden" name="autopick_on_expiry" value="0">
        <input type="checkbox" name="autopick_on_expiry" value="1"<?= $autopick ? ' checked' : '' ?>>
        Auto-pick when the timer runs out (off = the Team stays on the clock)
    </label>
    <label>Draft date &amp; time (optional, display only)
        <input type="datetime-local" name="scheduled_at" value="<?= e($scheduledAt) ?>">
    </label>

    <h2>Roster shape</h2>
    <?php foreach ($slots as $s): ?>
        <label><?= strtoupper($s) ?>
            <input type="number" name="roster_<?= $s ?>" min="0" max="30" value="<?= $slot($s) ?>" required>
        </label>
    <?php endforeach; ?>

    <button type="submit">Save draft settings</button>
</form>
