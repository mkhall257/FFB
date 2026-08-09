<?php
/**
 * Weekly scoreboard — broadcast comparison (content only; wrapped by layout_app).
 * Consumes the MatchupDetailService read-model. Expects:
 *
 * @var int $week
 * @var list<array{
 *   id:int, status:string,
 *   home:array{team_id:int,name:string,record:string,total:float,score:?float,starters:list<array<string,mixed>>},
 *   away:array{team_id:int,name:string,record:string,total:float,score:?float,starters:list<array<string,mixed>>}
 * }> $matchups
 * @var array<string,string> $stateLabels
 */

/** Two-letter initials avatar for a player/team name. */
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $s = '';
    foreach ($parts as $p) {
        $p = ltrim($p, '.');
        if ($p !== '') {
            $s .= strtoupper($p[0]);
        }
        if (strlen($s) >= 2) {
            break;
        }
    }
    return $s === '' ? '?' : $s;
};

/** Map a Sleeper injury status to a comparison pill [label, css]. Healthy/unknown → ACTIVE. */
$pill = static function (string $status): array {
    $s = strtolower($status);
    if (str_contains($s, 'out') || str_contains($s, 'ir') || str_contains($s, 'susp')) {
        return ['OUT', 'pill-out'];
    }
    if (str_contains($s, 'quest') || str_contains($s, 'doubt')) {
        return ['QUEST', 'pill-quest'];
    }
    return ['ACTIVE', 'pill-active'];
};

/** Headline value: the cached authoritative score when set, else the per-starter sum. */
$val = static fn (array $side): float => $side['score'] !== null ? (float) $side['score'] : (float) $side['total'];

$crests = ['home' => '/assets/img/crest-shadow.png', 'away' => '/assets/img/crest-roaring.png'];
?>
<div class="mu-week">
    <div><b>Week <?= (int) $week ?></b><span>Head-to-Head</span></div>
</div>

<div style="display:flex;justify-content:space-between;gap:8px;margin:8px 0 4px">
    <?php if ($week > 1): ?>
        <a class="btn btn--ghost" href="/scoreboard?week=<?= $week - 1 ?>">← Week <?= $week - 1 ?></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <a class="btn btn--ghost" href="/scoreboard?week=<?= $week + 1 ?>">Week <?= $week + 1 ?> →</a>
</div>

<?php if ($matchups === []): ?>
    <section class="panel mu-empty">No matchups scheduled for this week.</section>
<?php else: ?>
    <?php foreach ($matchups as $m): ?>
        <?php
        $status = (string) $m['status'];
        $stateLabel = $stateLabels[$status] ?? ucfirst($status);
        $home = $m['home'];
        $away = $m['away'];
        $hv = $val($home);
        $av = $val($away);
        $sum = $hv + $av;
        $share = $sum > 0 ? (int) round($hv / $sum * 100) : 50;
        $starters = max(count($home['starters']), count($away['starters']));
        // Pair home/away starters positionally (both are slot-sorted by the read-model).
        $rows = [];
        for ($i = 0; $i < $starters; $i++) {
            $rows[] = [$home['starters'][$i] ?? null, $away['starters'][$i] ?? null];
        }
        ?>
        <section class="panel panel--pad" style="margin-bottom:22px">
            <div class="mu-head">
                <div class="mu-team">
                    <img class="mu-crest" src="<?= $crests['home'] ?>" alt="">
                    <h2><?= e($home['name']) ?></h2>
                    <div class="mu-rec"><b><?= e($home['record'] !== '' ? $home['record'] : '—') ?></b></div>
                </div>
                <div class="mu-vs">
                    <svg viewBox="0 0 60 100"><defs><linearGradient id="bolt" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#30db73"/><stop offset="1" stop-color="#0b8f46"/></linearGradient></defs><path d="M34 2 L8 56 L27 56 L20 98 L54 38 L33 38 Z" fill="url(#bolt)" opacity=".55"/></svg>
                    <b>VS</b>
                </div>
                <div class="mu-team">
                    <img class="mu-crest" src="<?= $crests['away'] ?>" alt="">
                    <h2><?= e($away['name']) ?></h2>
                    <div class="mu-rec"><b><?= e($away['record'] !== '' ? $away['record'] : '—') ?></b></div>
                </div>
            </div>

            <div class="mu-live">
                <div class="mu-score"><b><?= number_format($hv, 2) ?></b><span><?= e($stateLabel) ?></span></div>
                <div class="mu-barwrap">
                    <div class="mu-bar"><i class="l" style="width:<?= $share ?>%"></i><i class="r" style="width:<?= 100 - $share ?>%"></i></div>
                    <img class="mu-medal l" src="<?= $crests['home'] ?>" alt="">
                    <img class="mu-medal r" src="<?= $crests['away'] ?>" alt="">
                    <div class="mu-remain">
                        <span><?= count($home['starters']) ?> Starters</span>
                        <span><?= count($away['starters']) ?> Starters</span>
                    </div>
                </div>
                <div class="mu-score"><b><?= number_format($av, 2) ?></b><span><?= e($stateLabel) ?></span></div>
            </div>

            <?php if ($rows === []): ?>
                <div class="mu-empty">Lineups not set.</div>
            <?php else: ?>
                <div class="mu-list">
                <?php foreach ($rows as [$h, $a]): ?>
                    <?php
                    $slot = (string) ($h['slot'] ?? $a['slot'] ?? '');
                    ?>
                    <div class="mu-row">
                        <?php if ($h !== null): [$hL, $hC] = $pill((string) $h['status']); ?>
                            <div class="mu-av"><?= e($initials((string) $h['name'])) ?><span class="mu-pill <?= $hC ?>"><?= $hL ?></span></div>
                            <div class="mu-meta">
                                <div class="nm"><?= e((string) $h['name']) ?></div>
                                <div class="sub"><?= e((string) $h['nfl_team']) ?></div>
                            </div>
                            <div class="mu-pts"><?= number_format((float) $h['points'], 1) ?></div>
                        <?php else: ?>
                            <div class="mu-av">—</div><div class="mu-meta"></div><div class="mu-pts">—</div>
                        <?php endif; ?>

                        <div class="mu-pos"><?= e($slot) ?></div>

                        <?php if ($a !== null): [$aL, $aC] = $pill((string) $a['status']); ?>
                            <div class="mu-pts"><?= number_format((float) $a['points'], 1) ?></div>
                            <div class="mu-meta" style="text-align:right">
                                <div class="nm"><?= e((string) $a['name']) ?></div>
                                <div class="sub"><?= e((string) $a['nfl_team']) ?></div>
                            </div>
                            <div class="mu-av"><?= e($initials((string) $a['name'])) ?><span class="mu-pill <?= $aC ?>"><?= $aL ?></span></div>
                        <?php else: ?>
                            <div class="mu-pts">—</div><div class="mu-meta"></div><div class="mu-av">—</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
