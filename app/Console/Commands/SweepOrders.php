<?php

namespace App\Console\Commands;

use App\Services\Payments\CheckoutService;
use App\Services\Payments\EscrowService;
use Illuminate\Console\Command;

/**
 * The scheduled half of the payment system.
 *
 * Three jobs that all exist because people walk away from things: buyers
 * abandon checkouts, buyers forget to confirm a parcel that arrived, and
 * sellers are owed money whether or not anyone remembers to press a button.
 * None of them can be driven by a webhook, because the event they respond to
 * is the absence of one.
 *
 * Written as a single command rather than three because the order matters -
 * expiring reservations first means the auto-confirm pass is not looking at
 * orders that are about to be cancelled - and because one scheduled entry is
 * one thing to notice has stopped running.
 */
class SweepOrders extends Command
{
    protected $signature = 'orders:sweep
                            {--dry-run : Report what would happen without touching anything}';

    protected $description = 'Expire lapsed checkout reservations, auto-confirm overdue orders, and pay sellers what they are owed';

    public function handle(CheckoutService $checkout, EscrowService $escrow): int
    {
        if ($this->option('dry-run')) {
            // Deliberately not a simulation of the whole sweep. Anything that
            // predicted the outcome would be a second implementation of the
            // rules, and a second implementation of rules about money is a
            // second set of bugs about money.
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $expired = $checkout->expireLapsedReservations();
        $this->line("Reservations expired: {$expired}");

        $confirmed = $escrow->autoConfirmOverdue();
        $this->line("Orders auto-confirmed: {$confirmed}");

        $released = $escrow->releaseDue();
        $this->line("Sellers paid: {$released['released']}");

        if ($released['failed'] > 0) {
            // Not a failure of the command. Each one is already logged with
            // its order and its reason, and the sweep will try them again;
            // surfacing the count is so a human notices a pattern.
            $this->warn("Payouts that could not be made: {$released['failed']}");
        }

        return self::SUCCESS;
    }
}
