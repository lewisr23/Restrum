<?php

use Illuminate\Contracts\Console\Kernel;
use Stripe\StripeClient;

/**
 * Remove the data left behind by the end-to-end payment test of 2026-09-16.
 *
 * Four things, and the third is the one that would actually bite:
 *
 *   1. Order #1, listing #1 and the TestBuyer user. Visible junk.
 *   2. The stray connected account holding real personal details, started by
 *      accident before anyone realised test mode wanted magic values.
 *   3. The seller's link to a TEST connected account. Test and live are
 *      separate worlds in Stripe, so a stripe_account_id pointing at a test
 *      account is a dangling reference the moment live keys go in, and the
 *      failure would land at a seller's first real payout.
 *   4. Nothing else. The catalogue stays.
 *
 * Refuses to run against live keys, and asks before it deletes anything.
 * Everything that runs on the server is shipped as a file and invoked by
 * name, because PowerShell rewrites inline quotes on the way to ssh.
 */
$root = __DIR__.'/..';
require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$key = config('services.stripe.secret');

if (! str_starts_with((string) $key, 'sk_test_')) {
    fwrite(STDERR, "Refusing to run: STRIPE_SECRET is not a test key.\n");
    exit(1);
}

const SERVER = 'lewis@158.220.117.209';

$strayAccount = 'acct_1UGJuCRzC3FLUXJ8';   // real details, never completed
$testAccount = 'acct_1UGK0PRzC3rLR60a';    // Jenny Testerson, fully onboarded

echo "This will permanently delete, from the LIVE SITE's database:\n";
echo "  - order #1 (the PAID test order)\n";
echo "  - listing #1 'Webhook Test Stratocaster'\n";
echo "  - user #2 'TestBuyer'\n";
echo "  - the Stripe link on user #1, so you re-onboard when you go live\n";
echo "And will close these TEST Stripe accounts:\n";
echo "  - {$strayAccount} (holds your real name, DOB and address)\n";
echo "  - {$testAccount} (Jenny Testerson)\n\n";
echo "The catalogue and everything else is untouched.\n\n";
echo 'Type DELETE to continue: ';

$answer = trim((string) fgets(STDIN));

if ($answer !== 'DELETE') {
    echo "Nothing done.\n";
    exit(0);
}

$client = new StripeClient($key);

foreach ([$strayAccount, $testAccount] as $id) {
    try {
        $client->v2->core->accounts->close($id, ['applied_configurations' => ['recipient']]);
        echo "closed {$id}\n";
    } catch (Throwable $e) {
        echo "could not close {$id}: {$e->getMessage()}\n";
    }
}

$remote = <<<'REMOTE'
<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Listing;
use App\Models\Order;
use App\Models\User;

// Order first: it points at both the listing and the buyer.
Order::whereKey(1)->delete();
echo "order #1 removed\n";

Listing::where('title', 'Webhook Test Stratocaster')->delete();
echo "test listing removed\n";

User::where('email', 'testbuyer@restrum.uk')->delete();
echo "TestBuyer removed\n";

$seller = User::find(1);
$seller->stripe_account_id = null;
$seller->stripe_transfers_enabled = false;
$seller->stripe_payouts_enabled = false;
$seller->stripe_synced_at = null;
$seller->save();
echo "seller unlinked from the test Stripe account\n";

echo "remaining: users=".User::count()
    .", listings=".Listing::count()
    .", orders=".Order::count()."\n";
REMOTE;

$tmp = sys_get_temp_dir();
$php = $tmp.DIRECTORY_SEPARATOR.'restrum_cleanup.php';
$sh = $tmp.DIRECTORY_SEPARATOR.'restrum_cleanup.sh';

file_put_contents($php, $remote);
file_put_contents($sh, implode("\n", [
    '#!/usr/bin/env bash',
    'set -e',
    'docker cp ~/restrum_cleanup.php restrum-app-1:/tmp/restrum_cleanup.php',
    'cd ~/restrum',
    'docker compose --env-file .env.production -f docker-compose.prod.yml exec -T app php /tmp/restrum_cleanup.php',
    'rm -f ~/restrum_cleanup.php ~/restrum_cleanup.sh',
    '',
]));

echo "\nCleaning the database...\n";
exec('scp -o BatchMode=yes '.escapeshellarg($php).' '.SERVER.':~/restrum_cleanup.php', $o1, $c1);
exec('scp -o BatchMode=yes '.escapeshellarg($sh).' '.SERVER.':~/restrum_cleanup.sh', $o2, $c2);
exec('ssh -o BatchMode=yes '.SERVER.' bash restrum_cleanup.sh 2>&1', $o3, $c3);

@unlink($php);
@unlink($sh);

echo implode("\n", $o3)."\n";
echo ($c1 === 0 && $c2 === 0 && $c3 === 0) ? "\nDone.\n" : "\nServer step failed.\n";
