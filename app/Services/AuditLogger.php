<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes append-only audit entries for the events the spec requires evidence
 * for: price amendments, proof approvals, and stock re-check outcomes.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(Model $auditable, string $event, ?array $old, ?array $new): AuditLog
    {
        // Background (console/queue) mutations have no authenticated user or HTTP
        // request - record an explicit "console" source sentinel instead of a
        // null that reads like missing data, preserving who/what/where evidence.
        $inConsole = app()->runningInConsole();

        return AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_type' => $auditable::class,
            'auditable_id' => $auditable->getKey(),
            'event' => $event,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $inConsole ? 'console' : Request::ip(),
        ]);
    }
}
