<?php

declare(strict_types=1);

namespace FFB\Playoffs;

/**
 * The pure math of a fixed single-elimination bracket with standard seed
 * placement. The tree is never stored — it is derived from the field size by
 * these functions (see ADR-0011). Seeds are 1-based; when the field isn't a
 * power of two, the top seeds get first-round byes (a bye = being paired with a
 * "phantom" seed beyond the field). No I/O.
 */
final class Bracket
{
    /** The smallest power of two >= n (n >= 1). */
    public static function nextPowerOfTwo(int $n): int
    {
        $p = 1;
        while ($p < $n) {
            $p *= 2;
        }

        return $p;
    }

    public static function byeCount(int $fieldSize): int
    {
        return self::nextPowerOfTwo($fieldSize) - $fieldSize;
    }

    /** Total rounds to a champion (1 for a 2-team field, 3 for 5–8, …). */
    public static function roundCount(int $fieldSize): int
    {
        $rounds = 0;
        for ($b = self::nextPowerOfTwo($fieldSize); $b > 1; $b = intdiv($b, 2)) {
            $rounds++;
        }

        return $rounds;
    }

    /**
     * The standard bracket slot order for a field, length nextPowerOfTwo(field).
     * Seeds greater than the field size are phantoms (bye markers). Built by the
     * classic recursive doubling so seed 1 is maximally protected.
     *
     * @return list<int>
     */
    public static function seedSlots(int $fieldSize): array
    {
        $size = self::nextPowerOfTwo($fieldSize);
        $slots = [1];
        while (count($slots) < $size) {
            $roundSize = count($slots) * 2;
            $next = [];
            foreach ($slots as $seed) {
                $next[] = $seed;
                $next[] = $roundSize + 1 - $seed;
            }
            $slots = $next;
        }

        return $slots;
    }

    /**
     * Consecutive slot pairs [highSeed, lowSeed]; a lowSeed beyond the field is a
     * phantom (its partner gets a bye). Always highSeed < lowSeed.
     *
     * @return list<array{0:int,1:int}>
     */
    public static function firstRoundPairings(int $fieldSize): array
    {
        $slots = self::seedSlots($fieldSize);
        $pairs = [];
        for ($i = 0; $i < count($slots); $i += 2) {
            $a = $slots[$i];
            $b = $slots[$i + 1];
            $pairs[] = $a < $b ? [$a, $b] : [$b, $a];
        }

        return $pairs;
    }

    /**
     * The actual first-round games — pairings where both seeds are in the field.
     * `high` is the higher seed (home), `low` the lower seed (away).
     *
     * @return list<array{high:int,low:int}>
     */
    public static function firstRoundGames(int $fieldSize): array
    {
        $games = [];
        foreach (self::firstRoundPairings($fieldSize) as [$high, $low]) {
            if ($low <= $fieldSize) {
                $games[] = ['high' => $high, 'low' => $low];
            }
        }

        return $games;
    }

    /**
     * Seeds that get a first-round bye (paired with a phantom), ascending.
     *
     * @return list<int>
     */
    public static function firstRoundByes(int $fieldSize): array
    {
        $byes = [];
        foreach (self::firstRoundPairings($fieldSize) as [$high, $low]) {
            if ($low > $fieldSize) {
                $byes[] = $high;
            }
        }
        sort($byes);

        return $byes;
    }
}
