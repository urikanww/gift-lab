<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Quote;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the staff console when a buyer checks out a line that asks the team
 * to do the design (customization.mode === 'buyer_uploaded'): the buyer handed
 * us reference images + placement notes instead of laying it out themselves, so
 * a human must produce the artwork and stage a proof.
 *
 * Lands on the shared staff.queue channel (same channel as ProofChangesRequested
 * and the production/queue alerts) so every operator gets the live nudge whichever
 * order they happen to be looking at. The frontend turns it into a toast + a
 * Quotes badge refresh.
 *
 * @phpstan-type LineBrief array{product_name: string, qty: int}
 */
class DesignRequested implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<int, array{product_name: string, qty: int}>  $lines
     */
    public function __construct(
        public readonly Quote $quote,
        public readonly array $lines,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff.queue')];
    }

    public function broadcastAs(): string
    {
        return 'design.requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'quote_id' => $this->quote->id,
            // Displayed identifier; the store keys realtime refreshes off it.
            'quote_reference' => $this->quote->reference,
            'line_count' => count($this->lines),
            'products' => array_map(
                static fn (array $l): string => $l['product_name'],
                $this->lines,
            ),
        ];
    }
}
