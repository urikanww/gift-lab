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

    // 3D model source APIs (spec 6.5). When a token is present the live client
    // is used; otherwise the stub serves fixtures (AppServiceProvider decides).
    'thingiverse' => [
        'token' => env('THINGIVERSE_TOKEN'),
        'base_url' => env('THINGIVERSE_BASE_URL', 'https://api.thingiverse.com'),
    ],
    'cults3d' => [
        // Disabled for now: Cults3D has no public file-download API, so every
        // item lands as missing_model_file. Set CULTS3D_ENABLED=true (with
        // credentials) to re-enable the live client.
        'enabled' => env('CULTS3D_ENABLED', false),
        'username' => env('CULTS3D_USERNAME'),
        'token' => env('CULTS3D_TOKEN'),
        'base_url' => env('CULTS3D_BASE_URL', 'https://cults3d.com/graphql'),
    ],

    // Headless slicer (PrusaSlicer CLI). When a binary path is set, ingested
    // 3D models are sliced for real grams/print-minutes and auto-verified;
    // without it the manual staff verification flow applies.
    'slicer' => [
        'binary' => env('SLICER_BINARY', ''),
        'timeout' => env('SLICER_TIMEOUT', 300),
        // Used when the slicer profile carries no filament density (the
        // console default) - grams = volume [cm3] × density. PLA ≈ 1.24.
        'density_g_cm3' => env('SLICER_FILAMENT_DENSITY', 1.24),
        // OrcaSlicer CLI + Bambu H2S (O1S) profile. When ORCA_SLICER_BINARY is
        // set it supersedes the PrusaSlicer path: an STL is sliced against the
        // H2S profile bundle, the .gcode.3mf is persisted as production_file_ref,
        // and grams/minutes are read from the same pass. Empty => PrusaSlicer.
        'orca_binary' => env('ORCA_SLICER_BINARY', ''),
        'h2s_profile' => env('ORCA_H2S_PROFILE', ''),
    ],

    // LLM IP/trademark screen at catalogue ingest (layer 2; the keyword
    // blocklist always runs). Provider is selectable; whichever provider is
    // chosen, missing credentials degrade to blocklist-only.
    'ip_screen' => [
        'provider' => env('IP_SCREEN_PROVIDER', 'anthropic'), // anthropic | openai | ollama
    ],
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    ],
    'openai' => [
        'key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', ''),
        'model' => env('OLLAMA_MODEL', 'llama3.2'),
    ],

    // Shopee Affiliate Open API (SCRAPED_UV feed - permitted product data,
    // not scraping). When credentials are present the live client is bound.
    'shopee_affiliate' => [
        'app_id' => env('SHOPEE_AFFILIATE_APP_ID'),
        'secret' => env('SHOPEE_AFFILIATE_SECRET'),
        'base_url' => env('SHOPEE_AFFILIATE_BASE_URL', 'https://open-api.affiliate.shopee.sg/graphql'),
    ],

    // Lazada Open Platform affiliate feed (second SCRAPED_UV source). The
    // search/detail API paths are configurable - Lazada scopes endpoints per
    // affiliate program; confirm them in your program's API console.
    'lazada_affiliate' => [
        'app_key' => env('LAZADA_AFFILIATE_APP_KEY'),
        'secret' => env('LAZADA_AFFILIATE_SECRET'),
        'base_url' => env('LAZADA_AFFILIATE_BASE_URL', 'https://api.lazada.sg/rest'),
        'search_path' => env('LAZADA_AFFILIATE_SEARCH_PATH', '/marketing/product/search'),
        'item_path' => env('LAZADA_AFFILIATE_ITEM_PATH', '/marketing/product/detail'),
    ],

    // Stripe (B2C "pay now"). Without a secret key the fixture gateway is used.
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // NinjaVan courier (delivery dispatch). When client id + secret are present
    // the live HTTP client is bound; otherwise the fixture serves local/testing
    // (AppServiceProvider decides).
    'ninjavan' => [
        'client_id' => env('NINJAVAN_CLIENT_ID'),
        'client_secret' => env('NINJAVAN_CLIENT_SECRET'),
        // Full host + country segment, e.g. https://api-sandbox.ninjavan.co/sg.
        // Switch to the production host (https://api.ninjavan.co/sg) when going live.
        'base_url' => env('NINJAVAN_BASE_URL', 'https://api-sandbox.ninjavan.co/sg'),
        // Prefix for the merchant-supplied requested_tracking_number (1-9 chars,
        // no account prefix - NinjaVan prepends that). Lead days is the fallback
        // window for delivery_start_date when the quote has no needed_by date.
        'tracking_prefix' => env('NINJAVAN_TRACKING_PREFIX', 'GL'),
        'lead_days' => env('NINJAVAN_LEAD_DAYS', 2),
        'service_type' => env('NINJAVAN_SERVICE_TYPE', 'Parcel'),
        'service_level' => env('NINJAVAN_SERVICE_LEVEL', 'Standard'),
        'default_weight_kg' => env('NINJAVAN_DEFAULT_WEIGHT_KG', 1),
        'timezone' => env('NINJAVAN_TIMEZONE', 'Asia/Singapore'),
        'timeslot_start' => env('NINJAVAN_TIMESLOT_START', '09:00'),
        'timeslot_end' => env('NINJAVAN_TIMESLOT_END', '18:00'),
        'pickup' => [
            'name' => env('NINJAVAN_PICKUP_NAME', 'Gift Lab'),
            'phone' => env('NINJAVAN_PICKUP_PHONE'),
            'email' => env('NINJAVAN_PICKUP_EMAIL'),
            'address1' => env('NINJAVAN_PICKUP_ADDRESS1'),
            'city' => env('NINJAVAN_PICKUP_CITY'),
            'state' => env('NINJAVAN_PICKUP_STATE'),
            'postcode' => env('NINJAVAN_PICKUP_POSTCODE'),
            'country' => env('NINJAVAN_PICKUP_COUNTRY', 'SG'),
        ],
    ],

];
