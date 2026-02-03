<?php

namespace LetoceilingCoder\Telegram;

use Illuminate\Support\ServiceProvider;
use LetoceilingCoder\Telegram\Bot;
use LetoceilingCoder\Telegram\Channel;
use LetoceilingCoder\Telegram\MiniApp;
use LetoceilingCoder\Telegram\Callback;
use LetoceilingCoder\Telegram\RateLimiter;

class TelegramServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Регистрируем singleton для основных классов
        $this->app->singleton('telegram.bot', function ($app) {
            return new Bot(config('telegram.bot_token'));
        });

        $this->app->singleton('telegram.channel', function ($app) {
            return new Channel(config('telegram.bot_token'));
        });

        $this->app->singleton('telegram.miniapp', function ($app) {
            return new MiniApp(config('telegram.bot_token'));
        });

        $this->app->singleton('telegram.callback', function ($app) {
            return new Callback(config('telegram.bot_token'));
        });

        $this->app->singleton('telegram.rate_limiter', function ($app) {
            return new RateLimiter();
        });

        // Алиасы для удобства
        $this->app->alias('telegram.bot', Bot::class);
        $this->app->alias('telegram.channel', Channel::class);
        $this->app->alias('telegram.miniapp', MiniApp::class);
        $this->app->alias('telegram.callback', Callback::class);
        $this->app->alias('telegram.rate_limiter', RateLimiter::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Публикация конфига
        $this->publishes([
            __DIR__.'/../config/telegram.php' => config_path('telegram.php'),
        ], 'telegram-config');
    }

    /**
     * Предоставляемые сервисы
     */
    public function provides(): array
    {
        return [
            'telegram.bot',
            'telegram.channel',
            'telegram.miniapp',
            'telegram.callback',
            'telegram.rate_limiter',
        ];
    }
}

