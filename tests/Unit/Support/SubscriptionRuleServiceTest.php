<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SubscriptionRuleService;
use PHPUnit\Framework\TestCase;

class SubscriptionRuleServiceTest extends TestCase
{
    public function testNormalizeDomainListSplitsOnAllSeparators(): void
    {
        $out = SubscriptionRuleService::normalizeDomainList("Example.COM example.org\nfoo.bar,baz.qux;quux.corge");
        $this->assertSame(['example.com', 'example.org', 'foo.bar', 'baz.qux', 'quux.corge'], $out);
    }

    public function testNormalizeDomainListDedupsAndDropsInvalid(): void
    {
        $out = SubscriptionRuleService::normalizeDomainList("a.ru\nA.RU\n\nbad_domain!\n.a.ru\nok-name.su");
        $this->assertSame(['a.ru', 'ok-name.su'], $out);
    }

    public function testNormalizeDomainListEmpty(): void
    {
        $this->assertSame([], SubscriptionRuleService::normalizeDomainList(''));
        $this->assertSame([], SubscriptionRuleService::normalizeDomainList(" \n,; "));
    }
}
