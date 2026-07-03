<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opaque per-quote tracking code for the login-free tracking page. Sequential
 * quote ids are guessable; this random code (paired with an email-prefix check
 * at lookup) is the anti-enumeration handle a customer uses to follow an order
 * without an account.
 */
return new class extends Migration
{
    /** Crockford-style base32 without ambiguous glyphs (I, L, O, U). */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('tracking_code', 16)->nullable()->unique()->after('id');
        });

        // Backfill existing quotes with unique codes so old orders are trackable.
        foreach (DB::table('quotes')->pluck('id') as $id) {
            do {
                $code = $this->code();
            } while (DB::table('quotes')->where('tracking_code', $code)->exists());

            DB::table('quotes')->where('id', $id)->update(['tracking_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn('tracking_code');
        });
    }

    private function code(): string
    {
        $out = 'GL-';
        for ($i = 0; $i < 6; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }
};
