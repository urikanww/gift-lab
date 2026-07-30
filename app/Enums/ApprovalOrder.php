<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which approval the buyer gives first. price_first (default) is the historic
 * flow: agree the price, then sign off the artwork. proof_first inverts it for
 * jobs where the art must be approved before the price is agreed. Consulted by
 * QuoteService's send/sendProofs/accept guards; a no-op on a plain-stock order
 * that has no proof-needing line.
 */
enum ApprovalOrder: string
{
    case PriceFirst = 'price_first';
    case ProofFirst = 'proof_first';
}
