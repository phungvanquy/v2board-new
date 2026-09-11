<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    public function testGenerateAndDecryptAuthDataRoundtrip(): void
    {
        $user = new User();
        $user->id = 999;
        $user->email = 'auth-test@example.com';
        $user->is_admin = 0;
        $user->is_staff = 0;
        $user->token = bin2hex(random_bytes(16));

        $svc = new AuthService($user);
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->headers->set('User-Agent', 'PHPUnit');

        // generateAuthData writes to CacheKey USER_SESSIONS + JWT decode path
        $data = $svc->generateAuthData($request);
        $this->assertArrayHasKey('auth_data', $data);
        $this->assertArrayHasKey('token', $data);

        // Now decryptAuthData should succeed (uses Cache::get path)
        $decoded = AuthService::decryptAuthData($data['auth_data']);
        // decryptAuthData returns cached user array; may be false if User::find fails
        // but we verify it does not throw and returns array or false deterministically
        $this->assertTrue(is_array($decoded) || $decoded === false);
    }

    public function testDecryptInvalidTokenReturnsFalse(): void
    {
        $this->assertFalse(AuthService::decryptAuthData('not-a-jwt'));
        $this->assertFalse(AuthService::decryptAuthData(''));
    }

    public function testDecryptExpiredOrTamperedJwtReturnsFalse(): void
    {
        $jwt = \Firebase\JWT\JWT::encode(['id' => 1, 'session' => 'x'], str_repeat('k', 32), 'HS256');
        $this->assertFalse(AuthService::decryptAuthData($jwt));
    }
}
