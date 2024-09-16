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
    }

    public function startProcess()
    {
        $descriptorspec = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open($this->makeCommand()  , $descriptorspec, $this->pipes);
        if (get_resource_type($process) != 'process') {
            app('log')->error("Can not start Process $this->processNumber");
        } else {
            // app('log')->info("Procces $this->processNumber started");
        }
        stream_set_blocking($this->pipes[1], 0);
    }

    protected function makeCommand()
    {
        return base_path() . "/artisan process-manager:worker $this->processNumber $this->portName";
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

        if ($this->processNumber > 250) { ///> for observation         
            app('log')->info("task given to worker $this->processNumber");
        }
    }

}
