<?php

use Illuminate\Contracts\Console\Kernel;
use Stripe\StripeClient;

/**
 * Create this integration's TEST-mode webhook destinations and push their
 * signing secrets to the server.
 *
 * Three destinations, not one, and the split is forced by Stripe rather
 * than chosen:
 *
 *   1. Platform events. Separate charges and transfers means the
 *      PaymentIntent belongs to the platform, so payment events are NOT
 *      connected-account events and must go to an endpoint with
 *      connect => false.
 *   2. Connected-account events. account.updated for a seller is emitted on
 *      the seller's account, so it only arrives at an endpoint created with
 *      connect => true. This is the one the dashboard quietly gets wrong by
 *      defaulting to "Events from: Your account".
 *   3. The v2 events, which can only be delivered as "thin" payloads and so
 *      cannot share a destination with the snapshot ones above.
 *
 * Hence three signing secrets, which is why STRIPE_WEBHOOK_SECRET takes a
 * comma separated list.
 *
 * Re-runnable: it removes its own previous destinations first, because a v1
 * endpoint's signing secret is shown once at creation and can never be read
 * back, so recreating is the only way to recover them.
 *
 * Nothing here is passed through a shell with inline quoting. Everything
 * that runs on the server is shipped as a file and invoked by name, because
 * PowerShell rewrites quotes on the way to ssh and silently produces
 * commands that mean something else entirely.
 */
$root = __DIR__.'/..';
require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$secret = config('services.stripe.secret');

if (! str_starts_with((string) $secret, 'sk_test_')) {
    fwrite(STDERR, "Refusing to run: STRIPE_SECRET is not a test key.\n");
    exit(1);
}

const URL = 'https://restrum.uk/api/stripe/webhook';
const SERVER = 'lewis@158.220.117.209';
const TAG = 'Restrum auto-created (test)';

$client = new StripeClient($secret);

echo "Removing any destinations this script created before...\n";

foreach ($client->webhookEndpoints->all(['limit' => 100])->data as $e) {
    if ($e->url === URL) {
        $client->webhookEndpoints->delete($e->id);
        echo "  removed {$e->id}\n";
    }
}

try {
    foreach ($client->v2->core->eventDestinations->all(['limit' => 100])->data as $d) {
        if (str_contains($d->name ?? '', 'Restrum')) {
            $client->v2->core->eventDestinations->delete($d->id);
            echo "  removed {$d->id}\n";
        }
    }
} catch (Throwable $e) {
    echo "  (no v2 destinations to remove)\n";
}

echo "\nCreating test-mode destinations for ".URL."\n";

$secrets = [];

$platform = $client->webhookEndpoints->create([
    'url' => URL,
    'enabled_events' => [
        'payment_intent.succeeded',
        'payment_intent.canceled',
        'charge.refunded',
        'charge.dispute.created',
    ],
    'description' => TAG.' - platform events',
]);
$secrets[] = $platform->secret;
echo "  platform events      {$platform->id}\n";

$connect = $client->webhookEndpoints->create([
    'url' => URL,
    'enabled_events' => ['account.updated'],
    'connect' => true,
    'description' => TAG.' - connected accounts',
]);
$secrets[] = $connect->secret;
echo "  connected accounts   {$connect->id}\n";

try {
    $thin = $client->v2->core->eventDestinations->create([
        'name' => 'Restrum v2 account events (test)',
        'type' => 'webhook_endpoint',
        'event_payload' => 'thin',
        'enabled_events' => [
            'v2.core.account[configuration.recipient].updated',
            'v2.core.account[configuration.recipient].capability_status_updated',
        ],
        'webhook_endpoint' => ['url' => URL],
        'include' => ['webhook_endpoint.signing_secret'],
    ])->toArray();

    if (! empty($thin['webhook_endpoint']['signing_secret'])) {
        $secrets[] = $thin['webhook_endpoint']['signing_secret'];
    }
    echo "  v2 thin events       {$thin['id']}\n";
} catch (Throwable $e) {
    echo "  v2 thin events       FAILED: {$e->getMessage()}\n";
}

$secrets = array_values(array_filter($secrets));
echo "\n".count($secrets)." signing secret(s) captured.\n";

// Ship the value and the remote steps as files. No quoting, no shell
// interpretation, nothing for PowerShell to rewrite on the way past.
$tmp = sys_get_temp_dir();
$line = $tmp.DIRECTORY_SEPARATOR.'restrum_secret_line';
$apply = $tmp.DIRECTORY_SEPARATOR.'restrum_apply.sh';

file_put_contents($line, 'STRIPE_WEBHOOK_SECRET='.implode(',', $secrets)."\n");
file_put_contents($apply, implode("\n", [
    '#!/usr/bin/env bash',
    'set -e',
    'cd ~/restrum',
    'grep -v ^STRIPE_WEBHOOK_SECRET= .env.production > .env.next',
    'cat ~/restrum_secret_line >> .env.next',
    'mv .env.next .env.production',
    'chmod 600 .env.production',
    'shred -u ~/restrum_secret_line',
    'echo applied',
    '',
]));

echo "Copying to the server...\n";
exec('scp -o BatchMode=yes '.escapeshellarg($line).' '.SERVER.':~/restrum_secret_line', $o1, $c1);
exec('scp -o BatchMode=yes '.escapeshellarg($apply).' '.SERVER.':~/restrum_apply.sh', $o2, $c2);
exec('ssh -o BatchMode=yes '.SERVER.' bash restrum_apply.sh 2>&1', $o3, $c3);

@unlink($line);
@unlink($apply);

if ($c1 === 0 && $c2 === 0 && $c3 === 0) {
    echo "Written to .env.production on the server.\n";
} else {
    echo "Server step failed:\n".implode("\n", array_merge($o1, $o2, $o3))."\n";
    exit(1);
}
