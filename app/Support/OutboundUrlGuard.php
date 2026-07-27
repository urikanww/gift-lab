<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\OutboundUrlBlockedException;
use Closure;

/**
 * SSRF guard for staff-supplied URLs the server fetches on their behalf
 * (capture-on-browse today; anything else that does Http::get($userUrl)
 * tomorrow). Two independent controls:
 *
 *  1. An optional host allowlist (config('services.catalogue.capture.allowlist'))
 *     - empty means "no allowlist", not "block everything". The allowlist is a
 *     coarse narrowing knob for operators who want to lock capture down to
 *     known suppliers; it is NOT the safety boundary.
 *  2. The private/loopback/link-local/CGNAT/metadata IP block - this is the
 *     real control, and it applies unconditionally, including to allowlisted
 *     hosts, because DNS answers are not trustworthy (a host can resolve
 *     differently at different times - "DNS rebinding").
 *
 * assertSafe() MUST be called for the initial URL AND for every redirect hop
 * ListingCapture follows - a hostname can resolve to a public IP on the first
 * check and a redirect can still point at 169.254.169.254 (the cloud metadata
 * endpoint) or any RFC1918 address on the private network the app runs in.
 */
final class OutboundUrlGuard
{
    /**
     * Hard-blocked regardless of allowlist. Order doesn't matter; classify()
     * returns the first match.
     *
     * @var array<string, string>
     */
    private const IPV4_RANGES = [
        '0.0.0.0/8' => 'reserved',        // "this network"
        '10.0.0.0/8' => 'private',        // RFC1918
        '100.64.0.0/10' => 'cgnat',       // RFC6598 shared/carrier-grade NAT
        '127.0.0.0/8' => 'loopback',
        '169.254.0.0/16' => 'link-local', // includes the 169.254.169.254 metadata IP
        '172.16.0.0/12' => 'private',     // RFC1918
        '192.0.0.0/24' => 'reserved',     // IETF protocol assignments
        '192.0.2.0/24' => 'reserved',     // TEST-NET-1
        '192.168.0.0/16' => 'private',    // RFC1918
        '198.18.0.0/15' => 'reserved',    // benchmarking
        '198.51.100.0/24' => 'reserved',  // TEST-NET-2
        '203.0.113.0/24' => 'reserved',   // TEST-NET-3
        '224.0.0.0/4' => 'reserved',      // multicast
        '240.0.0.0/4' => 'reserved',      // reserved/future use
        '255.255.255.255/32' => 'reserved',
    ];

    /** Explicit belt-and-braces block even if a future range table edit drops it. */
    private const METADATA_IP = '169.254.169.254';

    /**
     * Test-only DNS override: callable(string $host): list<string> returning
     * resolved IP strings. Left null in production, where real A/AAAA lookups
     * run. Tests should set/reset this rather than hitting real DNS.
     */
    public static ?Closure $resolver = null;

    /**
     * @throws OutboundUrlBlockedException
     */
    public static function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_array($parts) || $host === null || $host === '') {
            throw new OutboundUrlBlockedException("Refusing to fetch an unparseable URL: \"{$url}\".");
        }

        // parse_url() keeps the brackets on a bracketed IPv6 literal host
        // ("[::1]") - strip them so the IP-literal fast path (and CIDR/range
        // classification) sees the bare address, not a string that fails
        // FILTER_VALIDATE_IP and would otherwise fall through to DNS
        // resolution for what is already a literal address.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new OutboundUrlBlockedException("Refusing to fetch a non-http(s) URL scheme: \"{$scheme}\".");
        }

        $allowlist = array_map('strtolower', self::allowlist());
        if ($allowlist !== [] && ! in_array(strtolower($host), $allowlist, true)) {
            throw new OutboundUrlBlockedException("Refusing to fetch a host outside the configured allowlist: \"{$host}\".");
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            throw new OutboundUrlBlockedException("Could not resolve host: \"{$host}\".");
        }

        foreach ($ips as $ip) {
            if (self::classify($ip) !== 'public') {
                throw new OutboundUrlBlockedException(
                    "Refusing to fetch \"{$host}\": resolves to a non-public address ({$ip})."
                );
            }
        }
    }

    /**
     * Pure classification of a single IP literal (v4 or v6, including
     * IPv4-mapped IPv6 like "::ffff:169.254.169.254"). Returns one of:
     * 'public', 'private', 'loopback', 'link-local', 'cgnat', 'reserved',
     * 'metadata', 'invalid'. Only 'public' is safe to fetch.
     */
    public static function classify(string $ip): string
    {
        $ip = trim($ip);

        $mapped = self::unwrapIpv4MappedV6($ip);
        if ($mapped !== null) {
            $ip = $mapped;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::classifyV4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::classifyV6($ip);
        }

        return 'invalid';
    }

    private static function classifyV4(string $ip): string
    {
        if ($ip === self::METADATA_IP) {
            return 'metadata';
        }

        foreach (self::IPV4_RANGES as $cidr => $label) {
            if (self::ipv4InCidr($ip, $cidr)) {
                return $label;
            }
        }

        return 'public';
    }

    private static function classifyV6(string $ip): string
    {
        $lower = strtolower($ip);

        if ($lower === '::1') {
            return 'loopback';
        }
        if ($lower === '::') {
            return 'reserved';
        }

        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return 'invalid';
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        // fc00::/7 - unique local addresses (RFC4193), the IPv6 analogue of RFC1918.
        if (($first & 0xfe) === 0xfc) {
            return 'private';
        }

        // fe80::/10 - link-local.
        if ($first === 0xfe && ($second & 0xc0) === 0x80) {
            return 'link-local';
        }

        return 'public';
    }

    /**
     * Unwraps "::ffff:a.b.c.d" (RFC4291 IPv4-mapped IPv6) to the embedded
     * IPv4 literal, else null. Deliberately does NOT also treat the
     * deprecated "::a.b.c.d" IPv4-compatible form as a match: that form's
     * all-zero 10-11 byte prefix collides with genuine IPv6 addresses like
     * "::1" (loopback, packs to 15 zero bytes + 0x01, i.e. indistinguishable
     * from "::0.0.0.1") - misclassifying real IPv6 loopback as IPv4 "0.0.0.1"
     * would UNDER-block, which is the wrong direction for a security guard.
     */
    private static function unwrapIpv4MappedV6(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        if (substr($packed, 0, 10) !== str_repeat("\x00", 10) || substr($packed, 10, 2) !== "\xff\xff") {
            return null;
        }

        $v4 = inet_ntop(substr($packed, 12, 4));

        return $v4 === false ? null : $v4;
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);
            if (! is_array($records)) {
                continue;
            }
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        return $ips;
    }

    /** @return list<string> */
    private static function allowlist(): array
    {
        /** @var mixed $configured */
        $configured = config('services.catalogue.capture.allowlist', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter($configured, fn ($v) => is_string($v) && $v !== ''));
    }
}
