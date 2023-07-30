<?php

namespace MatinUtils\ProcessManager;

use MatinUtils\EasySocket\Consumer;
use MatinUtils\ProcessManager\ServiceOrders\Logics\ShowStat;
use MatinUtils\ProcessManager\ServiceOrders\Orders;

class ProcessManager 
{
    use ShowStat;
    protected $workerPort, $clientPort;
    protected $serviceOrders;
    protected $numOfProcess = 0, $availableWorkers = 0, $maxWorkerKey = 0;
    protected $taskQueue = [], $workerConnections = [], $clientConnections = [], $read = [];

    public function __construct()
    {
        $this->registerOrders();
    }

    public function run($clientPort, $workerPort, $workerCount)
    {
        $this->numOfProcess = $workerCount;
        $this->clientPort = serveAndListen($clientPort);
        if (empty($this->clientPort)) {
            app('log')->error('Process Manager: can not serve client socket');
            return;
        }
        while (true) {
            $this->checkNumberOfWorkers($workerPort);

            $this->makeReadArray();

            socket_select($this->read, $write, $except, null);

            $this->checkForNewClients();

            $this->readAllWorkers();

            $this->readAllClients();

            $this->allocateTasksToWorkers();
            // dump("available workers $this->availableWorkers, tasks " . count($this->taskQueue));
        }
    }

    protected function checkNumberOfWorkers($workerPortName)
    {
        static $workerPort;
        if (empty($workerPort)) {
            $workerPort = serveAndListen($workerPortName);
        }
        for ($i = 0; $i < $this->numOfProcess; $i++) {
            if (empty($this->workerConnections[$i])) {
                $worker = new WorkerHandler($i, $workerPortName);
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
                $this->availableWorkers++;
                $fromWorker = app('easy-socket')->cleanData($fromWorker);
                ///> worker only send stuff if they're idle or the have the client's response, we'll check if the 
                ///> workers send __WORKERSTATUSISIDLE__ when they just start working
                ///> workers send __TASKDONE__ when they finish a non-returnable client's task
                if (!preg_match('/^__WORKERSTATUSISIDLE__/', $fromWorker, $output_array)) {
                    foreach ($this->taskQueue as $key => $task) {
                        if ($task->isGivenToWorker($workerKey)) {
                            if (!preg_match('/^__TASKDONE__/', $fromWorker, $output_array)) {
                                $task->client->writeOnSocket($fromWorker);
                            }
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
                    if ($this->messageHasTasks($input)) {
                        $this->addTasks($clientConnection, $clientKey, $this->handleTaskMessages($clientConnection, $input));
                        ///> For Observation
                        if ($this->availableWorkers < $this->numOfProcess - ($this->numOfProcess * 80 / 100)) {
                            app('log')->info("$this->availableWorkers/$this->numOfProcess available workers");
                        }
                    } else {
                        $clientConnection->writeOnSocket($this->serviceOrders->run($input));
                    }
                }
            }
        }
    }

    protected function handleTaskMessages($clientConnection, $input)
    {
        ///> if the client send multiple short messages one imediately after another they'll come here attached, so we need to seperate them into different tasks
        return app('easy-socket')->seperateMessageGroup($input);
    }

    protected function addTasks($clientConnection, $clientKey, $tasks)
    {
        foreach ($tasks as $input) {
            $this->taskQueue[] = new Task($clientConnection, $clientKey, $input);
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
                        $this->availableWorkers--;
                        if ($this->maxWorkerKey < $workerKey) {
                            $this->maxWorkerKey = $workerKey;
                        }
                        continue 2;
                    }
                }
            }
        }
    }

    protected function messageHasTasks($input)
    {
        return !$this->serviceOrders->messageIsAnOrder($input);
    }

    protected function registerOrders()
    {
        $this->serviceOrders = new Orders($this);
    }
}
