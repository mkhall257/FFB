<?php

declare(strict_types=1);

namespace FFB\Scoring;

/**
 * Turns a raw stat line into fantasy points using the League's key/value scoring
 * config (scoring.*). Pure and stateless. Linear stats multiply their value by
 * the matching scoring.<stat> weight; Defense points-allowed maps to a tier
 * value from scoring.def_pa_*.
 */
final class ScoringEngine
{
    /** Stat names that score linearly as value * scoring.<name>. */
    private const LINEAR = [
        'reception', 'pass_yard', 'pass_td', 'pass_int',
        'rush_yard', 'rush_td', 'rec_yard', 'rec_td', 'fumble_lost',
        'fg_made', 'xp_made',
        'def_sack', 'def_int', 'def_fumble_rec', 'def_td', 'def_safety',
    ];

    /**
     * @param array<string,float|int> $stats
     * @param array<string,string> $settings
     */
    public function pointsFor(array $stats, array $settings): float
    {
        $points = 0.0;

        foreach (self::LINEAR as $stat) {
            if (!isset($stats[$stat])) {
                continue;
            }
            $weight = (float) ($settings['scoring.' . $stat] ?? 0);
            $points += (float) $stats[$stat] * $weight;
        }

        if (isset($stats['def_points_allowed'])) {
            $points += $this->pointsAllowedTier((int) $stats['def_points_allowed'], $settings);
        }

        return round($points, 2);
    }

    /**
     * @param array<string,string> $settings
     */
    private function pointsAllowedTier(int $pointsAllowed, array $settings): float
    {
        $key = match (true) {
            $pointsAllowed <= 0  => 'scoring.def_pa_0',
            $pointsAllowed <= 6  => 'scoring.def_pa_1_6',
            $pointsAllowed <= 13 => 'scoring.def_pa_7_13',
            $pointsAllowed <= 20 => 'scoring.def_pa_14_20',
            $pointsAllowed <= 27 => 'scoring.def_pa_21_27',
            $pointsAllowed <= 34 => 'scoring.def_pa_28_34',
            default              => 'scoring.def_pa_35',
        };

        return (float) ($settings[$key] ?? 0);
    }
}
