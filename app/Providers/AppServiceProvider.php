<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\Payments\FakeCardGateway;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Test mode: no real card is ever charged.
        $this->app->bind(PaymentGateway::class, FakeCardGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SR-38: every new password (reset, profile, accounts created by the Manager).
        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
