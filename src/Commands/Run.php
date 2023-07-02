<?php

namespace MatinUtils\ProcessManager\Commands;

use Illuminate\Console\Command;

class Run extends Command
{
    protected $signature = 'process-manager:run';

    protected $description = 'run process-manager';

    public function handle()
    {
        $clientPort = config('processManager.clientPort', 'client');
        $workerPort = config('processManager.workerPort', 'worker');
        $workerCount = config('processManager.numOfProcess', 3);
        return app('process-manager')->run($clientPort, $workerPort, $workerCount);
    }
}
