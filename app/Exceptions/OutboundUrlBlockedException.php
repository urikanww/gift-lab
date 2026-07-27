<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Support\OutboundUrlGuard when a staff-supplied URL (or a
 * redirect hop it leads to) is unsafe to fetch server-side: a non-http(s)
 * scheme, a host outside a configured allowlist, or a hostname/IP that
 * resolves into private/loopback/link-local/CGNAT/metadata address space.
 * Callers treat this the same as any other network failure (capture just
 * degrades to null) - the message is safe to log but not to leak verbatim to
 * an untrusted client since it echoes back the offending host/IP.
 */
class OutboundUrlBlockedException extends RuntimeException {}
