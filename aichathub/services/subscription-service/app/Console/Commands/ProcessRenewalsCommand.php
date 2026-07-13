<?php

namespace App\Console\Commands;

use App\Jobs\ProcessRenewalJob;
use App\Models\UserSubscription;
use Illuminate\Console\Command;

class ProcessRenewalsCommand extends Command
{
    protected $signature   = 'renewals:process';
    protected $description = 'Process all subscriptions due for renewal';

    public function handle(): void
    {
        $due = UserSubscription::where('status', 'active')
            ->where('auto_renew', true)
            ->where('renews_at', '<=', now())
            ->get();

        if ($due->isEmpty()) {
            $this->info('No renewals due.');
            return;
        }

        foreach ($due as $subscription) {
            ProcessRenewalJob::dispatch($subscription);
        }

        $this->info("Dispatched {$due->count()} renewal job(s).");
    }
}
