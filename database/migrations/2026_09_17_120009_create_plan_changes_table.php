<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Amounts are stored, not recomputed on demand -- recomputation would
     * silently change historical answers if proration rules changed later.
     * See docs/TECHNICAL_SPEC.md #2.
     */
    public function up(): void
    {
        Schema::create('plan_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('subscriptions');
            $table->foreignUuid('from_plan_id')->constrained('plans');
            $table->foreignUuid('to_plan_id')->constrained('plans');
            $table->timestamp('changed_at');
            $table->bigInteger('credit_paise');
            $table->bigInteger('charge_paise');
            $table->bigInteger('net_paise');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_changes');
    }
};
