<?php

namespace MatinUtils\ProcessManager\Commands;

use Exception;
use Illuminate\Console\Command;

class Worker extends Command
{
    protected $signature = 'process-manager:worker {processNumber} {portName}';

    protected $description = 'process-manager worker';

    protected $buffer = '', $pm;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        try {
            $this->pm = connectToSocket($this->argument('portName'));
        } catch (\Throwable $th) {
            app('log')->error("Worker " . $this->argument('processNumber') . ". PM connection unavailable. " . $th->getMessage());
            return;
        }
        // app('log')->info("Worker " . $this->argument('processNumber') . ". Connected to PM");

        $this->writeOnSocket('__WORKERSTATUSISIDLE__');
        while (true) {
            $input = socket_read($this->pm, 5000);
            if (empty($input)) {
                // app('log')->info("Worker " . $this->argument('processNumber') . ". PM broke");
                socket_close($this->pm);
                break;
            }
            $this->buffer .= $input;
            if (app('easy-socket')->messageIsCompelete($this->buffer)) {
                $response = $this->startProcess();
                $this->writeOnSocket($response);
                $this->buffer = '';
            }
            if (!$this->processOver($input)) {
                socket_close($this->pm);
                break;
            }
        }
    }

    protected function processOver($input) ///> rewritten in crawler
    {
        if (!$this->checkUp()) {
            app('log')->info("Worker " . $this->argument('processNumber') . ". exits after checkUp");
            app('log')->info("Worker " . $this->argument('processNumber') . ". Ram Usage: " . memory_get_usage(true) / (1024 * 1024) . ". input: $input");
            return false;
        }
        return true;
    }

    protected function checkUp()
    {
        if (config('processManager.maxWorkerRam', false)) {
            return memory_get_usage(true) / (1024 * 1024) <= config('processManager.maxWorkerRam');
        }
        return true;
    }

    protected function writeOnSocket($output)
    {
        socket_write($this->pm, app('easy-socket')->prepareMessage($output));
    }

    protected function startProcess() ///> rewritten in mss
    {
        $request = $this->makeRequest();
        // if (!$request->status()) {
        //     ///> this will be false if there is a problem with the input's structure and it can not be handled by the protocol's request (like when it's not a json for the protocols that need this to be jason)
        //     ///> usualy only happens when an outside client is trying to connect to thie server through this port
        //     return '__TASKDONE__';
        // }
        $response = $this->makeResponse();
        $router = $this->makeRouter($request);
        $router->handle($request, $response);
        return ($response->returnable()) ? $response->getOutput() : '__TASKDONE__';
    }

    protected function makeRequest()
    {
        $protocolAlias = config('easySocket.defaultProtocol', 'http');
        $class = config("easySocket.protocols.$protocolAlias") . '\Request';

        if (empty($class)) {
            throw new Exception("No Protocol", 1);
        }

        return new $class($this->buffer);
    }

    protected function makeResponse()
    {
        $protocolAlias = config('easySocket.defaultProtocol', 'http');
        $class = config("easySocket.protocols.$protocolAlias") . '\Response';

        if (empty($class)) {
            throw new Exception("No Protocol", 1);
        }

        return new $class();
    }

    protected function makeRouter($request)
    {
        $protocolAlias = config('easySocket.defaultProtocol', 'http');
        $class = config("easySocket.protocols.$protocolAlias") . '\Router';

        if (empty($class)) {
            throw new Exception("No Protocol", 1);
        }

        return new $class($request);
    }
}
