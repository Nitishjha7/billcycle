<?php

namespace App\Providers;

use App\Billing\FakeGateway;
use App\Billing\NullGateway;
use App\Billing\PaymentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PAYMENT_GATEWAY=fake is the only supported production value; see
        // docs/TECHNICAL_SPEC.md #6 for why no real gateway is integrated.
        // Tests bind NullGateway or a specifically configured FakeGateway
        // directly, bypassing this config-driven default entirely.
        $this->app->singleton(PaymentGateway::class, function () {
            return config('payment.gateway') === 'null'
                ? new NullGateway
                : new FakeGateway;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
