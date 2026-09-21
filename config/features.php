<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature switches
    |--------------------------------------------------------------------------
    |
    | Behaviour that is built and tested but whose rollout depends on
    | something outside the code being ready.
    |
    */

    /*
     * Whether an unverified email address blocks the actions that carry
     * money or reach another member: listing an instrument, paying for one,
     * and messaging.
     *
     * OFF by default, and that default is load-bearing rather than timid.
     * Enforcing verification while MAIL_MAILER is still "log" would brick
     * the site for every new account: nothing can send the link, so nothing
     * can ever be verified, so nobody can ever sell, buy or ask a question.
     * Turn this on in the same change that points MAIL_MAILER at a real
     * SMTP host, never before.
     *
     * Verification emails send regardless of this switch, so the addresses
     * of people who register before it is turned on are still confirmed.
     */
    'require_email_verification' => (bool) env('REQUIRE_EMAIL_VERIFICATION', false),

];
