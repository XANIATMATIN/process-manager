<?php

namespace MatinUtils\ProcessManager;

use App\Models\Pid;
use MatinUtils\EasySocket\Consumer;
use MatinUtils\ProcessManager\ServiceOrders\Logics\CurrentTasks;
use MatinUtils\ProcessManager\ServiceOrders\Logics\ShowStat;
use MatinUtils\ProcessManager\ServiceOrders\Orders;

class ProcessManager
{
    use ShowStat, CurrentTasks;
    protected $workerPort, $clientPort;
    protected $serviceOrders, $startTimeStamp;
    protected $numOfProcess = 0, $availableWorkers = 0, $maxWorkerKey = 0;
    protected $taskQueue = [], $workerConnections = [], $clientConnections = [], $read = [];

    public function __construct()
    {
        $this->registerOrders();
    }

    public function run($clientPort, $workerPort, $workerCount)
    {
        $this->availableWorkers = $this->numOfProcess = $workerCount;
        $this->clientPort = serveAndListen($clientPort);
        if (empty($this->clientPort)) {
            app('log')->error('Process Manager can not serve client socket');
            return;
        }
        $this->startTimeStamp = microtime(true);
        while (true) {
            $this->checkNumberOfWorkers($workerPort);

            $this->makeReadArray();

            socket_select($this->read, $write, $except, null);

            $this->checkForNewClients();

            $this->readAllWorkers();

            $this->readAllClients();

            $this->allocateTasksToWorkers();
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
                $this->availableWorkers--;
                $worker = $this->newWorkerHandler($i, $workerPortName);
                $worker->startProcess();
                $worker->setConnection(socket_accept($workerPort));
                $this->workerConnections[$i] = $worker;
            }
        }
    }

    protected function newWorkerHandler($i, $workerPortName)
    {
        return new WorkerHandler($i, $workerPortName);
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
            $clientConnection =  new Consumer(socket_accept($this->clientPort));
            if ($clientConnection->status()) {
                $this->clientConnections[] = $clientConnection;
            }
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
                $this->handleWorkerInput(app('easy-socket')->cleanData($fromWorker), $workerKey);
            }
        }
    }

    protected function handleWorkerInput($fromWorker, $workerKey)
    {
        ///> example fromWorker __WORKERSTATUSISIDLE__
        ///> worker only send stuff if they're idle or the have the client's response, we'll check if the 
        ///> workers send __WORKERSTATUSISIDLE__ when they just start working
        ///> workers send __TASKDONE__ when they finish a non-returnable client's task
        if (!preg_match('/__WORKERSTATUSISIDLE__$/', $fromWorker, $output_array)) {
            foreach ($this->taskQueue as $key => $task) {
                if ($task->isGivenToWorker($workerKey)) {
                    if (preg_match('/^__TASKDONE__/', $fromWorker, $output_array)) {
                        ///> this mean task is for a non returnable (oneway) system like log
                        ///> so the response is not important for the client and the client has already moved on
                        $task->client->responseReceived();
                    } else {
                        ///> means the tsk is for a returnable system like MSS and we need to send back the worker's response to the client
                        ///> sometimes a system has 2 types of tasks, both returnable and non returnable (like crawler), in this case we'll check and send response only if the client is waiting for a response, else the task was not returnable
                        if ($task->client->isWaitingForResponse()) {
                            $task->client->writeOnSocket($fromWorker);
                            $task->client->responseReceived();
                        } else {
                            app('log')->info("There is a response from worker but the consumer has already received it's response and is no longer waiting for response");
                            app('log')->info("fromWorker $fromWorker");
                        }
                    }
                    unset($this->taskQueue[$key]);
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

    protected function handleTaskMessages($clientConnection, $input) ///> rewritten in crawler
    {
        $tasks = app('easy-socket')->seperateMessageGroup($input); ///> if the client send multiple short messages one imediately after another they'll come here attached, so we need to seperate them into different tasks
        if (($this->availableWorkers + config('processManager.tasksOverload', 0)) < count($tasks)) {
            $clientConnection->writeOnSocket('unavailable');
            app('log')->info("tasksOverload");
            return [];
        }
        $clientConnection->waitingForResponse(); /// this is not very clean (in case the task type is Not returnable, like log) but it won't be a problem bc non returnable tasks' responses will be handled differently in handleWorkerInput
        return $tasks;
    }

    protected function addTasks($clientConnection, $clientKey, $tasks)
    {
        foreach ($tasks ?? [] as $input) {
            $this->taskQueue[] = new Task($clientConnection, $clientKey, $input);
        }
    }

    protected function allocateTasksToWorkers()
    {
        $busyWorkers = [];
        foreach ($this->taskQueue as $key => $task) {
            if (!$task->isInProcess()) {
                ksort($this->workerConnections);
                foreach ($this->workerConnections as $workerKey => $workerConnection) {
                    if (in_array($workerKey, $busyWorkers)) continue; ///> busyWorkers array is for all the workers that are allocated in this turn. i thought maybe this is more efficient than checking status
                    if ($workerConnection->status() == 'idle') {
                        $workerConnection->writeOnSocket($task->input());
                        $workerConnection->busy();
                        $task->inProcess($workerKey);
                        $this->availableWorkers--;
                        if ($this->maxWorkerKey < $workerKey) {
                            $this->maxWorkerKey = $workerKey;
                        }
                        $busyWorkers[] = $workerKey;
                        continue 2;
                    } else {
                        $busyWorkers[] = $workerKey;
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
