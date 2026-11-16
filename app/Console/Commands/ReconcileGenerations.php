<?php

namespace App\Console\Commands;

use App\Pixelforge\Reconciler;
use Illuminate\Console\Command;

class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile';

    protected $description = 'Resend unsent generations and poll quiet in-flight ones, so a lost webhook or job cannot leave a generation stuck';

    public function handle(Reconciler $reconciler): int
    {
        $result = $reconciler->run();

        $this->info(sprintf(
            'Resubmitted %d, polled %d, errors %d.',
            $result['resubmitted'],
            $result['polled'],
            $result['errors'],
        ));

        return self::SUCCESS;
    }
}
