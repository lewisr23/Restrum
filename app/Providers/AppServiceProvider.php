<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StripePaymentGateway;
use App\Services\Recommender\ListingRecommender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound as a singleton so one StripeClient is built per request
        // rather than one per call site, and resolved from config here so
        // that a test can swap the whole gateway out without any code under
        // test knowing the difference.
        $this->app->singleton(PaymentGateway::class, fn () => new StripePaymentGateway(
            config('services.stripe.secret'),
            config('services.stripe.webhook_secret'),
        ));

        // A null client rather than a missing binding when there is no API
        // key. The recommender answers isConfigured() with false, the
        // frontend never renders the widget, and nothing anywhere has to
        // guard against the service not existing.
        $this->app->singleton(ListingRecommender::class, function () {
            $key = (string) config('services.anthropic.key');

            return new ListingRecommender(
                $key === '' ? null : new AnthropicClient(apiKey: $key),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * The gear adviser is the only endpoint here that costs money per
         * request and is open to anyone, so it gets its own limiter rather
         * than the API default.
         *
         * Two limits, not one. The per-minute limit stops a stuck client
         * hammering it; the daily one is the actual spending cap, and it is
         * the reason a bored visitor cannot run up a bill overnight. Both are
         * per IP, which is imperfect behind a shared connection and is still
         * the only handle available for a feature that does not require an
         * account.
         */
        RateLimiter::for('gear-adviser', fn (Request $request) => [
            Limit::perMinute(6)->by($request->ip()),
            Limit::perDay(60)->by($request->ip()),
        ]);
    }
}
