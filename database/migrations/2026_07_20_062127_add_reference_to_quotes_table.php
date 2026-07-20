<?php

declare(strict_types=1);

use App\Models\Quote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            // Opaque, non-enumerable order reference used in buyer/public URLs
            // (/orders/{reference}) so numeric ids never leak. Unique; the model
            // assigns one on create.
            $table->string('reference', 24)->nullable()->unique()->after('tracking_code');
        });

        // Backfill existing rows (including soft-deleted) with a unique reference.
        Quote::withTrashed()->whereNull('reference')->get()->each(function (Quote $quote): void {
            do {
                $code = Quote::generateReference();
            } while (Quote::withTrashed()->where('reference', $code)->exists());
            $quote->reference = $code;
            $quote->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });
    }
};
