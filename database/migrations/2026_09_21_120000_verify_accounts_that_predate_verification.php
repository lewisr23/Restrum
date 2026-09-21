<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Treat every account that already existed as confirmed.
 *
 * Email verification did not exist when these people registered, so none of
 * them has an email_verified_at. Shipping the feature without this would
 * retroactively lock existing members out of selling, buying and messaging
 * for failing a step they were never offered, and the "resend" button would
 * be their only way back in.
 *
 * It does mean their addresses are unconfirmed in fact while confirmed in
 * the database. That is the right trade at this size: nobody has sold
 * anything yet, so the accounts in question are the operator's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Not reversible, and deliberately so. Down() cannot tell an account
     * grandfathered in by up() from one that genuinely confirmed its
     * address afterwards, and blanking both would unverify real members.
     */
    public function down(): void
    {
        //
    }
};
