<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        /*
         * Marketplace policy rather than credentials, kept here because it is
         * read on every one of the Stripe calls below and splitting it into
         * its own file would only mean two places to look.
         */

        // The platform's cut, as a percentage of the sale price. Stored onto
        // each order at checkout, so changing it never rewrites the
        // economics of sales that already happened.
        'platform_fee_percent' => env('STRIPE_PLATFORM_FEE_PERCENT', '5'),

        // How long a checkout holds an instrument off the market. Long enough
        // to find a card, short enough that an abandoned checkout does not
        // strand a listing: Stripe expires its own session at 24 hours, which
        // is far too long to make a seller wait.
        'reservation_minutes' => env('STRIPE_RESERVATION_MINUTES', 30),

        // How long the buyer has to confirm the item arrived before the money
        // releases to the seller anyway. Without it an order sits in escrow
        // forever whenever a buyer simply never comes back, which punishes
        // the seller for the buyer's silence.
        'auto_release_days' => env('STRIPE_AUTO_RELEASE_DAYS', 14),
    ],

];
