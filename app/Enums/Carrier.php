<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Courier a shipped job was handed to. Drives the buyer-facing "track with
 * {carrier}" link on the public tracker. `Other` carries no template (the raw
 * consignment ref is shown as copyable text).
 */
enum Carrier: string
{
    case SingPost = 'SINGPOST';
    case NinjaVan = 'NINJAVAN';
    case JnT = 'JNT';
    case Qxpress = 'QXPRESS';
    case Dhl = 'DHL';
    case FedEx = 'FEDEX';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::SingPost => 'SingPost',
            self::NinjaVan => 'Ninja Van',
            self::JnT => 'J&T Express',
            self::Qxpress => 'Qxpress',
            self::Dhl => 'DHL',
            self::FedEx => 'FedEx',
            self::Other => 'Other',
        };
    }

    /** URL template per carrier; null when no self-serve tracking page applies. */
    public function trackingUrl(string $ref): ?string
    {
        $enc = rawurlencode($ref);

        return match ($this) {
            self::SingPost => "https://www.singpost.com/track-items?trackingNumber={$enc}",
            self::NinjaVan => "https://www.ninjavan.co/en-sg/tracking?id={$enc}",
            self::JnT => "https://www.jtexpress.sg/index/query/gzquery.html?bills={$enc}",
            self::Qxpress => "https://www.qxpress.net/Tracking/Tracking.aspx?bill_no={$enc}",
            self::Dhl => "https://www.dhl.com/sg-en/home/tracking.html?tracking-id={$enc}",
            self::FedEx => "https://www.fedex.com/fedextrack/?trknbr={$enc}",
            self::Other => null,
        };
    }
}
