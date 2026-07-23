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
        // Laravel's postmark mail transport reads `token` (the Postmark Server
        // API token). Optional message_stream_id targets a specific stream.
        'token' => env('POSTMARK_API_KEY'),
        'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
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

    // Oxylabs Web Scraper API — used to pull eBay sold comps + Amazon stock alerts.
    'oxylabs' => [
        'username' => env('OXYLABS_USERNAME'),
        'password' => env('OXYLABS_PASSWORD'),
        'endpoint' => env('OXYLABS_ENDPOINT', 'https://realtime.oxylabs.io/v1/queries'),
    ],

    // X (Twitter) API — posts stock-alert tweets. Posting needs OAuth 1.0a
    // user context (a bearer token is app-only and can't post), so the bot
    // account's access token + secret are required alongside the app keys.
    'x' => [
        'consumer_key' => env('X_CONSUMER_KEY'),
        'consumer_secret' => env('X_SECRET_KEY'),
        'access_token' => env('X_ACCESS_TOKEN'),
        'access_token_secret' => env('X_ACCESS_TOKEN_SECRET'),
        'bearer_token' => env('X_BEARER_TOKEN'),
    ],

    // Optional Amazon Associates tag appended to stock-alert product links.
    'amazon' => [
        'associate_tag' => env('AMAZON_ASSOCIATE_TAG'),
    ],

    // PriceCharting Legendary — full price-guide CSV (catalog reconciliation).
    'pricecharting' => [
        'token' => env('PRICECHARTING_API_KEY'),
        'base_url' => env('PRICECHARTING_BASE_URL', 'https://www.pricecharting.com'),
    ],

    // Anthropic Messages API — Claude vision for card scanning (Phase 4b).
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'endpoint' => env('ANTHROPIC_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'model' => env('SCAN_MODEL', 'claude-sonnet-4-6'),
    ],

    // Dodo Payments — subscriptions + credit packs (merchant of record).
    'dodo' => [
        'key' => env('DODO_API_KEY', env('DODO_PAYMENTS_API_KEY')),
        'base_url' => env('DODO_BASE_URL', 'https://test.dodopayments.com'),
        'webhook_secret' => env('DODO_WEBHOOK_SECRET'),
        // One subscription product per paid tier.
        'collector_product_id' => env('DODO_PRODUCT_ID_COLLECTOR'),
        'guru_product_id' => env('DODO_PRODUCT_ID_GURU'),
        // Back-compat: the original single product is the Collector tier.
        'premium_product_id' => env('DODO_PRODUCT_ID_COLLECTOR', env('DODO_PREMIUM_PRODUCT_ID')),
        // Subscription product id → tier (for the webhook).
        'product_tiers' => array_filter([
            (string) env('DODO_PRODUCT_ID_COLLECTOR') => 'collector',
            (string) env('DODO_PRODUCT_ID_GURU') => 'guru',
        ], fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY),
    ],

    // pokemontcg.io — catalog + TCGplayer price source for set imports.
    'pokemontcg' => [
        'key' => env('POKEMONTCG_API_KEY'),
        'base_url' => env('POKEMONTCG_BASE_URL', 'https://api.pokemontcg.io/v2'),
    ],

    // TCGCSV — free public JSON mirror of TCGplayer. Source for Japanese Pokémon
    // (category 85), which pokemontcg.io doesn't carry.
    'tcgcsv' => [
        'base_url' => env('TCGCSV_BASE_URL', 'https://tcgcsv.com'),
    ],

    // lorcana-api.com — free, open card data for Disney Lorcana (no prices;
    // valuations come from eBay via Oxylabs like every other product line). The
    // `bulk/cards` endpoint returns all cards in one call, refreshed twice daily.
    'lorcana' => [
        'base_url' => env('LORCANA_BASE_URL', 'https://api.lorcana-api.com'),
    ],

    // eBay Browse API (live active / buy-it-now listings) + Partner Network.
    // Used for card-page listings (GetCardListings) and for-sale valuation
    // (IngestForSaleListings). Prefer EBAY_CLIENT_ID/SECRET when both set;
    // otherwise EBAY_PRODUCTION_* or EBAY_SANDBOX_* based on EBAY_ENV.
    // Affiliate: EBAY_CAMPAIGN_ID tags Browse item URLs + "Shop on eBay" links.
    'ebay' => [
        'env' => env('EBAY_ENV', 'production'),
        'client_id' => (static function () {
            $id = env('EBAY_CLIENT_ID');
            $secret = env('EBAY_CLIENT_SECRET');
            if (! empty($id) && ! empty($secret)) {
                return $id;
            }

            return env('EBAY_ENV', 'production') === 'sandbox'
                ? env('EBAY_SANDBOX_CLIENT_ID')
                : env('EBAY_PRODUCTION_CLIENT_ID');
        })(),
        'client_secret' => (static function () {
            $id = env('EBAY_CLIENT_ID');
            $secret = env('EBAY_CLIENT_SECRET');
            if (! empty($id) && ! empty($secret)) {
                return $secret;
            }

            return env('EBAY_ENV', 'production') === 'sandbox'
                ? env('EBAY_SANDBOX_CLIENT_SECRET')
                : env('EBAY_PRODUCTION_CLIENT_SECRET');
        })(),
        'campaign_id' => env('EBAY_CAMPAIGN_ID'),
        'marketplace_id' => env('EBAY_MARKETPLACE_ID', 'EBAY_US'),
        'base_url' => env(
            'EBAY_BASE_URL',
            env('EBAY_ENV', 'production') === 'sandbox'
                ? 'https://api.sandbox.ebay.com'
                : 'https://api.ebay.com',
        ),
        // EPN rotation id for the eBay US marketplace (affiliate search links).
        'rover_id' => env('EBAY_ROVER_ID', '711-53200-19255-0'),
        // Marketplace Account Deletion notifications (required for production keys).
        // Token: 32–80 chars; must match the value saved in the eBay developer portal.
        // Endpoint: public HTTPS URL registered there (no trailing slash).
        'marketplace_deletion_token' => env('EBAY_MARKETPLACE_DELETION_TOKEN'),
        'marketplace_deletion_endpoint' => env(
            'EBAY_MARKETPLACE_DELETION_ENDPOINT',
            rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/webhooks/ebay/marketplace-account-deletion',
        ),
    ],

    // TCGplayer affiliate (search links). Partner id is optional — without it
    // the link is a plain TCGplayer search.
    'tcgplayer' => [
        'partner' => env('TCGPLAYER_PARTNER'),
    ],

    // Social login (Laravel Socialite). The callback URL is set per-request in
    // SocialiteController so it always matches the current host; `redirect` here
    // is just a fallback. Google keys are optional — wire them to enable Google.
    'facebook' => [
        'client_id' => env('FACEBOOK_APP_ID'),
        'client_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
