<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Points per accepted contribution
    |--------------------------------------------------------------------------
    | Awarded when a contribution is accepted (an edit approved, a missing
    | card/set report accepted). Keyed by App\Enums\ContributionType value.
    */
    'points' => [
        'edit_suggestion' => (int) env('POINTS_EDIT_SUGGESTION', 10),
        'missing_card' => (int) env('POINTS_MISSING_CARD', 20),
        'missing_set' => (int) env('POINTS_MISSING_SET', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Levels (by lifetime points)
    |--------------------------------------------------------------------------
    | Ascending. A user's level is the highest tier whose `min` they've reached.
    | Keep the first tier at 0 so everyone has a level.
    */
    'levels' => [
        ['min' => 0, 'name' => 'Rookie'],
        ['min' => 50, 'name' => 'Collector'],
        ['min' => 150, 'name' => 'Curator'],
        ['min' => 400, 'name' => 'Archivist'],
        ['min' => 1000, 'name' => 'Historian'],
        ['min' => 2500, 'name' => 'Legend'],
    ],

    // How many users the public leaderboard shows per board.
    'leaderboard_size' => (int) env('COMMUNITY_LEADERBOARD_SIZE', 25),

];
