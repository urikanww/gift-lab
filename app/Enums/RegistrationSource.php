<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a user record came into being - the basis for its PDPA consent state.
 * A null consented_at is only meaningful alongside this: self_registered rows
 * carry real consent, staff_created rows rely on the Business Contact
 * Information exemption (no consent needed), and legacy rows predate consent
 * capture and are the only re-consent targets.
 */
enum RegistrationSource: string
{
    case SelfRegistered = 'self_registered';
    case StaffCreated = 'staff_created';
    // DB default. Backfills pre-consent rows, and the only re-consent target.
    // Footgun: any NEW user-creation path that forgets to stamp a source reads
    // as legacy too - stamp explicitly (self_registered / staff_created) when
    // adding one, or it will be miscategorised for re-consent.
    case Legacy = 'legacy';
}
