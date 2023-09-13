<?php

namespace MatinUtils\ProcessManager\ServiceOrders\Logics;

use Carbon\Carbon;

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

    public function latestClientNumber()
    {
        foreach ($this->clientConnections as $key => $val) {
        }
        return $key ?? '';
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

    public function upTime()
    {
        return Carbon::createFromTimestamp($this->startTimeStamp)->diffForHumans();
    }
}
