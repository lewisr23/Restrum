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

        /*
         * How long a seller has to actually post the thing before the buyer
         * gets their money back.
         *
         * Separate from auto_release_days, and shorter, because the two
         * count different risks: that one is a buyer who never came back to
         * confirm, this one is a seller who never sent anything. Silence
         * from a seller who has been paid should not cost the buyer.
         */
        'dispatch_deadline_days' => env('STRIPE_DISPATCH_DEADLINE_DAYS', 7),
    ],

    /*
     * The gear adviser. Off unless a key is present, so a fork of this
     * project runs perfectly well with no Anthropic account and the widget
     * simply does not appear.
     */
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),

        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

        // How many times Claude may call a tool before the reply is forced.
        // Each pass is a billed request, so this is the ceiling on what one
        // question can cost.
        'max_tool_rounds' => env('ANTHROPIC_MAX_TOOL_ROUNDS', 4),

        // How many turns of a conversation are sent back. Old turns are the
        // bulk of what a chat costs, and nobody's taste in guitars depends on
        // what they asked twenty messages ago.
        'max_history' => env('ANTHROPIC_MAX_HISTORY', 12),
    ],

];
