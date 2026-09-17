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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers');
            $table->foreignUuid('plan_id')->constrained('plans');

            // Stored, not derived -- past_due depends on payment history and
            // suspended on retries exhausted, neither of which is a function
            // of the calendar alone. See docs/TECHNICAL_SPEC.md #2.
            $table->enum('status', ['trialing', 'active', 'past_due', 'suspended', 'cancelled']);

            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->date('trial_ends_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
