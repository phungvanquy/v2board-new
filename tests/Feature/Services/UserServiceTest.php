<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Plan;
use App\Models\User;
use App\Services\UserService;
use Tests\TestCase;

class UserServiceTest extends TestCase
{
    private UserService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new UserService();
    }

    public function testIsAvailableWhenNotBannedAndNotExpired(): void
    {
        $user = new User();
        $user->banned = 0;
        $user->transfer_enable = 1000;
        $user->expired_at = time() + 3600;
        $this->assertTrue($this->svc->isAvailable($user));
    }

    public function testIsAvailableFalseWhenBanned(): void
    {
        $user = new User();
        $user->banned = 1;
        $user->transfer_enable = 1000;
        $user->expired_at = time() + 3600;
        $this->assertFalse($this->svc->isAvailable($user));
    }

    public function testIsAvailableFalseWhenZeroTransfer(): void
    {
        $user = new User();
        $user->banned = 0;
        $user->transfer_enable = 0;
        $user->expired_at = time() + 3600;
        $this->assertFalse($this->svc->isAvailable($user));
    }

    public function testIsAvailableFalseWhenExpired(): void
    {
        $user = new User();
        $user->banned = 0;
        $user->transfer_enable = 1000;
        $user->expired_at = time() - 1;
        $this->assertFalse($this->svc->isAvailable($user));
    }

    public function testIsAvailableTrueWhenExpiredNull(): void
    {
        $user = new User();
        $user->banned = 0;
        $user->transfer_enable = 1000;
        $user->expired_at = null;
        $this->assertTrue($this->svc->isAvailable($user));
    }

    public function testGetResetDayNullWhenNoPlan(): void
    {
        $user = new User();
        $user->plan_id = null;
        $user->expired_at = time() + 3600;
        $this->assertNull($this->svc->getResetDay($user));
    }

    public function testGetResetDayNullWhenExpired(): void
    {
        $user = new User();
        $user->plan_id = 1;
        $user->expired_at = time() - 10;
        // attach a mock plan with reset method not 2
        $plan = new Plan();
        $plan->reset_traffic_method = 0;
        $user->setRelation('plan', $plan);
        $this->assertNull($this->svc->getResetDay($user));
    }

    public function testGetResetDayNullWhenPlanResetMethodIsNoReset(): void
    {
        $user = new User();
        $user->plan_id = 1;
        $user->expired_at = time() + 3600;
        $plan = new Plan();
        $plan->reset_traffic_method = 2;
        $user->setRelation('plan', $plan);
        $this->assertNull($this->svc->getResetDay($user));
    }

    public function testGetResetDayReturnsIntForMonthlyFirstDay(): void
    {
        $user = new User();
        $user->plan_id = 1;
        $user->expired_at = time() + 86400 * 10;
        $plan = new Plan();
        $plan->reset_traffic_method = 0;
        $user->setRelation('plan', $plan);
        $result = $this->svc->getResetDay($user);
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    public function testGetResetPeriodNullWhenNoPlanId(): void
    {
        $user = new User();
        $user->plan_id = null;
        $user->expired_at = time() + 3600;
        $this->assertNull($this->svc->getResetPeriod($user));
    }

    public function testTrafficFetchDispatchesJobs(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->svc->trafficFetch(['id' => 1], 'vmess', ['userId' => 1, 'u' => 100]);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\TrafficFetchJob::class);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\StatUserJob::class);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\StatServerJob::class);
    }
}
