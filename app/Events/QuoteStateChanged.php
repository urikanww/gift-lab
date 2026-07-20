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
 * Pushed to the owning company's private channel whenever a quote changes
 * state, so the buyer's screen updates without polling (Reverb-only mandate).
 */
class QuoteStateChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Quote $quote,
        public readonly string $previousState,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("company.{$this->quote->company_id}")];
    }

    public function broadcastAs(): string
    {
        return 'quote.state-changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'quote_id' => $this->quote->id,
            // Displayed identifier; quote_id stays as the store's join key.
            'quote_reference' => $this->quote->reference,
            'state' => $this->quote->state->value,
            'previous_state' => $this->previousState,
            'total' => $this->quote->total,
            'currency' => $this->quote->currency,
        ];
    }
}
