<?php
/**
 * Commissioner Season Control. Expects:
 *
 * @var int $currentWeek
 * @var int $nextWeek
 * @var int $seasonYear
 * @var string $kickoffPrefill    datetime-local value
 * @var array<string,string> $scoring   scoring key (without prefix) => value
 * @var array<string,string> $roster    roster key (without prefix) => value
 * @var ?string $flash
 * @var ?string $error
 */
?>
<h1>Season control</h1>
<p><a href="/">Home</a> · <a href="/admin">Commissioner tools</a> · <a href="/scoreboard">Scoreboard</a></p>

<?php if ($flash !== null): ?><p role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($error !== null): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>

<h2>Current week</h2>
<p>
    <?php if ($currentWeek < 1): ?>
        The season has not started yet — start Week 1 below to begin live scoring.
    <?php else: ?>
        Week <strong><?= (int) $currentWeek ?></strong> is current.
    <?php endif; ?>
</p>

<h2>Start a week</h2>
<p>Sets the current week for scoring and the time this week's lineups lock (first kickoff).</p>
<form method="post" action="/admin/season/week">
    <label>Season year
        <input type="number" name="season_year" value="<?= (int) $seasonYear ?>" min="2000" max="2100" required>
    </label>
    <label>Week
        <input type="number" name="week" value="<?= (int) $nextWeek ?>" min="1" max="25" required>
    </label>
    <label>Lineups lock at (league time)
        <input type="datetime-local" name="kickoff" value="<?= e($kickoffPrefill) ?>" required>
    </label>
    <button type="submit">Start this week</button>
</form>

<h2>Scoring</h2>
<p>Points per unit. Changing these re-scores every week from the stored stats.</p>
<form method="post" action="/admin/season/scoring">
    <table>
        <thead><tr><th>Rule</th><th>Value</th></tr></thead>
        <tbody>
        <?php foreach ($scoring as $key => $value): ?>
            <tr>
                <td><label for="scoring_<?= e($key) ?>"><?= e($key) ?></label></td>
                <td><input id="scoring_<?= e($key) ?>" type="number" step="any"
                    name="scoring[<?= e($key) ?>]" value="<?= e($value) ?>"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <button type="submit">Save scoring</button>
</form>

<h2>Roster shape</h2>
<p>How many of each slot a team starts (and bench size).</p>
<form method="post" action="/admin/season/roster">
    <table>
        <thead><tr><th>Slot</th><th>Count</th></tr></thead>
        <tbody>
        <?php foreach ($roster as $key => $value): ?>
            <tr>
                <td><label for="roster_<?= e($key) ?>"><?= e($key) ?></label></td>
                <td><input id="roster_<?= e($key) ?>" type="number" min="0" step="1"
                    name="roster[<?= e($key) ?>]" value="<?= e($value) ?>"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <button type="submit">Save roster</button>
</form>
