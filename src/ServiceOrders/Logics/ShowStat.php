<?php

namespace MatinUtils\ProcessManager\ServiceOrders\Logics;

trait ShowStat
{
    public function workersCount()
    {
        return $this->numOfProcess;
    }

    public function availableWorkers()
    {
        return $this->availableWorkers;
    }

    public function clientsCount()
    {
        return count($this->clientConnections);
    }

    public function tasksCount()
    {
        return count($this->taskQueue);
    }

    public function maxWorker()
    {
        return $this->maxWorkerKey;
    }

    public function tasksInProgress()
    {
        $inProgress = $waiting = 0;
        foreach ($this->taskQueue as $task) {
            if ($task->isInProcess()) {
                $inProgress++;
            } else {
                $waiting++;
            }
        }
        return ['inProgress' => $inProgress, 'waiting' => $waiting];
    }
}
