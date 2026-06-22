<?php

namespace App\Support\Community;

/**
 * Maps lifetime contribution points to a level (name + progress to the next),
 * from the config('community.levels') ladder.
 */
class Level
{
    /**
     * @return array{level: int, name: string, points: int, next_at: ?int, into_level: int, to_next: ?int}
     */
    public static function for(int $points): array
    {
        $tiers = (array) config('community.levels', [['min' => 0, 'name' => 'Rookie']]);

        $index = 0;
        foreach ($tiers as $i => $tier) {
            if ($points >= (int) $tier['min']) {
                $index = $i;
            }
        }

        $current = $tiers[$index];
        $next = $tiers[$index + 1] ?? null;

        return [
            'level' => $index + 1,
            'name' => (string) $current['name'],
            'points' => $points,
            'next_at' => $next ? (int) $next['min'] : null,
            'into_level' => $points - (int) $current['min'],
            'to_next' => $next ? (int) $next['min'] - $points : null,
        ];
    }
}
