<?php

namespace MatinUtils\ProcessManager;

use MatinUtils\EasySocket\Consumer;

class WorkerHandler extends Consumer
{
    protected $processNumber, $process, $portName;
    protected $pipes = [];

    public function __construct(int $processNumber, $workerPortName)
    {
        $this->processNumber = $processNumber;
        $this->portName = $workerPortName;

        $this->startProcess();
    }

    public function startProcess()
    {
        $descriptorspec = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open(base_path() . "/artisan process-manager:worker $this->processNumber $this->portName"  , $descriptorspec, $this->pipes);
        if (get_resource_type($process) != 'process') {
            app('log')->error("Can not start Process $this->processNumber");
        } else {
            // app('log')->info("Procces $this->processNumber started");
        }
        stream_set_blocking($this->pipes[1], 0);
    }

    public function setConnection($socket)
    {
        $this->socket = $socket;
    }

    public function idle()
    {
        $this->status = 'idle';
        // app('log')->info("Worker $this->processNumber status: idle");
    }

    public function busy()
    {
        $this->status = 'busy';

        if ($this->processNumber > 3) { ///> for observation         
            app('log')->info("task given to worker $this->processNumber");
        }
    }

}
