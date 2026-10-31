<?php

namespace App\Providers;

use App\Payments\FakeGateway;
use App\Payments\PawaPayGateway;
use App\Payments\PaymentGateway;
use App\Services\CartService;
use App\Services\ChatService;
use App\Sms\HttpSender;
use App\Sms\LogSender;
use App\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsSender::class, function () {
            $senderId = config('sms.sender_id');

            return match (config('sms.driver')) {
                'http' => new HttpSender(
                    $senderId,
                    config('sms.http.url'),
                    config('sms.http.token'),
                    (int) config('sms.http.timeout'),
                ),
                'log' => new LogSender($senderId),
                default => throw new InvalidArgumentException('Unknown SMS_DRIVER: '.config('sms.driver')),
            };
        });

        $this->app->bind(PaymentGateway::class, function () {
            return match (config('payments.gateway')) {
                'pawapay' => new PawaPayGateway(
                    config('payments.pawapay.base_url'),
                    config('payments.pawapay.token'),
                    config('payments.currency'),
                    (int) config('payments.pawapay.timeout'),
                    config('payments.pawapay.statement_description'),
                ),
                'fake' => $this->app->isProduction()
                    ? throw new RuntimeException('PAYMENT_GATEWAY=fake is not allowed in production.')
                    : new FakeGateway,
                default => throw new InvalidArgumentException('Unknown PAYMENT_GATEWAY: '.config('payments.gateway')),
            };
        });
    }

    public function boot(): void
    {
        RateLimiter::for('otp-send', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(6)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('cart', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Max 20 chat messages a minute per person (CHAT_MAX_PER_MINUTE).
        RateLimiter::for('chat-send', fn (Request $request) => Limit::perMinute(config('chat.max_per_minute'))
            ->by($request->user()?->id ?: $request->ip()));

        // Unread chat badge in the header, one cheap query per page for logged-in people.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            $view->with('chatUnread', $user
                ? app(ChatService::class)->unreadFor($user) + ($user->is_admin ? app(ChatService::class)->supportUnreadTotal() : 0)
                : 0);
            $view->with('cartCount', $user ? app(CartService::class)->count($user) : 0);
        });
    }
}
