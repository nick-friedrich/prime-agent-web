<?php

use App\Services\PrimeAgentRuntime;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('prime:start', function (PrimeAgentRuntime $runtime) {
    if (! $runtime->isAvailable()) {
        $this->warn('Prime Agent is not installed. The dashboard will show setup instructions.');

        return 0;
    }

    $daemon = $runtime->ensureDaemon();

    if ($daemon['online']) {
        $this->info('Prime Agent runtime is online.');
    } else {
        $this->warn('Prime Agent could not start: '.($daemon['error'] ?: 'Unknown error'));
    }

    return 0;
})->purpose('Start the local Prime Agent daemon when available');
