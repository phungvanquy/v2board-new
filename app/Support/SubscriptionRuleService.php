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
     * Normalize an admin-entered domain list: strip empties, validate
     * charset, lower-case, de-duplicate. Single home for the logic so the
     * admin save path and the render path can never drift (e.g. different
     * split regexes treating space-separated input differently).
     *
     * @return string[]
     */
    public static function normalizeDomainList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;\s]+/', $raw) ?: [] as $domain) {
            $domain = strtolower(ltrim(trim($domain), '.'));
            if ($domain === '' || preg_match('/^[a-z0-9.\-]+$/', $domain) !== 1) {
                continue;
            }
            if (!in_array($domain, $out, true)) {
                $out[] = $domain;
            }
        }

        return $out;
    }

    /**
     * Base set plus admin-entered extras (validated, lower-cased, de-duplicated).
     *
     * @return string[]
     */
    public static function suffixes(): array
    {
        $extra = self::normalizeDomainList((string) config('v2board.subscribe_ru_direct_domains', ''));

        return array_values(array_unique(array_merge(self::BASE_SUFFIXES, self::BASE_DOMAINS, $extra)));
    }

    /**
     * Happ's native routing profile, imported alongside its node links.
     * Send an empty bypass list when disabled to replace a previously imported
     * profile with the same name; omitting it would leave stale rules active.
     */
    public static function happRoutingLink(): string
    {
        $profile = [
            'Name' => config('v2board.app_name', 'V2Board') . ' DIRECT',
            'GlobalProxy' => 'true',
            'Geoipurl' => 'https://github.com/Loyalsoldier/v2ray-rules-dat/releases/latest/download/geoip.dat',
            'Geositeurl' => 'https://github.com/Loyalsoldier/v2ray-rules-dat/releases/latest/download/geosite.dat',
            'DirectSites' => self::enabled()
                ? array_merge(array_map(fn ($suffix) => 'domain:' . $suffix, self::suffixes()), ['geosite:category-ru'])
                : [],
            'DirectIp' => self::enabled() ? ['geoip:private', 'geoip:ru'] : ['geoip:private'],
            'DomainStrategy' => 'IPIfNonMatch',
        ];

        return 'happ://routing/onadd/' . base64_encode(json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
        $detour = $config['route']['final'] ?? $directTag;
        foreach ($config['outbounds'] ?? [] as $outbound) {
            if (($outbound['type'] ?? '') === 'selector' && !empty($outbound['tag'])) {
                $detour = $outbound['tag'];
                break;
            }
        }
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

        // Explicit suffixes must work independently of the contents of geosite-ru.
        $suffixRule = $makeRule(['domain_suffix' => self::suffixes()]);
        // Keep preprocessing and explicit client modes before destination rules.
        $routeRules = is_array($config['route']['rules'] ?? null) ? $config['route']['rules'] : [];
        $routeRules = array_values(array_filter(
            $routeRules,
            fn ($r) => $r !== $suffixRule && empty(array_intersect(['geosite-ru', 'geoip-ru'], (array) ($r['rule_set'] ?? [])))
        ));
        $insert = [$suffixRule, $makeRule(['rule_set' => ['geosite-ru', 'geoip-ru']])];
        $idx = 0;
        foreach ($routeRules as $i => $r) {
            if (!isset($r['clash_mode']) && !in_array($r['action'] ?? '', ['sniff', 'hijack-dns', 'resolve'], true)
                && !in_array('dns', (array) ($r['protocol'] ?? []), true)) {
                break;
            }
            $idx = $i + 1;
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

        // Custom templates may not define the local resolver used by these rules.
        $dnsServers = $config['dns']['servers'] ?? [];
        if (!in_array('local', array_column($dnsServers, 'tag'), true)) {
            $dnsServers[] = $legacy
                ? ['tag' => 'local', 'address' => 'local']
                : ['tag' => 'local', 'type' => 'local'];
            $config['dns']['servers'] = $dnsServers;
        }

        // Resolve every explicit suffix directly as well, including extras.
        // Otherwise a fake-IP or remote-DNS catch-all can win before bypassing.
        $dnsSuffixRule = ['domain_suffix' => self::suffixes()] + ($legacy
            ? ['server' => 'local']
            : ['action' => 'route', 'server' => 'local']);
        $dnsRules = is_array($config['dns']['rules'] ?? null) ? $config['dns']['rules'] : [];
        $dnsRules = array_values(array_filter(
            $dnsRules,
            fn ($r) => $r !== $dnsSuffixRule && empty(array_intersect(['geosite-ru'], (array) ($r['rule_set'] ?? [])))
        ));
        $dnsInsert = $legacy
            ? ['rule_set' => ['geosite-ru'], 'server' => 'local']
            : ['rule_set' => ['geosite-ru'], 'action' => 'route', 'server' => 'local'];
        $idx = 0;
        foreach ($dnsRules as $i => $r) {
            if (!isset($r['clash_mode']) && !isset($r['outbound'])) {
                break;
            }
            $idx = $i + 1;
        }
        array_splice($dnsRules, $idx, 0, [$dnsSuffixRule, $dnsInsert]);
        $config['dns']['rules'] = $dnsRules;

        return $config;
    }
}
