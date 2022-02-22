<?php

namespace MatinUtils\ProcessManager\Commands;

use Illuminate\Console\Command;

class Run extends Command
{
    protected $signature = 'process-manager:run';

    protected $description = 'run process-manager';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        return app('process-manager')->run();
    }
}
