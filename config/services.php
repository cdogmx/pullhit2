<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Oxylabs Web Scraper API — used to pull eBay sold comps.
    'oxylabs' => [
        'username' => env('OXYLABS_USERNAME'),
        'password' => env('OXYLABS_PASSWORD'),
        'endpoint' => env('OXYLABS_ENDPOINT', 'https://realtime.oxylabs.io/v1/queries'),
    ],

    // Anthropic Messages API — Claude vision for card scanning (Phase 4b).
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'endpoint' => env('ANTHROPIC_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'model' => env('SCAN_MODEL', 'claude-sonnet-4-6'),
    ],

    // Dodo Payments — premium subscriptions (merchant of record).
    'dodo' => [
        'key' => env('DODO_API_KEY'),
        'base_url' => env('DODO_BASE_URL', 'https://test.dodopayments.com'),
        'webhook_secret' => env('DODO_WEBHOOK_SECRET'),
        'premium_product_id' => env('DODO_PREMIUM_PRODUCT_ID'),
    ],

    // pokemontcg.io — catalog + TCGplayer price source for set imports.
    'pokemontcg' => [
        'key' => env('POKEMONTCG_API_KEY'),
        'base_url' => env('POKEMONTCG_BASE_URL', 'https://api.pokemontcg.io/v2'),
    ],

    // eBay Browse API (live active listings) + eBay Partner Network affiliate.
    // The affiliate "Shop on eBay" link needs only campaign_id; inline live
    // listings additionally need client_id/secret (OAuth). Degrades gracefully.
    'ebay' => [
        'client_id' => env('EBAY_CLIENT_ID'),
        'client_secret' => env('EBAY_CLIENT_SECRET'),
        'campaign_id' => env('EBAY_CAMPAIGN_ID'),
        'marketplace_id' => env('EBAY_MARKETPLACE_ID', 'EBAY_US'),
        'base_url' => env('EBAY_BASE_URL', 'https://api.ebay.com'),
        // EPN rotation id for the eBay US marketplace (affiliate search links).
        'rover_id' => env('EBAY_ROVER_ID', '711-53200-19255-0'),
    ],

    // TCGplayer affiliate (search links). Partner id is optional — without it
    // the link is a plain TCGplayer search.
    'tcgplayer' => [
        'partner' => env('TCGPLAYER_PARTNER'),
    ],

];
