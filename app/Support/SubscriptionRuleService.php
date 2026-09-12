<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Server-side "Russia direct" (bypass VPN) rules, injected into client
 * subscriptions at render time instead of being hard-coded in the rule
 * templates. Controlled from the admin panel:
 *
 *  - v2board.subscribe_ru_direct_enable    0/1 (default 1)
 *  - v2board.subscribe_ru_direct_domains   extra domain suffixes, one per line
 *
 * All injections are idempotent: lines already present (e.g. from a
 * custom.* template that still carries hard-coded rules) are replaced,
 * never duplicated.
 */
class SubscriptionRuleService
{
    /**
     * Country-code TLDs assigned to Russia (matches geosite tld-ru) plus the
     * handful of major .com/.net services that are always wanted direct.
     */
    public const BASE_SUFFIXES = ['ru', 'su', 'xn--p1ai', 'moscow', 'tatar'];
    public const BASE_DOMAINS = ['vk.com', 'yandex.com', 'yandex.net', 'kaspersky.com'];

    public static function enabled(): bool
    {
        return (int) config('v2board.subscribe_ru_direct_enable', 1) === 1;
    }

    /**
     * Base set plus admin-entered extras (validated, lower-cased, de-duplicated).
     *
     * @return string[]
     */
    public static function suffixes(): array
    {
        $extra = [];
        $raw = (string) config('v2board.subscribe_ru_direct_domains', '');
        foreach (preg_split('/[\r\n,;\s]+/', $raw) ?: [] as $domain) {
            $domain = strtolower(ltrim(trim($domain), '.'));
            if ($domain === '' || preg_match('/^[a-z0-9.\-]+$/', $domain) !== 1) {
                continue;
            }
            $extra[] = $domain;
        }

        return array_values(array_unique(array_merge(self::BASE_SUFFIXES, self::BASE_DOMAINS, $extra)));
    }

    /**
     * Clash-family domain rule lines (no GEOIP line), in evaluation order.
     * $geosite is true only for mihomo-based clients (Stash), which support
     * GEOSITE; legacy Clash cores error on an unknown rule type.
     *
     * @return string[]
     */
    public static function clashLines(bool $geosite): array
    {
        if (!self::enabled()) {
            return [];
        }
        $lines = $geosite ? ['GEOSITE,category-ru,DIRECT'] : [];
        foreach (self::suffixes() as $suffix) {
            $lines[] = "DOMAIN-SUFFIX,{$suffix},DIRECT";
        }

        return $lines;
    }

    /**
     * Prepend RU domain rules to a parsed Clash/Mihomo config array and place
     * GEOIP,RU just before the GEOIP,CN/MATCH tail (matching template style).
     */
    public static function injectClash(array $config, bool $geosite = false): array
    {
        $lines = self::clashLines($geosite);
        if ($lines === []) {
            return $config;
        }
        $geoipLine = 'GEOIP,RU,DIRECT';
        $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        // drop any pre-existing copies (e.g. hard-coded in a custom template)
        $rules = array_values(array_udiff($rules, array_merge($lines, [$geoipLine]), 'strcmp'));
        $rules = array_merge($lines, $rules);
        // insert GEOIP,RU right before the final GEOIP,CN or MATCH rule
        $idx = count($rules);
        foreach ($rules as $i => $r) {
            if (strpos($r, 'GEOIP,CN,') === 0 || strpos($r, 'MATCH,') === 0) {
                $idx = $i;
                break;
            }
        }
        array_splice($rules, $idx, 0, [$geoipLine]);
        $config['rules'] = $rules;

        return $config;
    }

    /**
     * Insert RU rules into a Surge/Surfboard INI text config: domain rules
     * after the [Rule] header, GEOIP,RU before the GEOIP,CN tail.
     */
    public static function injectText(string $config): string
    {
        $lines = self::clashLines(false);
        if ($lines === []) {
            return $config;
        }
        $geoipLine = 'GEOIP,RU,DIRECT';
        $all = array_merge($lines, [$geoipLine]);
        // strip prior copies
        $config = implode("\n", array_udiff(preg_split('/\R+/', $config) ?: [], $all, 'strcmp'));
        if (preg_match('/^\[Rule\]/m', $config) === 1) {
            $block = implode("\n", $lines) . "\n";
            $config = preg_replace('/(\[Rule\][^\S\r\n]*\r?\n)/m', '$1' . $block, $config, 1);
        } else {
            $config = implode("\n", $lines) . "\n" . $config;
        }
        // GEOIP,RU before GEOIP,CN tail, else before FINAL
        if (strpos($config, 'GEOIP,CN,DIRECT') !== false) {
            $config = preg_replace('/^GEOIP,CN,DIRECT/m', $geoipLine . "\nGEOIP,CN,DIRECT", $config, 1);
        } elseif (preg_match('/^FINAL[,_]/m', $config) === 1) {
            $config = preg_replace('/^(FINAL[,_][^\r\n]*)/m', $geoipLine . "\n$1", $config, 1);
        }

        return $config;
    }

