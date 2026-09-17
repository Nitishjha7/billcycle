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
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('subscriptions');
            $table->string('number')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('subtotal_paise');
            $table->bigInteger('total_paise');
            $table->enum('status', ['open', 'paid', 'void'])->default('open');
            $table->timestamp('issued_at');
            $table->timestamps();

            // This is what makes the billing job idempotent -- see
            // docs/TECHNICAL_SPEC.md #4. The second run of billing:run for the
            // same period attempts the same insert, the database rejects it,
            // and the job counts that as "already billed". Not a
            // SELECT ... IF NOT EXISTS check, because that has a race window.
            $table->unique(['subscription_id', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
