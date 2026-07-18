<?php

declare(strict_types=1);

namespace App\Services\Courier;

final readonly class CourierShipment
{
    public function __construct(
        public string $reference,       // our order/quote ref, echoed to the carrier
        public string $recipientName,
        public string $phone,
        public ?string $email,
        public string $line1,
        public ?string $line2,
        public ?string $city,
        public ?string $state,
        public string $postalCode,
        public string $country,
        public ?string $notes,
        public int $parcelCount,
    ) {}
}
