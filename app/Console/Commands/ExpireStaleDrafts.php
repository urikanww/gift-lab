<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\QuoteState;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Console\Command;

/**
 * Cancels DRAFT quotes that have sat untouched past the grace window. There's no
 * payment gate at order time, so junk/abandoned quote requests would otherwise
 * pile up for staff to clear by hand - this reclaims them automatically. Only
 * DRAFTs are touched; anything a buyer or staffer has progressed (SENT and
 * beyond) is left alone. Cancellation runs through QuoteService so stock is
 * returned, the action is audited, and the state change broadcasts.
 */
class ExpireStaleDrafts extends Command
{
    protected $signature = 'quotes:expire-drafts {--days=14}';

    protected $description = 'Cancel DRAFT quotes with no activity for N days (default 14).';

    public function handle(QuoteService $quotes): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $stale = Quote::query()
            ->where('state', QuoteState::Draft->value)
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($stale as $quote) {
            $quotes->cancel($quote, "Auto-expired: no activity for {$days} days.");
        }

        $this->info("Expired {$stale->count()} stale draft quote(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
