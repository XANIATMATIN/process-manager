<?php

namespace MatinUtils\ProcessManager;

use Exception;
use MatinUtils\EasySocket\ClientHandler;

class ProcessManager
{
    protected $workerPort, $clientPort;
    protected $numOfProcess;
    protected $pipes = [], $workers = [], $taskQueue = [], $workerStatus = [], $workerConnections = [], $clientConnections = [], $read = [];

    public function run()
    {
        $this->clientPort = serveAndListen('client');
        if (empty($this->clientPort)) {
            return;
        }
        
        $this->numOfProcess = config('processManager.numOfProcess', 1);

        while (true) {
            $this->checkNumberOfWorkers();

            $this->makeeReadArray();

            socket_select($this->read, $write, $except, null);

            $this->checkForNewClients();

            $this->readAllWorkers();

            $this->readAllClients();

            $this->allocateTasksToWorkers();

            // dump('taskQueue count: ' . count($this->taskQueue) . '. clint count: ' . count($this->clientConnections) . '. workers count: ' . count($this->workerConnections));
        }
    }

    protected function checkNumberOfWorkers()
    {
        static $workerPort;
        if (empty($workerPort)) {
            $workerPort = serveAndListen('worker');
        }
        for ($i = 0; $i < $this->numOfProcess; $i++) {
            if (empty($this->workerConnections[$i])) {
                $this->workers[$i] = $this->startProcess($i);
                $this->workerConnections[$i] = socket_accept($workerPort);
            }
        }
    }

    public function startProcess($processNumber)
    {
        $descriptorspec = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $worker = proc_open(base_path() . '/artisan process-manager:worker ' . $processNumber, $descriptorspec, $this->pipes[$processNumber]);
        if (get_resource_type($worker) != 'process') {
            app('log')->error("Can not start Process $processNumber");
        } else {
            // dump("Procces $processNumber started");
        }
        stream_set_blocking($this->pipes[$processNumber][1], 0);
        return $worker;
    }

    protected function makeeReadArray()
    {
        $this->read = array_merge($this->workerConnections, [$this->clientPort]);
        foreach ($this->clientConnections as $clientConnection) {
            $this->read[] = $clientConnection->getSocket();
        }
        return $this->read;
    }

    protected function checkForNewClients()
    {
        if (in_array($this->clientPort, $this->read)) {
            $this->clientConnections[] =  new ClientHandler(socket_accept($this->clientPort));
            // dump('new client');
        }
    }

    protected function readAllWorkers()
    {
        foreach ($this->workerConnections as $workerKey => $workerConnection) {
            if (in_array($workerConnection, $this->read)) {
                try {
                    $fromWorker = socket_read($workerConnection, 1024);
                } catch (\Throwable $th) {
                }
                if (empty($fromWorker)) {
                    unset($this->workerConnections[$workerKey]);
                    foreach ($this->taskQueue as $key => $task) {
                        if ($task->isGivenToWorker($workerKey)) {
                            if ($task->MaxedTries()) {
                                app('log')->error("woker $workerKey broke");
                                app('log')->info($task->getProperties());
                                unset($this->taskQueue[$key]);
                                continue;
                            }
                            $task->removeWorkerKey();
                        }
                    }
                    app('log')->error("woker $workerKey broke");
                    continue;
                }

                $this->workerStatus[$workerKey] = 'idle';
                // dump("Worker $workerKey status: idle");

                if ($fromWorker != 'idle') {
                    foreach ($this->taskQueue as $key => $task) {
                        if ($task->isGivenToWorker($workerKey)) {
                            $task->client->writeOnSocket($fromWorker);
                            unset($this->taskQueue[$key]);
                        }
                    }
                }
            }
        }
    }

    protected function readAllClients()
    {
        foreach ($this->clientConnections as $clientKey => $clientConnection) {
            if (in_array($clientConnection->getSocket(), $this->read)) {
                $input = $clientConnection->read();
                if (!$input) {
                    if (!$clientConnection->status()) {
                        unset($this->clientConnections[$clientKey]);
                    }
                } else {
                    $this->taskQueue[] = new Task($clientConnection, $clientKey, $input);

                    // dump("new request from client $clientKey. " . strlen($input) . " bytes");
                }
            }
        }
    }

    protected function allocateTasksToWorkers()
    {
        foreach ($this->workerStatus as $workerKey => $status) {
            if ($status == 'idle') {
                foreach ($this->taskQueue as $key => $task) {
                    if (!$task->isInProcess()) {
                        socket_write($this->workerConnections[$workerKey], $task->input());
                        $this->workerStatus[$workerKey] = 'busy';
                        $task->inProcess($workerKey);

                        if ($workerKey > 1) { ///> for observation         
                            app('log')->info("task given to worker $workerKey: " . strlen($task->input()) . " bytes");
                        }
                        // dump("task given to worker $workerKey: " . strlen($task->input()) . " bytes");
                        continue 2;
                    }
                }
            }
        }
    }
}
