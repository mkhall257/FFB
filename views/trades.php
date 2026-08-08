<?php
/**
 * Trade surface. Expects:
 *
 * @var bool $hasTeam
 * @var list<array<string,mixed>> $incoming   proposals made TO me (accept/reject)
 * @var list<array<string,mixed>> $outgoing   proposals I made (cancel)
 * @var list<array<string,mixed>> $myRoster   my players (player_id, full_name, position)
 * @var list<array{id:int,name:string,roster:list<array<string,mixed>>}> $otherTeams
 * @var ?string $error
 * @var ?string $flash
 */

/**
 * Summarise a Trade's two sides for display.
 *
 * @param array<string,mixed> $txn
 */
$sides = static function (array $txn): string {
    $proposer = (int) $txn['proposed_by_team'];
    $give = [];
    $get = [];
    foreach ($txn['items'] ?? [] as $it) {
        if ((int) $it['from_team_id'] === $proposer) {
            $give[] = (string) $it['player_name'];
        } else {
            $get[] = (string) $it['player_name'];
        }
    }

    return implode(', ', $give) . '  for  ' . implode(', ', $get);
};
?>
<h1>Trades</h1>
<p><a href="/">Home</a> · <a href="/players">Free Agents</a> · <a href="/transactions">Activity</a></p>

<?php if ($error !== null): ?><p role="alert"><strong><?= e($error) ?></strong></p><?php endif; ?>
<?php if ($flash !== null): ?><p><?= e($flash) ?></p><?php endif; ?>

<?php if (!$hasTeam): ?>
    <p>You do not manage a team in this league.</p>
<?php else: ?>
    <h2>Incoming offers</h2>
    <?php if ($incoming === []): ?>
        <p>No incoming offers.</p>
    <?php else: ?>
        <ul>
        <?php foreach ($incoming as $t): ?>
            <li>
                <?= e($sides($t)) ?>
                <small>expires <?= e((string) $t['expires_at']) ?></small>
                <form method="post" action="/trades/accept" style="display:inline">
                    <input type="hidden" name="transaction_id" value="<?= e((string) $t['id']) ?>">
                    <button type="submit">Accept</button>
                </form>
                <form method="post" action="/trades/reject" style="display:inline">
                    <input type="hidden" name="transaction_id" value="<?= e((string) $t['id']) ?>">
                    <button type="submit">Reject</button>
                </form>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2>My outstanding offers</h2>
    <?php if ($outgoing === []): ?>
        <p>No outgoing offers.</p>
    <?php else: ?>
        <ul>
        <?php foreach ($outgoing as $t): ?>
            <li>
                <?= e($sides($t)) ?>
                <small>expires <?= e((string) $t['expires_at']) ?></small>
                <form method="post" action="/trades/cancel" style="display:inline">
                    <input type="hidden" name="transaction_id" value="<?= e((string) $t['id']) ?>">
                    <button type="submit">Cancel</button>
                </form>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2>Propose a trade</h2>
    <form method="post" action="/trades/propose">
        <p>
            <label>Trade with:
                <select name="target_team_id" required>
                    <option value="">— choose a team —</option>
                    <?php foreach ($otherTeams as $ot): ?>
                        <option value="<?= e((string) $ot['id']) ?>"><?= e($ot['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <fieldset>
            <legend>You give</legend>
            <?php foreach ($myRoster as $p): ?>
                <label>
                    <input type="checkbox" name="offered[]" value="<?= e((string) $p['player_id']) ?>">
                    <?= e((string) $p['full_name']) ?> (<?= e((string) $p['position']) ?>)
                </label><br>
            <?php endforeach; ?>
        </fieldset>
        <fieldset>
            <legend>You get</legend>
            <?php foreach ($otherTeams as $ot): ?>
                <strong><?= e($ot['name']) ?></strong><br>
                <?php foreach ($ot['roster'] as $p): ?>
                    <label>
                        <input type="checkbox" name="requested[]" value="<?= e((string) $p['player_id']) ?>">
                        <?= e((string) $p['full_name']) ?> (<?= e((string) $p['position']) ?>)
                    </label><br>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </fieldset>
        <button type="submit">Propose trade</button>
    </form>
<?php endif; ?>
