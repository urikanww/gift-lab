<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 3D model licence gate (spec 6.5). Only commercial-OK licences may publish.
 *
 * Commercial-OK set (owner decision, risk explicitly accepted): every Creative
 * Commons variant - including NonCommercial (NC) and NoDerivatives (ND) - plus
 * the permissive/copyleft open-source families and OWNED. Only "all rights
 * reserved" / unknown / paid-without-purchase licences remain BLOCKED.
 *
 * NOTE: NC forbids commercial sale and ND forbids the customisation the designer
 * performs; publishing NC/ND items is a licence-compliance risk the operator has
 * chosen to accept. Flip the mapLicense routing back to Blocked to reverse it.
 */
enum License: string
{
    case Cc0 = 'CC0';
    case CcBy = 'CC_BY';
    case CcBySa = 'CC_BY_SA';
    case CcByNc = 'CC_BY_NC';
    case CcByNd = 'CC_BY_ND';
    case CcByNcSa = 'CC_BY_NC_SA';
    case CcByNcNd = 'CC_BY_NC_ND';
    case Gpl = 'GPL';
    case Lgpl = 'LGPL';
    case Bsd = 'BSD';
    case Mit = 'MIT';
    case Apache = 'APACHE_2';
    case Owned = 'OWNED';
    case Blocked = 'BLOCKED';

    public function isCommercialOk(): bool
    {
        return $this !== self::Blocked;
    }

    /**
     * Every licence except public-domain (CC0) and OWNED carries an attribution
     * or notice-retention obligation, so we require a creator credit before
     * publishing - the gate holds items on `missing_credit` otherwise.
     */
    public function requiresCreatorCredit(): bool
    {
        return match ($this) {
            self::Cc0, self::Owned, self::Blocked => false,
            default => true,
        };
    }

    /**
     * Compliance tier for superadmin-only labelling (design Phase 3):
     * - standard  : the original always-allowed set (CC0 / CC-BY / OWNED) + BLOCKED (never shown).
     * - extended  : commercial-OK with obligations (Share-Alike + open-source families).
     * - high_risk : NonCommercial / NoDerivatives - terms forbid our use; enabled by operator choice.
     *
     * @return 'standard'|'extended'|'high_risk'
     */
    public function tier(): string
    {
        return match ($this) {
            self::CcByNc, self::CcByNd, self::CcByNcSa, self::CcByNcNd => 'high_risk',
            self::CcBySa, self::Gpl, self::Lgpl, self::Bsd, self::Mit, self::Apache => 'extended',
            self::Cc0, self::CcBy, self::Owned, self::Blocked => 'standard',
        };
    }
}
