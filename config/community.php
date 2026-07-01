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
        // Catalog-quality contributions (admin-approved).
        'edit_suggestion' => (int) env('POINTS_EDIT_SUGGESTION', 10),
        'missing_card' => (int) env('POINTS_MISSING_CARD', 20),
        'missing_set' => (int) env('POINTS_MISSING_SET', 40),
        // Engagement + onboarding (auto-awarded, abuse-resistant).
        'scan_feedback' => (int) env('POINTS_SCAN_FEEDBACK', 2),
        'daily_checkin' => (int) env('POINTS_DAILY_CHECKIN', 2),
        'streak_bonus' => (int) env('POINTS_STREAK_BONUS', 10),
        'profile_complete' => (int) env('POINTS_PROFILE_COMPLETE', 15),
        'first_scan' => (int) env('POINTS_FIRST_SCAN', 5),
        'first_collection_card' => (int) env('POINTS_FIRST_COLLECTION_CARD', 5),
        'first_public_collection' => (int) env('POINTS_FIRST_PUBLIC_COLLECTION', 5),
        'referral' => (int) env('POINTS_REFERRAL', 25),
    ],

    // A 7-day check-in streak awards the streak bonus on top of the daily points.
    'streak_bonus_every' => (int) env('COMMUNITY_STREAK_BONUS_EVERY', 7),

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
