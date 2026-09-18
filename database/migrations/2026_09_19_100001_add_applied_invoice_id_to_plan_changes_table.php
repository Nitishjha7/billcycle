<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A downgrade's credit is recorded on plan_changes at the moment the
     * change is applied, but the credit itself isn't owed to the customer
     * until it lands as a line on a real invoice -- billing:run does that
     * on the subscription's next cycle. This column marks when (and on
     * which invoice) that happened, so a credit is never carried forward
     * twice.
     */
    public function up(): void
    {
        Schema::table('plan_changes', function (Blueprint $table) {
            $table->foreignUuid('applied_invoice_id')->nullable()->constrained('invoices');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plan_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_invoice_id');
        });
    }
};
