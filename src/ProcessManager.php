<?php

namespace MatinUtils\ProcessManager;

use MatinUtils\EasySocket\Consumer;

class ProcessManager
{
    protected $workerPort, $clientPort;
    protected $numOfProcess;
    protected $taskQueue = [], $workerConnections = [], $clientConnections = [], $read = [];

    public function run()
    {
        $this->clientPort = serveAndListen(config('processManager.clientPort', 'client'));
        if (empty($this->clientPort)) {
            app('log')->error('Process Manager: can not serve client socket');
            return;
        }
        $this->numOfProcess = config('processManager.numOfProcess', 3);
        while (true) {
            $this->checkNumberOfWorkers();

            $this->makeReadArray();

            socket_select($this->read, $write, $except, null);

            $this->checkForNewClients();

            $this->readAllWorkers();

            $this->readAllClients();

            $this->allocateTasksToWorkers();
        }
    }

    protected function checkNumberOfWorkers()
    {
        static $workerPort;
        if (empty($workerPort)) {
            $workerPort = serveAndListen(config('processManager.workerPort', 'worker'));
        }
        for ($i = 0; $i < $this->numOfProcess; $i++) {
            if (empty($this->workerConnections[$i])) {
                $worker = new WorkerHandler($i);
                $worker->setConnection(socket_accept($workerPort));
                $this->workerConnections[$i] = $worker;
            }
        }
    }

    protected function makeReadArray()
    {
        $this->read = [$this->clientPort];
        foreach ($this->clientConnections as $clientConnection) {
            $this->read[] = $clientConnection->getSocket();
        }
        foreach ($this->workerConnections as $workerConnection) {
            $this->read[] = $workerConnection->getSocket();
        }
        return $this->read;
    }

    protected function checkForNewClients()
    {
        if (in_array($this->clientPort, $this->read)) {
            $this->clientConnections[] =  new Consumer(socket_accept($this->clientPort));
        }
    }

    protected function readAllWorkers()
    {
        foreach ($this->workerConnections as $workerKey => $workerConnection) {
            if (in_array($workerConnection->getSocket(), $this->read)) {
                $fromWorker = $workerConnection->read();
                if (!$fromWorker) {
                    if (!$workerConnection->status()) {
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
                    }
                    continue;
                }
                $workerConnection->idle();
                $fromWorker = app('easy-socket')->cleanData($fromWorker);
                if (!preg_match('/^__WORKERSTATUSISIDLE__/', $fromWorker, $output_array)) {
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
                }
            }
        }
    }

    protected function allocateTasksToWorkers()
    {
        foreach ($this->workerConnections as $workerKey => $workerConnection) {
            if ($workerConnection->status() == 'idle') {
                foreach ($this->taskQueue as $key => $task) {
                    if (!$task->isInProcess()) {
                        $workerConnection->writeOnSocket($task->input());
                        $workerConnection->busy();
                        $task->inProcess($workerKey);
                        continue 2;
                    }
                }
            }
        }
    }
}