    /**
     * Inject RU rule-sets into a parsed sing-box config array.
     * $legacy selects the pre-1.12 rule schema (no "action" keys).
     */
    public static function injectSingbox(array $config, bool $legacy): array
    {
        if (!self::enabled()) {
            return $config;
        }
        $directTag = null;
        foreach ($config['outbounds'] ?? [] as $outbound) {
            if (($outbound['type'] ?? '') === 'direct') {
                $directTag = (string) ($outbound['tag'] ?? 'direct');
                break;
            }
        }
        if ($directTag === null) {
            return $config;
        }
        $detour = '节点选择';
        foreach ($config['route']['rule_set'] ?? [] as $rs) {
            if (!empty($rs['download_detour'])) {
                $detour = $rs['download_detour'];
                break;
            }
        }

        $makeRule = function (array $match) use ($directTag, $legacy): array {
            return $legacy
                ? $match + ['outbound' => $directTag]
                : $match + ['action' => 'route', 'outbound' => $directTag];
        };

        // --- route.rules: after the geosite-cn direct rule when present, else at the end
        $routeRules = is_array($config['route']['rules'] ?? null) ? $config['route']['rules'] : [];
        $routeRules = array_values(array_filter(
            $routeRules,
            fn ($r) => empty(array_intersect(['geosite-ru', 'geoip-ru'], (array) ($r['rule_set'] ?? [])))
        ));
        $insert = [$makeRule(['rule_set' => ['geosite-ru', 'geoip-ru']])];
        $extras = array_values(array_diff(self::suffixes(), self::BASE_SUFFIXES, self::BASE_DOMAINS));
        if ($extras !== []) {
            $insert[] = $makeRule(['domain_suffix' => $extras]);
        }
        $idx = count($routeRules);
        foreach ($routeRules as $i => $r) {
            if (in_array('geosite-cn', (array) ($r['rule_set'] ?? []), true) && ($r['outbound'] ?? null) === $directTag) {
                $idx = $i + 1;
                break;
            }
        }
        array_splice($routeRules, $idx, 0, $insert);
        $config['route']['rules'] = $routeRules;

        // --- rule_set definitions (verified upstream URLs, same as templates use)
        $ruleSets = is_array($config['route']['rule_set'] ?? null) ? $config['route']['rule_set'] : [];
        $wanted = [
            'geosite-ru' => 'https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-category-ru.srs',
            'geoip-ru' => 'https://raw.githubusercontent.com/Loyalsoldier/geoip/release/srs/ru.srs',
        ];
        foreach ($wanted as $tag => $url) {
            foreach ($ruleSets as $i => $rs) {
                if (($rs['tag'] ?? '') === $tag) {
                    $ruleSets[$i]['url'] = $url;
                    continue 2;
                }
            }
            $ruleSets[] = [
                'tag' => $tag,
                'type' => 'remote',
                'format' => 'binary',
                'url' => $url,
                'download_detour' => $detour,
            ];
        }
        $config['route']['rule_set'] = $ruleSets;

        // --- dns.rules: resolve RU domains with the local (client-network) resolver
        $dnsRules = is_array($config['dns']['rules'] ?? null) ? $config['dns']['rules'] : [];
        $dnsRules = array_values(array_filter(
            $dnsRules,
            fn ($r) => empty(array_intersect(['geosite-ru'], (array) ($r['rule_set'] ?? [])))
        ));
        $dnsInsert = $legacy
            ? ['rule_set' => ['geosite-ru'], 'server' => 'local']
            : ['rule_set' => ['geosite-ru'], 'action' => 'route', 'server' => 'local'];
        $idx = 0;
        foreach ($dnsRules as $i => $r) {
            if (in_array('geosite-cn', (array) ($r['rule_set'] ?? []), true)) {
                $idx = $i;
                break;
            }
        }
        array_splice($dnsRules, $idx, 0, [$dnsInsert]);
        $config['dns']['rules'] = $dnsRules;

        return $config;
    }
}
