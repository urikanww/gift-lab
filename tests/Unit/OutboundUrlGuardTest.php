<?php

declare(strict_types=1);

use App\Exceptions\OutboundUrlBlockedException;
use App\Support\OutboundUrlGuard;

afterEach(function (): void {
    OutboundUrlGuard::$resolver = null;
    config(['services.catalogue.capture.allowlist' => []]);
});

// ---- assertSafe: scheme -------------------------------------------------

it('rejects a non-http(s) scheme', function (string $url): void {
    expect(fn () => OutboundUrlGuard::assertSafe($url))->toThrow(OutboundUrlBlockedException::class);
})->with([
    'ftp://blankco.sg/mug.jpg',
    'file:///etc/passwd',
]);

it('rejects an unparseable url', function (): void {
    expect(fn () => OutboundUrlGuard::assertSafe('not a url at all'))
        ->toThrow(OutboundUrlBlockedException::class);
});

// ---- assertSafe: IP-literal hosts (no DNS needed) ------------------------

it('rejects loopback, private, link-local, metadata and CGNAT IPv4 hosts', function (string $url): void {
    expect(fn () => OutboundUrlGuard::assertSafe($url))->toThrow(OutboundUrlBlockedException::class);
})->with([
    'http://127.0.0.1/',
    'http://169.254.169.254/latest/meta-data/', // cloud metadata endpoint
    'http://10.0.0.5/',
    'http://192.168.1.1/',
    'http://172.16.4.4/',
    'http://100.64.0.1/', // CGNAT (RFC6598)
]);

it('rejects loopback IPv6 (::1)', function (): void {
    expect(fn () => OutboundUrlGuard::assertSafe('http://[::1]/'))
        ->toThrow(OutboundUrlBlockedException::class);
});

it('rejects an IPv4-mapped IPv6 metadata address', function (): void {
    expect(fn () => OutboundUrlGuard::assertSafe('http://[::ffff:169.254.169.254]/'))
        ->toThrow(OutboundUrlBlockedException::class);
});

it('allows a public IPv4 host', function (): void {
    expect(fn () => OutboundUrlGuard::assertSafe('http://8.8.8.8/'))->not->toThrow(OutboundUrlBlockedException::class);
});

// ---- assertSafe: hostnames via the resolver override ---------------------

it('blocks a hostname that resolves to a private address', function (): void {
    OutboundUrlGuard::$resolver = fn (string $host): array => $host === 'internal.evil.test' ? ['10.0.0.9'] : [];

    expect(fn () => OutboundUrlGuard::assertSafe('https://internal.evil.test/x'))
        ->toThrow(OutboundUrlBlockedException::class);
});

it('allows a hostname that resolves to a public address', function (): void {
    OutboundUrlGuard::$resolver = fn (string $host): array => $host === 'blankco.sg' ? ['93.184.216.34'] : [];

    expect(fn () => OutboundUrlGuard::assertSafe('https://blankco.sg/mug'))->not->toThrow(OutboundUrlBlockedException::class);
});

it('blocks a hostname that fails to resolve', function (): void {
    OutboundUrlGuard::$resolver = fn (string $host): array => [];

    expect(fn () => OutboundUrlGuard::assertSafe('https://nowhere.invalid/x'))
        ->toThrow(OutboundUrlBlockedException::class);
});

// ---- allowlist -------------------------------------------------------------

it('honours a configured host allowlist, blocking hosts outside it', function (): void {
    config(['services.catalogue.capture.allowlist' => ['blankco.sg']]);
    OutboundUrlGuard::$resolver = fn (string $host): array => ['93.184.216.34'];

    expect(fn () => OutboundUrlGuard::assertSafe('https://blankco.sg/mug'))->not->toThrow(OutboundUrlBlockedException::class)
        ->and(fn () => OutboundUrlGuard::assertSafe('https://other.example/mug'))->toThrow(OutboundUrlBlockedException::class);
});

it('still blocks an allowlisted host that resolves privately (allowlist is not the safety boundary)', function (): void {
    config(['services.catalogue.capture.allowlist' => ['internal.example']]);
    OutboundUrlGuard::$resolver = fn (string $host): array => ['127.0.0.1'];

    expect(fn () => OutboundUrlGuard::assertSafe('https://internal.example/x'))
        ->toThrow(OutboundUrlBlockedException::class);
});

it('allows any public host when the allowlist is empty', function (): void {
    config(['services.catalogue.capture.allowlist' => []]);
    OutboundUrlGuard::$resolver = fn (string $host): array => ['93.184.216.34'];

    expect(fn () => OutboundUrlGuard::assertSafe('https://any-supplier.example/x'))->not->toThrow(OutboundUrlBlockedException::class);
});

// ---- classify() unit tests -------------------------------------------------

it('classifies IPv4 addresses correctly', function (string $ip, string $expected): void {
    expect(OutboundUrlGuard::classify($ip))->toBe($expected);
})->with([
    ['127.0.0.1', 'loopback'],
    ['169.254.169.254', 'metadata'],
    ['169.254.1.1', 'link-local'],
    ['10.1.2.3', 'private'],
    ['172.16.0.1', 'private'],
    ['172.31.255.255', 'private'],
    ['172.32.0.1', 'public'], // just outside 172.16.0.0/12
    ['192.168.0.1', 'private'],
    ['100.64.0.1', 'cgnat'],
    ['100.127.255.255', 'cgnat'],
    ['100.128.0.1', 'public'], // just outside 100.64.0.0/10
    ['0.0.0.0', 'reserved'],
    ['8.8.8.8', 'public'],
    ['93.184.216.34', 'public'],
]);

it('classifies IPv6 addresses correctly', function (string $ip, string $expected): void {
    expect(OutboundUrlGuard::classify($ip))->toBe($expected);
})->with([
    ['::1', 'loopback'],
    ['::', 'reserved'],
    ['fe80::1', 'link-local'],
    ['fc00::1', 'private'],
    ['fd12:3456:789a::1', 'private'],
    ['2001:4860:4860::8888', 'public'], // Google DNS
]);

it('unwraps IPv4-mapped IPv6 before classifying', function (): void {
    expect(OutboundUrlGuard::classify('::ffff:169.254.169.254'))->toBe('metadata')
        ->and(OutboundUrlGuard::classify('::ffff:10.0.0.1'))->toBe('private')
        ->and(OutboundUrlGuard::classify('::ffff:8.8.8.8'))->toBe('public');
});

it('treats garbage input as invalid', function (): void {
    expect(OutboundUrlGuard::classify('not-an-ip'))->toBe('invalid');
});
