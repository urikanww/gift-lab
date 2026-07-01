<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B buyer companies. Admin-provisioned; buyers are seated against a company.
 * created_by is a plain nullable column here (users table is created after
 * companies); its foreign key is added in a later migration to break the
 * companies <-> users circular dependency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('registration_no')->nullable()->comment('e.g. SG UEN');
            $table->string('billing_email');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('default_terms')->nullable()->comment('default PO payment terms');
            $table->enum('status', ['ACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
