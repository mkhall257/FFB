<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Players\FantasyProsDefenseRankings;
use PHPUnit\Framework\TestCase;

/**
 * Parsing the DynastyProcess/FantasyPros weekly CSV into a team => defense-rank
 * map: only DST rows, the JAC→JAX code normalization, and rank fidelity.
 */
final class FantasyProsDefenseRankingsTest extends TestCase
{
    public function testParsesDstRowsAndNormalizesJacksonville(): void
    {
        $csv = <<<CSV
"page","page_pos","pos","team","rank","ecr"
"dst","DST","DST","JAC",1,1.58
"dst","DST","DST","LAC",2,2.63
"ppr-wr","WR","WR","CIN",1,1.1
"dst","DST","DST","ARI",32,31.26
CSV;

        $map = FantasyProsDefenseRankings::parse($csv);

        // Only defenses, Jacksonville normalized to Sleeper's JAX, ranks kept.
        $this->assertSame(['JAX' => 1, 'LAC' => 2, 'ARI' => 32], $map);
        $this->assertArrayNotHasKey('CIN', $map, 'non-DST rows are ignored');
        $this->assertArrayNotHasKey('JAC', $map, 'FantasyPros code is normalized away');
    }

    public function testThrowsWhenColumnsAreMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        FantasyProsDefenseRankings::parse("page,team\n\"dst\",\"KC\"");
    }
}
