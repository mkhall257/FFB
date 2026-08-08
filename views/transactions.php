<?php
/**
 * League-wide activity feed. Expects:
 *
 * @var list<array<string,mixed>> $feed        headers with nested enriched items
 * @var bool $isCommissioner
 * @var ?string $flash
 * @var ?string $error
 */

/**
 * Render one Transaction as a plain-English sentence.
 *
 * @param array<string,mixed> $txn
 */
$describe = static function (array $txn): string {
    $items = $txn['items'] ?? [];

    if ($txn['type'] === 'add_drop') {
        $added = null;
        $dropped = null;
        $team = '';
        foreach ($items as $it) {
            if ($it['to_team_id'] !== null) {
                $added = (string) $it['player_name'];
                $team = (string) $it['to_team_name'];
            }
            if ($it['to_team_id'] === null && $it['from_team_id'] !== null) {
                $dropped = (string) $it['player_name'];
                $team = $team !== '' ? $team : (string) $it['from_team_name'];
            }
        }
        $parts = [];
        if ($added !== null) { $parts[] = 'added ' . $added; }
        if ($dropped !== null) { $parts[] = 'dropped ' . $dropped; }

        return trim($team . ' ' . implode(', ', $parts));
    }

    if ($txn['type'] === 'trade') {
        $moves = [];
        foreach ($items as $it) {
            $to = $it['to_team_name'] !== null ? (string) $it['to_team_name'] : 'free agents';
            $moves[] = (string) $it['player_name'] . ' to ' . $to;
        }

        return 'Trade: ' . implode('; ', $moves);
    }

    // commish_edit and any other type: a generic move description.
    $moves = [];
    foreach ($items as $it) {
        $from = $it['from_team_name'] !== null ? (string) $it['from_team_name'] : 'free agents';
        $to = $it['to_team_name'] !== null ? (string) $it['to_team_name'] : 'free agents';
        $moves[] = (string) $it['player_name'] . ' (' . $from . ' to ' . $to . ')';
    }

    return 'Commissioner edit: ' . implode('; ', $moves);
};
?>
<h1>League Activity</h1>
<p><a href="/">Home</a> · <a href="/players">Free Agents</a> · <a href="/trades">Trades</a></p>

<?php if ($error !== null): ?><p role="alert"><strong><?= e($error) ?></strong></p><?php endif; ?>
<?php if ($flash !== null): ?><p><?= e($flash) ?></p><?php endif; ?>

<?php if ($feed === []): ?>
    <p>No transactions yet.</p>
<?php else: ?>
    <ul>
    <?php foreach ($feed as $txn): ?>
        <li>
            <?= e($describe($txn)) ?>
            <?php if ($txn['status'] === 'reversed'): ?><em>(reversed)</em><?php endif; ?>
            <small><?= e((string) $txn['created_at']) ?></small>
            <?php if ($isCommissioner && $txn['status'] === 'applied'): ?>
                <form method="post" action="/admin/transactions/reverse" style="display:inline">
                    <input type="hidden" name="transaction_id" value="<?= e((string) $txn['id']) ?>">
                    <button type="submit">Reverse</button>
                </form>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
