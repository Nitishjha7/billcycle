<?php

namespace App\Console\Commands;

use App\Billing\BillingRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BillingRun extends Command
{
    protected $signature = 'billing:run {--dry-run : Show what would be billed without creating invoices}';

    protected $description = 'Generate due invoices for active and past-due subscriptions';

    public function handle(BillingRunner $runner): int
    {
        $now = CarbonImmutable::now();

        if ($this->option('dry-run')) {
            // Deliberately not implemented as "run inside a transaction and
            // roll back" -- that would still take out real locks and run
            // real queries against invoice_sequences, which is not what a
            // dry run should do. See docs/SETUP.md.
            $this->warn('--dry-run is not yet implemented; no invoices were created or previewed.');

            return self::SUCCESS;
        }

        $result = $runner->run($now);

        $this->info(sprintf(
            'Generated %d invoice(s). %d already billed this cycle. %d skipped.',
            $result['billed'],
            $result['already_billed'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
