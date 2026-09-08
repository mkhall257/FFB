<?php
/**
 * The live draft room.
 *
 * @var array<string,mixed>|null $draft
 * @var list<array<string,mixed>> $board       full pick board (overall_pick, team_name, player_name, ...)
 * @var list<array<string,mixed>> $available   undrafted players (filtered), best first
 * @var list<array<string,mixed>> $myQueue      the viewer's personal queue, in rank order
 * @var array<string,mixed>|null $myTeam        the viewer's team, or null
 * @var int|null $onClockTeamId
 * @var string|null $onClockName                  team currently on the clock
 * @var string|null $nextUpName                   team picking after the clock
 * @var int|null $nextUpTeamId                     id of the team picking after the clock
 * @var int|null $myNextOverall                   overall number of the viewer's next unmade pick
 * @var int|null $myNextRound                     round of that pick
 * @var int|null $picksUntilMyTurn                picks until the viewer is up (0 = now, null = none left)
 * @var bool $autopickOnExpiry                    whether an expired timer auto-picks
 * @var bool $myTurn
 * @var int|null $secondsLeft                    seconds left on the current pick clock, or null
 * @var array<string,int> $myRosterCounts        the viewer's drafted counts, position => count
 * @var array<string,int> $rosterShape           target slots: QB/RB/WR/TE/K/DEF/FLEX/BENCH => count
 * @var list<string> $filterPositions            the position filter buttons, in order
 * @var string $filterPos                        the active position filter ('' = all)
 * @var string $filterQ                          the active name search ('' = none)
 * @var bool $isCommissioner
 * @var list<array<string,mixed>> $order   draft order rows (commissioner only)
 * @var list<array<string,mixed>> $draftOrder  draft order rows for everyone (grid columns + status)
 * @var list<int> $autoDraftTeamIds        team ids currently auto-drafting
 * @var list<int> $connectedTeamIds        team ids whose manager is in the room now
 * @var int|null $fixOverall               overall pick the commissioner is correcting (?fix=), or null
 * @var string|null $fixCurrentName        player currently on the pick being corrected
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
$onClockName = $onClock !== null ? (string) $onClock['team_name'] : '—';
$made = array_values(array_filter($board, static fn ($r) => $r['player_id'] !== null));
$recent = array_slice(array_reverse($made), 0, 10);

$poolOpen = in_array($state, ['ready', 'live', 'paused'], true);
// The commissioner sees the pool during a live OR paused draft — paused so they
// can correct a pick (?fix=) while the clock is stopped.
$showPool = $poolOpen && ($myTeam !== null || ($isCommissioner && in_array($state, ['live', 'paused'], true)));
$fixing = $isCommissioner && ($fixOverall ?? null) !== null;

// Build a /draft URL that keeps the other filter set when one changes.
$filterUrl = static function (?string $pos, ?string $q): string {
    $params = [];
    if ($pos !== null && $pos !== '') {
        $params['pos'] = $pos;
    }
    if ($q !== null && $q !== '') {
        $params['q'] = $q;
    }
    return '/draft' . ($params === [] ? '' : '?' . http_build_query($params));
};

// A short, colour-coded flag for a Player whose availability is in doubt.
// Sleeper's status is "Active" for healthy players; anything else (Out,
// Questionable, Injured Reserve, Suspended, …) is worth surfacing so a Manager
// doesn't unknowingly draft or queue a hurt or unavailable player.
$statusFlag = static function ($status): string {
    $status = trim((string) $status);
    if ($status === '' || strcasecmp($status, 'Active') === 0) {
        return '';
    }
    $labels = [
        'Injured Reserve' => 'IR',
        'Physically Unable to Perform' => 'PUP',
        'Non Football Injury' => 'NFI',
        'Questionable' => 'Q',
        'Doubtful' => 'D',
        'Suspended' => 'SUSP',
        'Inactive' => 'INA',
    ];
    $label = $labels[$status] ?? strtoupper($status);

    return ' <span class="status-flag" title="' . e($status) . '">' . e($label) . '</span>';
};

// Auto-draft / presence helpers, so a Manager can see which teams are picking
// automatically and who is actually in the room.
$isAuto = static fn (?int $teamId): bool => $teamId !== null && in_array($teamId, $autoDraftTeamIds, true);
$isConnected = static fn (?int $teamId): bool => $teamId !== null && in_array($teamId, $connectedTeamIds, true);
$autoBadge = static fn (?int $teamId): string => $isAuto($teamId)
    ? ' <span class="tag tag-auto" title="This team is auto-drafting">auto</span>' : '';
$presenceDot = static fn (?int $teamId): string => $isConnected($teamId)
    ? '<span class="dot on" title="In the room now">&#9679;</span>'
    : '<span class="dot off" title="Not in the room">&#9675;</span>';
?>
<?php if ($state === 'live'): ?>
    <?php // Poll so everyone — including the manager ON the clock — sees picks land,
          // the clock move, and any commissioner pause/added time. The on-clock
          // manager polls a little slower so a reload is less likely to interrupt a
          // pick. Reloads pause while a form control is focused (see script). ?>
    <script>window.FFB_DRAFT_POLL = <?= $myTurn ? 5000 : 2500 ?>;</script>
<?php elseif ($state === 'paused'): ?>
    <?php // Keep the paused screen fresh so everyone sees the resume / added time. ?>
    <script>window.FFB_DRAFT_POLL = 5000;</script>
<?php elseif ($state === 'ready' && !empty($draft['scheduled_at'])): ?>
    <?php // Poll so the pre-staged auto-start fires (and everyone lands in the live room) at the scheduled time. ?>
    <script>window.FFB_DRAFT_POLL = 15000;</script>
<?php endif; ?>
<script>
window.FFB_MY_TURN = <?= $myTurn && $state === 'live' ? 'true' : 'false' ?>;
window.FFB_PICK_NO = <?= (int) ($draft['current_pick_no'] ?? 0) ?>;
// My drafted counts and starter targets, so the room can warn before a Manager
// wastes a pick on a position (QB/K/DEF) they've already filled.
window.FFB_ROSTER = {
    counts: <?= json_encode((object) $myRosterCounts, JSON_THROW_ON_ERROR) ?>,
    shape: <?= json_encode((object) $rosterShape, JSON_THROW_ON_ERROR) ?>
};
</script>

<style>
.draft-clock { font-size: 2rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.draft-clock.inline { font-size: 1.1rem; }
.draft-clock.low { color: #c0392b; }
.onclock-banner { padding: 0.75rem 1rem; border-radius: 8px; background: #eef4ff; margin: 0.5rem 0 1rem; }
.onclock-banner.mine { background: #e7f8ec; border: 2px solid #2ecc71; }
.pool-filters { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; margin: 0.5rem 0; }
.pool-chip { display: inline-block; padding: 0.3rem 0.7rem; border-radius: 999px; border: 1px solid #bbb;
    text-decoration: none; color: inherit; font-size: 0.9rem; }
.pool-chip.active { background: #2d6cdf; color: #fff; border-color: #2d6cdf; }
.pool-table { width: 100%; border-collapse: collapse; }
.pool-table th, .pool-table td { text-align: left; padding: 0.3rem 0.5rem; border-bottom: 1px solid #eee; }
.pool-scroll { max-height: 60vh; overflow-y: auto; border: 1px solid #eee; border-radius: 6px; }
.pool-table td.actions { white-space: nowrap; }
.needs { display: flex; flex-wrap: wrap; gap: 0.4rem; margin: 0.5rem 0; }
.need-pill { padding: 0.25rem 0.6rem; border-radius: 6px; background: #f1f1f1; font-size: 0.9rem; }
.need-pill.met { background: #e7f8ec; }
.need-pill.open { background: #fff3cd; }
.status-flag { display: inline-block; padding: 0 0.35rem; margin-left: 0.15rem; border-radius: 4px;
    background: #fdecea; color: #c0392b; font-size: 0.7rem; font-weight: 700; vertical-align: middle; }
.fix-banner { padding: 0.6rem 0.9rem; border-radius: 8px; background: #fff3cd; border: 1px solid #e0c65a;
    margin: 0.5rem 0 1rem; }
.queue-actions { white-space: nowrap; }
.queue-actions form { display: inline; }
.queue-actions button { min-width: 2rem; }
.tag { display: inline-block; padding: 0 0.35rem; border-radius: 4px; font-size: 0.7rem; font-weight: 700; vertical-align: middle; }
.tag-auto { background: #e8e0ff; color: #5b3fbf; }
.dot { font-size: 0.85rem; line-height: 1; }
.dot.on { color: #2ecc71; }
.dot.off { color: #c0392b; }
.away-note { color: #c0392b; font-size: 0.85em; }
.grid-scroll { overflow-x: auto; border: 1px solid #eee; border-radius: 6px; }
.draft-grid { border-collapse: collapse; font-size: 0.8rem; }
.draft-grid th, .draft-grid td { border: 1px solid #eee; padding: 0.3rem 0.45rem; text-align: left; vertical-align: top; white-space: nowrap; }
.draft-grid thead th { background: #f6f8fc; position: sticky; top: 0; }
.draft-grid th.round-col, .draft-grid td.round-col { background: #f6f8fc; font-weight: 700; text-align: center; }
.draft-grid td.filled { background: #fff; }
.draft-grid td.empty { color: #bbb; }
.draft-grid td.onclock { background: #fff3cd; outline: 2px solid #e0c65a; }
.draft-grid .cell-pos { color: #888; }
.draft-grid .cell-fix { font-size: 0.7rem; }
</style>

<h1>Draft room</h1>
<p><a href="/">Home</a></p>

<?php if (!empty($flash)): ?><p role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if (!empty($error)): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if ($fixing): ?>
    <div class="fix-banner" role="status">
        <strong>Correcting pick #<?= (int) $fixOverall ?><?= $fixCurrentName !== null ? ' (currently ' . e($fixCurrentName) . ')' : '' ?>.</strong>
        Choose a replacement from the available players below.
        <a href="/draft">Cancel</a>
    </div>
<?php endif; ?>

<?php if ($draft === null || $state === 'setup' || $state === 'ready'): ?>
    <?php if ($state === 'ready' && !empty($draft['scheduled_at'])): ?>
        <p>The draft is set to start automatically on
            <strong><?= e(date('D M j, Y \a\t g:i A', strtotime((string) $draft['scheduled_at']))) ?></strong>.
            This page will bring you in when it does.</p>
    <?php else: ?>
        <p>The draft hasn't started yet.<?= $state === 'ready' ? ' It has been finalized — hang tight for the commissioner to start it.' : '' ?></p>
    <?php endif; ?>
<?php elseif ($state === 'aborted' || $state === 'complete'): ?>
    <?php $done = $state === 'complete'; ?>
    <p><?= $done
        ? 'The draft is complete. Final rosters below.'
        : 'The draft was stopped by the commissioner. The picks made before it stopped are shown below.' ?></p>
    <?php
    $rostersByTeam = [];
    foreach ($board as $row) {
        if ($row['player_id'] !== null) {
            $rostersByTeam[(string) $row['team_name']][] = $row;
        }
    }
    ?>
    <div>
        <?php foreach ($rostersByTeam as $teamName => $rows): ?>
            <h3><?= e((string) $teamName) ?></h3>
            <ul>
                <?php foreach ($rows as $r): ?>
                    <li><?= e((string) $r['player_name']) ?> (<?= e((string) $r['position']) ?>)</li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?php // Live or paused. ?>
    <div class="onclock-banner<?= $myTurn ? ' mine' : '' ?>">
        <?php if ($state === 'paused'): ?>
            <strong>&#9208; The draft is paused by the commissioner.</strong>
        <?php elseif ($myTurn): ?>
            <div><strong>&#128994; You're on the clock &mdash; make your pick below!</strong></div>
            <?php if ($state === 'live' && $secondsLeft !== null): ?>
                <div style="margin-top:0.35rem">
                    <?= $autopickOnExpiry ? 'Auto-pick in' : 'Time left' ?>:
                    <span class="draft-clock" data-seconds-left="<?= (int) $secondsLeft ?>">--:--</span>
                </div>
                <p style="margin:0.25rem 0 0; font-size:0.85em">
                    <?= $autopickOnExpiry
                        ? 'If the timer runs out, your top queued player (or the best available) is drafted for you.'
                        : 'If the timer runs out you stay on the clock &mdash; nothing is picked until you choose.' ?>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <div>
                On the clock: <strong><?= e((string) $onClockName) ?></strong><?= $autoBadge($onClockTeamId) ?>
                <?= $presenceDot($onClockTeamId) ?>
                (pick #<?= (int) ($draft['current_pick_no'] ?? 0) ?><?= $onClock !== null ? ', round ' . (int) $onClock['round'] : '' ?>)
                <?php if ($state === 'live' && $secondsLeft !== null): ?>
                    &mdash; <span class="draft-clock inline" data-seconds-left="<?= (int) $secondsLeft ?>">--:--</span>
                <?php endif; ?>
                <?php if ($state === 'live' && !$isConnected($onClockTeamId) && !$isAuto($onClockTeamId)): ?>
                    <div class="away-note">This manager isn't in the room — they may run the clock down<?= $autopickOnExpiry ? ' and be auto-picked' : '' ?>.</div>
                <?php endif; ?>
            </div>
            <?php if ($nextUpName !== null): ?>
                <div>Next up: <strong><?= e($nextUpName) ?></strong><?= $autoBadge($nextUpTeamId) ?> <?= $presenceDot($nextUpTeamId) ?></div>
            <?php endif; ?>
            <?php if ($myTeam !== null): ?>
                <div style="margin-top:0.25rem">
                    <?php if ($picksUntilMyTurn === null): ?>
                        You have no more picks left in this draft.
                    <?php elseif ($picksUntilMyTurn === 1): ?>
                        <strong>You're up next!</strong> (your pick is #<?= (int) $myNextOverall ?>)
                    <?php else: ?>
                        Your next pick: <strong><?= (int) $picksUntilMyTurn ?> picks away</strong>
                        (pick #<?= (int) $myNextOverall ?>, round <?= (int) $myNextRound ?>)
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($myTeam !== null && $poolOpen): ?>
    <h2>My roster so far</h2>
    <div class="needs">
        <?php foreach (['QB', 'RB', 'WR', 'TE', 'K', 'DEF'] as $pos): ?>
            <?php
            $have = (int) ($myRosterCounts[$pos] ?? 0);
            $need = (int) ($rosterShape[$pos] ?? 0);
            $cls = $need === 0 ? '' : ($have >= $need ? 'met' : 'open');
            ?>
            <span class="need-pill <?= $cls ?>"><?= e($pos) ?>: <?= $have ?><?= $need > 0 ? ' / ' . $need : '' ?></span>
        <?php endforeach; ?>
        <?php if ((int) ($rosterShape['FLEX'] ?? 0) > 0): ?>
            <span class="need-pill">FLEX: <?= (int) $rosterShape['FLEX'] ?></span>
        <?php endif; ?>
        <?php if ((int) ($rosterShape['BENCH'] ?? 0) > 0): ?>
            <span class="need-pill">BENCH: <?= (int) $rosterShape['BENCH'] ?></span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($showPool): ?>
    <h2>Available players</h2>

    <div class="pool-filters">
        <a class="pool-chip<?= $filterPos === '' ? ' active' : '' ?>" href="<?= e($filterUrl('', $filterQ)) ?>">All</a>
        <?php foreach ($filterPositions as $pos): ?>
            <a class="pool-chip<?= $filterPos === $pos ? ' active' : '' ?>" href="<?= e($filterUrl($pos, $filterQ)) ?>"><?= e($pos) ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="/draft" class="pool-filters" role="search">
        <?php if ($filterPos !== ''): ?><input type="hidden" name="pos" value="<?= e($filterPos) ?>"><?php endif; ?>
        <input type="search" name="q" id="pool-search" value="<?= e($filterQ) ?>"
               placeholder="Search by player or team (e.g. Mahomes, SF)…" autocomplete="off">
        <button type="submit">Search</button>
        <?php if ($filterQ !== ''): ?><a class="pool-chip" href="<?= e($filterUrl($filterPos, '')) ?>">Clear</a><?php endif; ?>
    </form>

    <?php if ($available === []): ?>
        <p>No available players match
            <?= $filterPos !== '' ? 'position ' . e($filterPos) : 'that' ?><?= $filterQ !== '' ? ' and "' . e($filterQ) . '"' : '' ?>.</p>
    <?php else: ?>
        <div class="pool-scroll">
            <form method="post">
                <?php if ($fixing): ?>
                    <?php // The correct-pick endpoint needs the overall pick being fixed. ?>
                    <input type="hidden" name="overall_pick" value="<?= (int) $fixOverall ?>">
                <?php endif; ?>
                <table class="pool-table">
                    <thead><tr><th>Rank</th><th>Player</th><th>Pos</th><th>Team</th><th>Bye</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($available as $p): $pid = (string) $p['sleeper_id']; ?>
                        <tr>
                            <td><?= $p['search_rank'] !== null ? (int) $p['search_rank'] : '—' ?></td>
                            <td><?= e((string) $p['full_name']) ?><?= $statusFlag($p['status'] ?? null) ?></td>
                            <td><?= e((string) $p['position']) ?></td>
                            <td><?= $p['nfl_team'] !== null ? e((string) $p['nfl_team']) : '—' ?></td>
                            <td><?= ($p['bye_week'] ?? null) !== null ? (int) $p['bye_week'] : '—' ?></td>
                            <td class="actions">
                                <?php if ($fixing): ?>
                                    <button formaction="/admin/draft/correct-pick" name="player_id" value="<?= e($pid) ?>"
                                            title="Set pick #<?= (int) $fixOverall ?> to this player">Assign to #<?= (int) $fixOverall ?></button>
                                <?php else: ?>
                                    <?php if ($myTurn): ?>
                                        <button class="pool-draft" data-pos="<?= e((string) $p['position']) ?>"
                                                formaction="/draft/pick" name="player_id" value="<?= e($pid) ?>">Draft</button>
                                    <?php endif; ?>
                                    <?php if ($myTeam !== null): ?>
                                        <button formaction="/draft/queue/add" name="player_id" value="<?= e($pid) ?>">+ Queue</button>
                                    <?php endif; ?>
                                    <?php if ($isCommissioner && $state === 'live' && $onClockTeamId !== null): ?>
                                        <button formaction="/admin/draft/pick-on-behalf" name="player_id" value="<?= e($pid) ?>"
                                                title="Draft for <?= e($onClockName) ?>">Draft for <?= e($onClockName) ?></button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <p style="font-size:0.85em; color:#666"><?= count($available) ?> available, best first. Use a position filter or search (by player name or team) to narrow it down.</p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($myTeam !== null && $poolOpen): ?>
    <h2>My queue</h2>
    <?php if ($myQueue === []): ?>
        <p>Your queue is empty. Add players from the list above — they drive your auto-pick if your timer runs out.</p>
    <?php else: ?>
        <p style="font-size:0.85em; color:#666">In order — the top player is drafted for you first if your timer runs out. Use ▲/▼ to reprioritize.</p>
        <?php $queueIds = array_map(static fn ($q) => (string) $q['player_id'], $myQueue); $queueCount = count($queueIds); ?>
        <ol class="queue-list">
            <?php foreach ($myQueue as $i => $q): ?>
                <li>
                    <?= e((string) $q['full_name']) ?> (<?= e((string) $q['position']) ?><?= ($q['bye_week'] ?? null) !== null ? ', bye ' . (int) $q['bye_week'] : '' ?>)<?= $statusFlag($q['status'] ?? null) ?>
                    <span class="queue-actions">
                        <?php if ($i > 0): ?>
                            <?php $up = $queueIds; [$up[$i - 1], $up[$i]] = [$up[$i], $up[$i - 1]]; ?>
                            <form method="post" action="/draft/queue/reorder">
                                <?php foreach ($up as $id): ?><input type="hidden" name="player_ids[]" value="<?= e($id) ?>"><?php endforeach; ?>
                                <button type="submit" title="Move up" aria-label="Move up">&#9650;</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($i < $queueCount - 1): ?>
                            <?php $down = $queueIds; [$down[$i + 1], $down[$i]] = [$down[$i], $down[$i + 1]]; ?>
                            <form method="post" action="/draft/queue/reorder">
                                <?php foreach ($down as $id): ?><input type="hidden" name="player_ids[]" value="<?= e($id) ?>"><?php endforeach; ?>
                                <button type="submit" title="Move down" aria-label="Move down">&#9660;</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/draft/queue/remove">
                            <input type="hidden" name="player_id" value="<?= e((string) $q['player_id']) ?>">
                            <button type="submit">Remove</button>
                        </form>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
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
        <form method="post" action="/admin/draft/abort" style="display:inline"
              onsubmit="return confirm('Stop the draft now? Picks made so far are kept, but no more picks can happen.') &amp;&amp; confirm('Are you sure you want to stop the draft?')">
            <button type="submit">Stop draft</button>
        </form>
        <form method="post" action="/admin/draft/reset" style="display:inline"
              onsubmit="return confirm('Really reset the whole draft? This wipes every pick.') &amp;&amp; confirm('Are you absolutely sure?')">
            <button type="submit">Reset draft</button>
        </form>
    </p>

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
    <?php
    $canFix = $isCommissioner && in_array($state, ['live', 'paused'], true);
    $currentNo = (int) ($draft['current_pick_no'] ?? 0);
    ?>
    <h2>Board</h2>
    <?php if ($draftOrder !== []): ?>
        <?php
        // Round × team grid: columns follow the draft order, each cell is that
        // team's pick in that round. Built from the flat board indexed by
        // [round][team_id].
        $cells = [];
        $maxRound = 0;
        foreach ($board as $row) {
            $cells[(int) $row['round']][(int) $row['team_id']] = $row;
            $maxRound = max($maxRound, (int) $row['round']);
        }
        ?>
        <div class="grid-scroll">
            <table class="draft-grid">
                <thead>
                    <tr>
                        <th class="round-col">Rd</th>
                        <?php foreach ($draftOrder as $col): $tid = (int) $col['team_id']; ?>
                            <th>
                                <?= $presenceDot($tid) ?> <?= e((string) $col['team_name']) ?><?= $autoBadge($tid) ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php for ($rd = 1; $rd <= $maxRound; $rd++): ?>
                    <tr>
                        <td class="round-col"><?= $rd ?></td>
                        <?php foreach ($draftOrder as $col): $tid = (int) $col['team_id']; $cell = $cells[$rd][$tid] ?? null; ?>
                            <?php
                            $isPicked = $cell !== null && $cell['player_id'] !== null;
                            $isOnClock = $cell !== null && (int) $cell['overall_pick'] === $currentNo && $state === 'live';
                            $cls = $isOnClock ? 'onclock' : ($isPicked ? 'filled' : 'empty');
                            ?>
                            <td class="<?= $cls ?>">
                                <?php if ($isPicked): ?>
                                    <?= e((string) $cell['player_name']) ?>
                                    <span class="cell-pos"><?= e((string) $cell['position']) ?></span>
                                    <?php if ($canFix): ?>
                                        <div class="cell-fix"><a href="/draft?fix=<?= (int) $cell['overall_pick'] ?>">Fix</a></div>
                                    <?php endif; ?>
                                <?php elseif ($cell !== null): ?>
                                    <?= $isOnClock ? '&#9201; on the clock' : '&mdash;' ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <?php // Fallback flat list if the draft order isn't available. ?>
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
<?php endif; ?>

<script>
(function () {
    // Live pick-clock countdown(s), ticking from the server's remaining seconds.
    Array.prototype.forEach.call(document.querySelectorAll('.draft-clock[data-seconds-left]'), function (clock) {
        var left = parseInt(clock.getAttribute('data-seconds-left'), 10) || 0;
        var render = function () {
            if (left <= 0) { clock.textContent = "Time's up"; clock.classList.add('low'); return; }
            var m = Math.floor(left / 60), s = left % 60;
            clock.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            clock.classList.toggle('low', left <= 10);
        };
        render();
        setInterval(function () { if (left > 0) { left--; render(); } }, 1000);
    });

    // Auto-refresh the room, but never interrupt someone mid-action: if any form
    // control is focused (searching, or about to click Draft/Queue/a control),
    // wait for the next tick instead of reloading.
    var poll = window.FFB_DRAFT_POLL;
    if (poll) {
        var tick = function () {
            var ae = document.activeElement;
            if (ae && /^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(ae.tagName)) {
                setTimeout(tick, poll);
                return;
            }
            location.reload();
        };
        setTimeout(tick, poll);
    }

    // Soft over-draft warning: nudge before drafting another QB/K/DEF the roster
    // already has enough of. Those positions don't fill FLEX, so extra ones are
    // usually a wasted pick — the classic "kid drafts three kickers" mistake.
    // RB/WR/TE are never warned (depth there feeds FLEX and the bench).
    var roster = window.FFB_ROSTER || { counts: {}, shape: {} };
    var singleSlot = { QB: 1, K: 1, DEF: 1 };
    Array.prototype.forEach.call(document.querySelectorAll('button.pool-draft'), function (btn) {
        btn.addEventListener('click', function (e) {
            var pos = btn.getAttribute('data-pos');
            if (!(pos in singleSlot)) { return; }
            var have = (roster.counts && roster.counts[pos]) || 0;
            var need = (roster.shape && roster.shape[pos]) || 0;
            if (need > 0 && have >= need) {
                var word = pos === 'DEF' ? 'defenses' : pos + 's';
                if (!window.confirm('You already have ' + have + ' ' + word + ' (you only start ' + need + '). Draft another ' + pos + ' anyway?')) {
                    e.preventDefault();
                }
            }
        });
    });

    // "It's your turn" alert. The tab-title flash is the dependable signal — it
    // works even in a background tab, and needs no prior click. The beep and
    // vibrate are best-effort: browsers may block them until the page has been
    // interacted with, so they're a bonus, not the primary cue. The beep fires at
    // most once per pick (tracked in sessionStorage) so the auto-reload doesn't
    // re-trigger it every few seconds.
    if (window.FFB_MY_TURN) {
        var baseTitle = document.title;
        var flashed = false;
        setInterval(function () {
            flashed = !flashed;
            document.title = flashed ? '⏰ YOUR PICK!' : baseTitle;
        }, 1000);

        var pickKey = 'ffb-alerted-pick-' + window.FFB_PICK_NO;
        var alreadyAlerted = false;
        try { alreadyAlerted = sessionStorage.getItem(pickKey) === '1'; } catch (e) {}
        if (!alreadyAlerted) {
            try { sessionStorage.setItem(pickKey, '1'); } catch (e) {}
            try { if (navigator.vibrate) { navigator.vibrate([200, 100, 200]); } } catch (e) {}
            try {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (Ctx) {
                    var ctx = new Ctx();
                    var beep = function (freq, start, dur) {
                        var osc = ctx.createOscillator(), gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.value = freq;
                        gain.gain.setValueAtTime(0.0001, ctx.currentTime + start);
                        gain.gain.exponentialRampToValueAtTime(0.3, ctx.currentTime + start + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + dur);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(ctx.currentTime + start);
                        osc.stop(ctx.currentTime + start + dur);
                    };
                    beep(880, 0, 0.25);
                    beep(1175, 0.3, 0.3);
                }
            } catch (e) {}
        }
    }
}());
</script>
