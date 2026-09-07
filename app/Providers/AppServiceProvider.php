<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Line\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // socialiteproviders/line 官方安裝說明（Laravel 11+）：
        // 用 Event::listen 掛上 SocialiteWasCalled，讓 Socialite 認得 'line' 這個 driver。
        // Google 是 Socialite 內建 driver，不需要這道手續。
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('line', Provider::class);
        });
    }
}
