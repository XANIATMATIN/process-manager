<?php

namespace MatinUtils\ProcessManager\Commands;

use Exception;
use Illuminate\Console\Command;

class Worker extends Command
{
    protected $signature = 'process-manager:worker {processNumber}';

    protected $description = 'process-manager worker';

    protected $buffer = '', $pm;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        try {
            $this->pm = socket_create(AF_UNIX, SOCK_STREAM, 0);
            socket_connect($this->pm, base_path('bootstrap/easySocket/worker.sock'));
        } catch (\Throwable $th) {
            app('log')->error("Worker " . $this->argument('processNumber') . ". PM connection unavailable. " . $th->getMessage());
            return;
        }
        app('log')->info("Worker " . $this->argument('processNumber') . ". Connected to PM");

        $this->writeOnSocket('idle');
        while (true) {
            $input = socket_read($this->pm, 5000);

            if (empty($input)) {
                app('log')->info("Worker " . $this->argument('processNumber') . ". PM broke");
                socket_close($this->pm);
                break;
            }

            $this->buffer .= $input;

            $length = strlen($this->buffer);
            if ($this->buffer[$length - 1] == "\0") {
                // app('log')->info("Worker " . $this->argument('processNumber') . ". Reveived. " . strlen($this->buffer) . " bytes");
                // app('log')->info("Worker " . $this->argument('processNumber') . ". Reveived. " . $this->buffer);
                $response = $this->startProcess();
                $this->writeOnSocket($response);
                $this->buffer = '';
            }
        }
    }

    protected function writeOnSocket($output)
    {
        $data = $output . "\0";
        socket_write($this->pm, $data);
    }

    protected function startProcess()
    {
        $request = $this->makeRequest();
        $response = $this->makeResponse();
        $router = $this->makeRouter();
        $router->handle($request, $response);
        return ($response->returnable()) ? $response->getOutput() : 'idle';
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

    protected function makeRouter()
    {
        $protocolAlias = config('easySocket.defaultProtocol', 'http');
        $class = config("easySocket.protocols.$protocolAlias") . '\Router';

        if (empty($class)) {
            throw new Exception("No Protocol", 1);
        }

        return new $class();
    }
}
