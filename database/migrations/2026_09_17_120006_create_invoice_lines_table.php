<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->string('description');

            // Signed -- credits are negative lines on a normal invoice rather
            // than a separate credit-note entity, so an invoice always reads
            // top to bottom as an arithmetic sum. See docs/TECHNICAL_SPEC.md #2.
            $table->bigInteger('amount_paise');

            $table->enum('type', ['subscription', 'proration_credit', 'proration_charge']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
