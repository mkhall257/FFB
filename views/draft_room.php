<?php
/**
 * The live draft room.
 *
 * @var array<string,mixed>|null $draft
 * @var list<array<string,mixed>> $board       full pick board (overall_pick, team_name, player_name, ...)
 * @var list<array<string,mixed>> $available   undrafted players, best first
 * @var list<array<string,mixed>> $myQueue      the viewer's personal queue, in rank order
 * @var array<string,mixed>|null $myTeam        the viewer's team, or null
 * @var int|null $onClockTeamId
 * @var bool $myTurn
 * @var bool $isCommissioner
 * @var list<array<string,mixed>> $order   draft order rows (commissioner only)
 * @var string|null $flash
 * @var string|null $error
 */
$state = $draft !== null ? (string) $draft['state'] : 'none';
$onClock = null;
foreach ($board as $row) {
    if ((int) $row['overall_pick'] === (int) ($draft['current_pick_no'] ?? 0)) {
        $onClock = $row;
        break;
    }
}
$made = array_values(array_filter($board, static fn ($r) => $r['player_id'] !== null));
$recent = array_slice(array_reverse($made), 0, 10);
?>
<h1>Draft room</h1>
<p><a href="/">Home</a></p>

<?php if (!empty($flash)): ?><p role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if (!empty($error)): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if ($draft === null || $state === 'setup' || $state === 'ready'): ?>
    <p>The draft hasn't started yet.<?= $state === 'ready' ? ' It has been finalized — hang tight for the commissioner to start it.' : '' ?></p>
<?php elseif ($state === 'complete'): ?>
    <p>The draft is complete. Final results below.</p>
<?php else: ?>
    <p>
        Status: <strong><?= e($state) ?></strong>.
        On the clock: <strong><?= e($onClock !== null ? (string) $onClock['team_name'] : '—') ?></strong>
        (pick <?= (int) ($draft['current_pick_no'] ?? 0) ?>).
    </p>

    <?php if ($state === 'live' && $myTurn): ?>
        <h2>You're on the clock — make your pick</h2>
        <form method="post" action="/draft/pick">
            <select name="player_id" required>
                <?php foreach ($available as $p): ?>
                    <option value="<?= e((string) $p['sleeper_id']) ?>">
                        <?= e((string) $p['full_name']) ?> (<?= e((string) $p['position']) ?><?= $p['nfl_team'] !== null ? ', ' . e((string) $p['nfl_team']) : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Draft player</button>
        </form>
    <?php elseif ($state === 'live'): ?>
        <p>Waiting for <?= e($onClock !== null ? (string) $onClock['team_name'] : 'the next team') ?> to pick&hellip;</p>
    <?php elseif ($state === 'paused'): ?>
        <p>The draft is paused by the commissioner.</p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($myTeam !== null && ($draft !== null && in_array($state, ['ready', 'live', 'paused'], true))): ?>
    <h2>My queue</h2>
    <?php if ($myQueue === []): ?>
        <p>Your queue is empty. Add players below — they drive your auto-pick if your timer runs out.</p>
    <?php else: ?>
        <ol>
            <?php foreach ($myQueue as $q): ?>
                <li>
                    <?= e((string) $q['full_name']) ?> (<?= e((string) $q['position']) ?>)
                    <form method="post" action="/draft/queue/remove" style="display:inline">
                        <input type="hidden" name="player_id" value="<?= e((string) $q['player_id']) ?>">
                        <button type="submit">Remove</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($available !== []): ?>
        <form method="post" action="/draft/queue/add">
            <select name="player_id" required>
                <?php foreach ($available as $p): ?>
                    <option value="<?= e((string) $p['sleeper_id']) ?>">
                        <?= e((string) $p['full_name']) ?> (<?= e((string) $p['position']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Add to queue</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php if ($isCommissioner && $draft !== null && in_array($state, ['live', 'paused'], true)): ?>
    <h2>Commissioner controls</h2>
    <p>
        <?php if ($state === 'live'): ?>
            <form method="post" action="/admin/draft/pause" style="display:inline"><button type="submit">Pause</button></form>
        <?php else: ?>
            <form method="post" action="/admin/draft/resume" style="display:inline"><button type="submit">Resume</button></form>
        <?php endif; ?>
        <form method="post" action="/admin/draft/add-time" style="display:inline">
            <input type="hidden" name="seconds" value="30"><button type="submit">+30s</button>
        </form>
        <form method="post" action="/admin/draft/undo-last" style="display:inline"><button type="submit">Undo last pick</button></form>
        <form method="post" action="/admin/draft/reset" style="display:inline"
              onsubmit="return confirm('Really reset the whole draft? This wipes every pick.') &amp;&amp; confirm('Are you absolutely sure?')">
            <button type="submit">Reset draft</button>
        </form>
    </p>

    <?php if ($state === 'live'): ?>
        <form method="post" action="/admin/draft/pick-on-behalf">
            Pick for the team on the clock:
            <select name="player_id" required>
                <?php foreach ($available as $p): ?>
                    <option value="<?= e((string) $p['sleeper_id']) ?>"><?= e((string) $p['full_name']) ?> (<?= e((string) $p['position']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Draft for them</button>
        </form>
    <?php endif; ?>

    <?php if ($order !== []): ?>
        <h3>Auto-draft teams</h3>
        <ul>
            <?php foreach ($order as $o): ?>
                <li>
                    <?= e((string) $o['team_name']) ?> —
                    <?php if ((int) $o['auto_draft'] === 1): ?>
                        auto-drafting
                        <form method="post" action="/admin/draft/auto-draft" style="display:inline">
                            <input type="hidden" name="team_id" value="<?= (int) $o['team_id'] ?>">
                            <input type="hidden" name="enabled" value="0">
                            <button type="submit">Turn off</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/admin/draft/auto-draft" style="display:inline">
                            <input type="hidden" name="team_id" value="<?= (int) $o['team_id'] ?>">
                            <input type="hidden" name="enabled" value="1">
                            <button type="submit">Auto-draft this team</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>

<?php if ($recent !== []): ?>
    <h2>Recent picks</h2>
    <ol reversed>
        <?php foreach ($recent as $r): ?>
            <li>#<?= (int) $r['overall_pick'] ?> — <?= e((string) $r['team_name']) ?>:
                <?= e((string) $r['player_name']) ?> (<?= e((string) $r['position']) ?>)</li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php if ($board !== []): ?>
    <h2>Board</h2>
    <table>
        <thead><tr><th>#</th><th>Rd</th><th>Team</th><th>Player</th></tr></thead>
        <tbody>
        <?php foreach ($board as $row): ?>
            <tr>
                <td><?= (int) $row['overall_pick'] ?></td>
                <td><?= (int) $row['round'] ?></td>
                <td><?= e((string) $row['team_name']) ?></td>
                <td><?= $row['player_name'] !== null ? e((string) $row['player_name']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
