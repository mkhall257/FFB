<?php

declare(strict_types=1);

namespace FFB\Schedule;

/**
 * Generates a regular-season Schedule for the League's Teams using the circle
 * method, then cycles the round-robin to fill the configured number of weeks.
 * An odd Team count introduces a rotating BYE placeholder; the Team paired with
 * BYE that week simply has no Matchup (a bye). Pure — no I/O.
 */
final class ScheduleGenerator
{
    private const BYE = 0;

    /**
     * @param list<int> $teamIds
     * @return list<array{week:int,home_team_id:int,away_team_id:int}>
     */
    public function generate(array $teamIds, int $weeks): array
    {
        $teams = array_values($teamIds);
        if (count($teams) < 2 || $weeks < 1) {
            return [];
        }
        if (count($teams) % 2 === 1) {
            $teams[] = self::BYE;
        }

        $n = count($teams);
        $rounds = $n - 1;          // distinct weeks in one round-robin
        $half = intdiv($n, 2);
        $rows = [];

        for ($week = 1; $week <= $weeks; $week++) {
            $round = ($week - 1) % $rounds;
            $cycle = intdiv($week - 1, $rounds); // which pass through the round-robin
            $arrangement = $this->rotate($teams, $round);

            for ($i = 0; $i < $half; $i++) {
                $a = $arrangement[$i];
                $b = $arrangement[$n - 1 - $i];
                if ($a === self::BYE || $b === self::BYE) {
                    continue; // bye
                }
                // Swap home/away each cycle so repeat pairings alternate venue.
                [$home, $away] = $cycle % 2 === 0 ? [$a, $b] : [$b, $a];
                $rows[] = ['week' => $week, 'home_team_id' => $home, 'away_team_id' => $away];
            }
        }

        return $rows;
    }

    /**
     * Circle-method rotation: team 0 is fixed, the rest rotate clockwise.
     *
     * @param list<int> $teams
     * @return list<int>
     */
    private function rotate(array $teams, int $round): array
    {
        $fixed = $teams[0];
        $rest = array_slice($teams, 1);
        $count = count($rest);
        $rotated = [];
        for ($i = 0; $i < $count; $i++) {
            $rotated[$i] = $rest[($i - $round % $count + $count) % $count];
        }

        return array_merge([$fixed], $rotated);
    }
}
