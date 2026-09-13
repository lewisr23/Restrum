<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Every five minutes, because the shortest thing it watches is a checkout
 * reservation and a buyer who walks away should not hold an instrument for
 * much longer than they already have.
 *
 * withoutOverlapping matters more than the frequency: this command pays
 * sellers, and two copies running at once would be two attempts to pay the
 * same one. Stripe's idempotency keys mean that could not actually double a
 * payout, but relying on the last line of defence as the first one is how
 * the last line stops being reliable.
 */
Schedule::command('orders:sweep')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
