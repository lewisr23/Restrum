<?php

namespace App\Providers;

use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StripePaymentGateway;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
