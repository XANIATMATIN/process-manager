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
        }
    }

    protected function writeOnSocket($output)
    {
        socket_write($this->pm, app('easy-socket')->prepareMessage($output));
    }

    protected function startProcess()
    {
        $request = $this->makeRequest();
        $response = $this->makeResponse();
        $router = $this->makeRouter($request);
        $router->handle($request, $response);
        return ($response->returnable()) ? $response->getOutput() : '__WORKERSTATUSISIDLE__';
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
