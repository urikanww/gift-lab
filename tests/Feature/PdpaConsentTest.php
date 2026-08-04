<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('has the pdpa consent columns and a policy version', function (): void {
    expect(Schema::hasColumn('users', 'consented_at'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'consent_policy_version'))->toBeTrue()
        ->and(Schema::hasColumn('quotes', 'recipient_consent_ack_at'))->toBeTrue()
        ->and(Schema::hasColumn('quotes', 'recipient_consent_version'))->toBeTrue()
        ->and(config('privacy.version'))->not->toBeNull();
});
