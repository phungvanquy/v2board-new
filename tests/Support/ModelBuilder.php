<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Support\CryptoHelper;

/**
 * Lightweight model builders for tests. Prefer these over factories
 * when a model has legacy guards/casts that make factories noisy.
 * All builders return unsaved model instances unless ->create() is called.
 */
class ModelBuilder
{
    public static function user(array $overrides = []): User
    {
        $defaults = [
            'email' => 'test_' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => CryptoHelper::guid(true),
            'token' => bin2hex(random_bytes(16)),
            'transfer_enable' => 107374182400, // 100 GB
            'u' => 0,
            'd' => 0,
            'expired_at' => time() + 86400 * 30,
            'banned' => 0,
            'is_admin' => 0,
            'balance' => 0,
            'commission_balance' => 0,
            'group_id' => 1,
            'speed_limit' => null,
        ];

        $user = new User();
        foreach (array_merge($defaults, $overrides) as $k => $v) {
            $user->$k = $v;
        }

        return $user;
    }

    public static function plan(array $overrides = []): Plan
    {
        $defaults = [
            'group_id' => 1,
            'transfer_enable' => 53687091200,
            'name' => 'Test Plan',
            'show' => 1,
            'month_price' => 1000,
            'reset_traffic_method' => 0,
            'content' => 'Test plan content',
        ];

        $plan = new Plan();
        foreach (array_merge($defaults, $overrides) as $k => $v) {
            $plan->$k = $v;
        }

        return $plan;
    }

    public static function order(array $overrides = []): Order
    {
        $defaults = [
            'user_id' => 1,
            'plan_id' => 1,
            'period' => 'month_price',
            'trade_no' => bin2hex(random_bytes(16)),
            'total_amount' => 1000,
            'status' => 0,
        ];

        $order = new Order();
        foreach (array_merge($defaults, $overrides) as $k => $v) {
            $order->$k = $v;
        }

        return $order;
    }

    /**
     * Generate a signed auth token for a user without hitting DB/cache.
     * Useful for middleware bypass in feature tests.
     */
    public static function authDataFor(User $user, string $session = 'test-session'): string
    {
        return \Firebase\JWT\JWT::encode(
            ['id' => $user->id ?? 1, 'session' => $session],
            config('app.key'),
            'HS256'
        );
    }
}
