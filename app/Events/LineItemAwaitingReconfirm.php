<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\LineItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Procurement re-check surfaced a qty shortfall or price jump. Pushed to staff
 * so an admin can reconfirm/amend/drop without polling.
 */
class LineItemAwaitingReconfirm implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  string  $reason  qty_short | price_jumped
     */
    public function __construct(
        public readonly LineItem $lineItem,
        public readonly string $reason,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff.procurement')];
    }

    public function broadcastAs(): string
    {
        return 'line-item.awaiting-reconfirm';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'line_item_id' => $this->lineItem->id,
            'quote_id' => $this->lineItem->quote_id,
            'reason' => $this->reason,
            'ordered_qty' => $this->lineItem->qty,
            'procured_qty' => $this->lineItem->procured_qty,
            'unit_price' => $this->lineItem->unit_price,
            'procured_price' => $this->lineItem->procured_price,
        ];
    }
}
