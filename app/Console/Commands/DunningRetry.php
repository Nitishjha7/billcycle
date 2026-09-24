<?php

namespace App\Console\Commands;

use App\Billing\DunningRetryRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DunningRetry extends Command
{
    protected $signature = 'dunning:retry';

    protected $description = 'Re-attempt payment for invoices whose scheduled retry is due';

    public function handle(DunningRetryRunner $runner): int
    {
        $count = $runner->run(CarbonImmutable::now());

        $this->info("Retried {$count} invoice(s).");

        return self::SUCCESS;
    }
}
