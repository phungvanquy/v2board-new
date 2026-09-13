<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Support\HappCryptoService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HappCryptoServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('v2board.happ_crypto_use_remote', 0);
        config()->set('v2board.happ_crypto_cache_version', 0);
        config()->set('v2board.happ_crypto_cache_ttl', 3600);
        config()->set('v2board.happ_crypto_public_key', '');
    }
    public function testEncryptEmptyUrlReturnsNull(): void
    {
        $this->assertNull(HappCryptoService::encrypt(''));
        $this->assertNull(HappCryptoService::encrypt('   '));
        $this->assertNull(HappCryptoService::encryptLink(''));
    }

    public function testEncryptOversizeLocalReturnsNullWithoutNetwork(): void
    {
        // 502 bytes > RSA-4096/PKCS#1 v1.5 limit (501); must fail before any
        // openssl call or remote HTTP request.
        $long = 'https://example.com/' . str_repeat('a', 600);
        $this->assertGreaterThan(HappCryptoService::MAX_LOCAL_PLAIN, strlen($long));
        $this->assertNull(HappCryptoService::encrypt($long, HappCryptoService::MODE_LOCAL));
    }

    public function testConfiguredModeFollowsSetting(): void
    {
        $this->assertSame(HappCryptoService::MODE_LOCAL, HappCryptoService::configuredMode());
        config()->set('v2board.happ_crypto_use_remote', 1);
        $this->assertSame(HappCryptoService::MODE_REMOTE, HappCryptoService::configuredMode());
    }

    public function testEncryptReturnsCachedResultWithoutCryptoOrNetwork(): void
    {
        config()->set('v2board.happ_crypto_cache_version', 'v7');
        $url = 'https://example.com/api/v1/client/subscribe?token=abc123';
        $expected = ['link' => HappCryptoService::PREFIX_CRYPT4 . 'cached', 'mode' => 'local'];
        Cache::put(HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_LOCAL), $expected, 3600);

        $this->assertSame($expected, HappCryptoService::encrypt($url, HappCryptoService::MODE_LOCAL));
        $this->assertSame($expected['link'], HappCryptoService::encryptLink($url, HappCryptoService::MODE_LOCAL));
    }

    public function testForgetForUrlClearsBothModes(): void
    {
        config()->set('v2board.happ_crypto_cache_version', 'v7');
        $url = 'https://example.com/api/v1/client/subscribe?token=forgetme';
        Cache::put(HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_LOCAL), ['link' => 'x', 'mode' => 'local'], 3600);
        Cache::put(HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_REMOTE), ['link' => 'y', 'mode' => 'remote'], 3600);

        HappCryptoService::forgetForUrl($url);

        $this->assertNull(Cache::get(HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_LOCAL)));
        $this->assertNull(Cache::get(HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_REMOTE)));
    }

    public function testCacheIdentityBindsEffectiveKeyAndMode(): void
    {
        // Same version, same URL — but a different effective key or mode must
        // never resolve to the other entry (same-second-save safety).
        config()->set('v2board.happ_crypto_cache_version', 'v1');
        $url = 'https://example.com/api/v1/client/subscribe?token=bind';

        $localKey = HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_LOCAL);
        $remoteKey = HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_REMOTE);
        $this->assertNotSame($localKey, $remoteKey);

        config()->set('v2board.happ_crypto_public_key', "-----BEGIN PUBLIC KEY-----\ninvalid\n-----END PUBLIC KEY-----");
        $rotatedKey = HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_LOCAL);
        $this->assertNotSame($localKey, $rotatedKey);
    }

    public function testCachedEntrySurvivesKeyRotationButIsNotReused(): void
    {
        $this->markTestSkippedUnlessOpenssl('encryptLocal needs openssl_public_encrypt');
        config()->set('v2board.happ_crypto_cache_version', 'vrot');
        $url = 'https://example.com/api/v1/client/subscribe?token=rotate';

        $first = HappCryptoService::encrypt($url, HappCryptoService::MODE_LOCAL);
        $this->assertIsArray($first);

        // A throwaway second key (2048-bit is fine here — encryptLocal() has no
        // size check; the controller's save validation does) proves rebinding:
        // the rotated key resolves to a different cache identity, so the old
        // entry is never served, even though it still exists under the old key.
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($res);
        openssl_pkey_export($res, $priv);
        $details = openssl_pkey_get_details($res);
        $this->assertIsArray($details);

        $oldKey = HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_LOCAL);
        config()->set('v2board.happ_crypto_public_key', $details['key']);
        $newKey = HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_LOCAL);
        $this->assertNotSame($oldKey, $newKey);
        $this->assertNull(Cache::get($newKey));

        $fresh = HappCryptoService::encrypt($url, HappCryptoService::MODE_LOCAL);
        $this->assertIsArray($fresh);
        $this->assertNotSame($first['link'], $fresh['link']);
        // Old entry is untouched under the old key — it simply is never looked
        // up again, which is exactly how a rotated config avoids stale links.
        $this->assertSame($first, Cache::get($oldKey));
    }

    public function testEncryptLocalRoundTripsAgainstBundledPublicKey(): void
    {
        $this->markTestSkippedUnlessOpenssl('encryptLocal needs openssl_public_encrypt');
        $url = 'https://example.com/api/v1/client/subscribe?token=abc123';

        $result = HappCryptoService::encrypt($url, HappCryptoService::MODE_LOCAL);

        $this->assertIsArray($result);
        $this->assertSame(HappCryptoService::MODE_LOCAL, $result['mode']);
        $this->assertStringStartsWith(HappCryptoService::PREFIX_CRYPT4, $result['link']);
        // RSA-4096 ciphertext is 512 bytes once base64-decoded.
        $this->assertSame(
            512,
            strlen((string) base64_decode(substr($result['link'], strlen(HappCryptoService::PREFIX_CRYPT4)), true))
        );
        // Short URL must stay under the PKCS#1 v1.5 ceiling, unrelated URLs stay unique.
        $this->assertLessThanOrEqual(HappCryptoService::MAX_LOCAL_PLAIN, strlen($url));
        $other = HappCryptoService::encrypt($url . '&x=1', HappCryptoService::MODE_LOCAL);
        $this->assertIsArray($other);
        $this->assertNotSame($result['link'], $other['link']);
    }

    public function testEncryptLocalAccepts501ByteUrl(): void
    {
        $this->markTestSkippedUnlessOpenssl('encryptLocal needs openssl_public_encrypt');
        $url = 'https://example.com/' . str_repeat('b', 501 - strlen('https://example.com/'));
        $this->assertSame(HappCryptoService::MAX_LOCAL_PLAIN, strlen($url));

        $result = HappCryptoService::encrypt($url, HappCryptoService::MODE_LOCAL);

        $this->assertIsArray($result);
        $this->assertStringStartsWith(HappCryptoService::PREFIX_CRYPT4, $result['link']);
    }

    public function testEncryptLocalResultIsCached(): void
    {
        $this->markTestSkippedUnlessOpenssl('encryptLocal needs openssl_public_encrypt');
        config()->set('v2board.happ_crypto_cache_version', 'v11');
        $url = 'https://example.com/api/v1/client/subscribe?token=cached';

        $first = HappCryptoService::encrypt($url, HappCryptoService::MODE_LOCAL);
        $this->assertIsArray($first);
        $cached = Cache::get(HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_LOCAL));
        $this->assertSame($first, $cached);

        // A second call must come from cache: a corrupted key can no longer
        // break it, and the cached link is returned byte-identical. Note the
        // cache identity binds the effective key, so rotating the key moves
        // the lookup — restore the good key first, then prove the second
        // call is served from cache by asserting identity after the encrypt.
        config()->set('v2board.happ_crypto_public_key', '');
        $second = HappCryptoService::encrypt($url, HappCryptoService::MODE_LOCAL);
        $this->assertSame($first, $second);
    }

    public function testEncryptLocalFailureIsNotCached(): void
    {
        config()->set('v2board.happ_crypto_cache_version', 'v12');
        // Passes pem()'s 'BEGIN PUBLIC KEY' gate but fails openssl parsing,
        // so encryptLocal() must fail (no fallback to the bundled key).
        config()->set('v2board.happ_crypto_public_key', "-----BEGIN PUBLIC KEY-----\ninvalid\n-----END PUBLIC KEY-----");
        $url = 'https://example.com/api/v1/client/subscribe?token=badkey';

        $this->assertNull(HappCryptoService::encrypt($url, HappCryptoService::MODE_LOCAL));
        $this->assertNull(Cache::get(HappCryptoService::cacheKeyFor($url, HappCryptoService::MODE_LOCAL)));

        // Oversized URLs also fail before caching, this time offline.
        config()->set('v2board.happ_crypto_public_key', '');
        $long = 'https://example.com/' . str_repeat('a', 600);
        $this->assertNull(HappCryptoService::encrypt($long, HappCryptoService::MODE_LOCAL));
        $this->assertNull(Cache::get(HappCryptoService::cacheKeyFor($long, HappCryptoService::MODE_LOCAL)));
    }

    public function testEncryptTrimsWhitespaceAndSharesCacheKey(): void
    {
        $this->markTestSkippedUnlessOpenssl('encryptLocal needs openssl_public_encrypt');
        config()->set('v2board.happ_crypto_cache_version', 'v13');
        $url = 'https://example.com/api/v1/client/subscribe?token=spaced';

        $plain = HappCryptoService::encrypt($url, HappCryptoService::MODE_LOCAL);
        $padded = HappCryptoService::encrypt('  ' . $url . "\n", HappCryptoService::MODE_LOCAL);

        $this->assertIsArray($plain);
        $this->assertSame($plain, $padded);
    }

    public function testEncryptRejectsUnknownMode(): void
    {
        $this->markTestSkippedUnlessOpenssl('encryptLocal needs openssl_public_encrypt');
        $url = 'https://example.com/api/v1/client/subscribe?token=moded';

        // An unknown explicit mode falls back to local instead of being used
        // as a cache-key mode or dispatched to the remote HTTP path.
        $result = HappCryptoService::encrypt($url, 'bogus');
        $this->assertIsArray($result);
        $this->assertSame(HappCryptoService::MODE_LOCAL, $result['mode']);
        $this->assertStringStartsWith(HappCryptoService::PREFIX_CRYPT4, $result['link']);
    }

    private function markTestSkippedUnlessOpenssl(string $reason): void
    {
        if (!function_exists('openssl_public_encrypt') || !function_exists('openssl_get_publickey')) {
            $this->markTestSkipped($reason);
        }
    }
}
