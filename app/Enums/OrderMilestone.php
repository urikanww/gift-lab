<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The points in an order's life a buyer is told about.
 *
 * Before this the application sent exactly two emails — on send, and on the
 * first proof — and staff carried every other milestone by hand, on the phone.
 * Each case here is one of those calls stopped being manual.
 *
 * The enum is the registry: adding a case and its copy is all a new milestone
 * needs, and the settings screen enumerates it rather than keeping its own list
 * that could drift out of step.
 */
enum OrderMilestone: string
{
    case Accepted = 'accepted';
    case ArtworkApproved = 'artwork_approved';
    case ProofIssued = 'proof_issued';
    case Committed = 'committed';
    case InProduction = 'in_production';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case LineChanged = 'line_changed';

    /** Subject line for the buyer's email. */
    public function subject(string $reference): string
    {
        return match ($this) {
            self::Accepted => "We've received your acceptance — order {$reference}",
            self::ArtworkApproved => "Artwork approved — one step left on order {$reference}",
            self::ProofIssued => "Your proof is ready to review — order {$reference}",
            self::Committed => "Your order is confirmed — {$reference}",
            self::InProduction => "Your order is now in production — {$reference}",
            self::Shipped => "Your order is on its way — {$reference}",
            self::Delivered => "Your order has been delivered — {$reference}",
            self::Cancelled => "Your order has been cancelled — {$reference}",
            self::LineChanged => "A change to your order — {$reference}",
        };
    }

    /** The body copy. Plain, and honest about what the buyer must do next. */
    public function body(): string
    {
        return match ($this) {
            self::Accepted => 'Thanks for accepting your quote. We’re preparing your artwork proof and will send it over shortly.',
            self::ArtworkApproved => 'Thanks for approving the artwork. There’s one step left: please review and accept the pricing to confirm your order.',
            self::ProofIssued => 'A new proof is ready for you to review. Please approve it, or tell us what to change.',
            self::Committed => 'Your order is confirmed and scheduled for production. We’ll let you know when it starts.',
            self::InProduction => 'Your order is now being made. We’ll be in touch as soon as it ships.',
            self::Shipped => 'Your order has left us and is on its way.',
            self::Delivered => 'Your order has been delivered. Thanks for working with us.',
            self::Cancelled => 'Your order has been cancelled. If that’s unexpected, please get in touch and we’ll sort it out.',
            self::LineChanged => 'One or more items on your order have changed. The updated details are on your order page.',
        };
    }

    /**
     * Whether this milestone emails unless switched off.
     *
     * LineChanged defaults OFF: staff contact the client themselves about a
     * dropped or re-priced item, because that conversation needs a person.
     */
    public function enabledByDefault(): bool
    {
        return $this !== self::LineChanged;
    }
}
