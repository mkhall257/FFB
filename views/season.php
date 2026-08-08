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
 * @var string $tradeDeadlineWeek
 * @var string $playoffTeamCount
 * @var ?string $flash
 * @var ?string $error
 */

// Friendly labels, in a sensible display order. Keys not listed here still
// render (with their raw name) so a newly-added rule is never hidden.
$scoringLabels = [
    'pass_yard' => 'Passing yards (per yard)',
    'pass_td' => 'Passing touchdown',
    'pass_int' => 'Interception thrown',
    'rush_yard' => 'Rushing yards (per yard)',
    'rush_td' => 'Rushing touchdown',
    'rec_yard' => 'Receiving yards (per yard)',
    'rec_td' => 'Receiving touchdown',
    'reception' => 'Reception (PPR)',
    'fumble_lost' => 'Fumble lost',
    'fg_made' => 'Field goal made',
    'xp_made' => 'Extra point made',
    'def_sack' => 'Defense — sack',
    'def_int' => 'Defense — interception',
    'def_fumble_rec' => 'Defense — fumble recovery',
    'def_td' => 'Defense — touchdown',
    'def_safety' => 'Defense — safety',
    'def_pa_0' => 'Defense — 0 points allowed',
    'def_pa_1_6' => 'Defense — 1 to 6 points allowed',
    'def_pa_7_13' => 'Defense — 7 to 13 points allowed',
    'def_pa_14_20' => 'Defense — 14 to 20 points allowed',
    'def_pa_21_27' => 'Defense — 21 to 27 points allowed',
    'def_pa_28_34' => 'Defense — 28 to 34 points allowed',
    'def_pa_35' => 'Defense — 35+ points allowed',
];
$rosterLabels = [
    'qb' => 'Quarterback (QB)',
    'rb' => 'Running back (RB)',
    'wr' => 'Wide receiver (WR)',
    'te' => 'Tight end (TE)',
    'flex' => 'Flex (RB/WR/TE)',
    'k' => 'Kicker (K)',
    'def' => 'Defense (DEF)',
    'bench' => 'Bench',
];

/**
 * Return [key => value] ordered by the label map first, then any leftover keys.
 *
 * @param array<string,string> $labels
 * @param array<string,string> $values
 * @return list<string>
 */
$orderedKeys = static function (array $labels, array $values): array {
    $ordered = array_values(array_intersect(array_keys($labels), array_keys($values)));
    $rest = array_values(array_diff(array_keys($values), $ordered));

    return array_merge($ordered, $rest);
};
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
        <thead><tr><th>Rule</th><th>Points</th></tr></thead>
        <tbody>
        <?php foreach ($orderedKeys($scoringLabels, $scoring) as $key): ?>
            <tr>
                <td><label for="scoring_<?= e($key) ?>"><?= e($scoringLabels[$key] ?? $key) ?></label></td>
                <td><input id="scoring_<?= e($key) ?>" type="number" step="any"
                    name="scoring[<?= e($key) ?>]" value="<?= e($scoring[$key]) ?>"></td>
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
        <?php foreach ($orderedKeys($rosterLabels, $roster) as $key): ?>
            <tr>
                <td><label for="roster_<?= e($key) ?>"><?= e($rosterLabels[$key] ?? $key) ?></label></td>
                <td><input id="roster_<?= e($key) ?>" type="number" min="0" step="1"
                    name="roster[<?= e($key) ?>]" value="<?= e($roster[$key]) ?>"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <button type="submit">Save roster</button>
</form>

<h2>Trade deadline</h2>
<p>The last week trades can be made. Leave blank for no deadline (trading stays
open all season). Add/Drop is never affected.</p>
<form method="post" action="/admin/season/trades">
    <label for="trade_deadline_week">Trades close after week</label>
    <input id="trade_deadline_week" type="number" min="1" max="25" step="1"
        name="trade_deadline_week" value="<?= e($tradeDeadlineWeek) ?>" placeholder="none">
    <button type="submit">Save trade deadline</button>
</form>

<h2>Playoffs</h2>
<p>How many teams make the single-elimination bracket. Top seeds get a first-round
bye when this isn't a power of two. Create the bracket once the final regular-season
week is settled — it freezes the current standings as the seeds.</p>
<form method="post" action="/admin/season/playoffs">
    <label for="team_count">How many teams make the playoffs</label>
    <input id="team_count" type="number" min="2" step="1"
        name="team_count" value="<?= e($playoffTeamCount) ?>">
    <button type="submit">Save playoff size</button>
</form>
<form method="post" action="/admin/playoffs/create">
    <label>Round 1 lineups lock at (league time, optional)
        <input type="datetime-local" name="kickoff">
    </label>
    <button type="submit">Create the playoff bracket</button>
</form>
<form method="post" action="/admin/playoffs/advance">
    <label>Next round lineups lock at (league time, optional)
        <input type="datetime-local" name="kickoff">
    </label>
    <button type="submit">Advance to the next round</button>
</form>
<form method="post" action="/admin/playoffs/correct">
    <button type="submit">Undo the last round (correct a result)</button>
</form>
<form method="post" action="/admin/playoffs/reset">
    <button type="submit">Reset the bracket (before any games are played)</button>
</form>
<p><a href="/playoffs">View the bracket</a></p>
