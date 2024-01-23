<?php

namespace MatinUtils\ProcessManager\ServiceOrders;

use Carbon\Carbon;

class OrdersList
{
    public function defaultOrders()
    {
        return [
            'availableworkers' =>
            function ($processManager) {
                return $processManager->availableWorkers();
            },
            'currenttasks' =>
            function ($processManager) {
                return json_encode([
                    'tasks' => $tasks = $processManager->tasksDetails(),
                    'totalTasks' => count($tasks),
                    'now' => Carbon::now()->format('Y-m-d H:i')
                ]);
            },
            'showstat' =>
            function ($processManager) {
                $availableWorkers = $processManager->availableWorkers();
                $tassksStat = $processManager->tasksInProgress();
                $idles = $availableWorkers * 100 / $processManager->workersCount();
                $maxWorkers = $processManager->maxWorker() + 1;
                $maxWorkersPercent = $maxWorkers * 100 / $processManager->workersCount();
                return json_encode([
                    'service' => basename(base_path()),
                    'up time' => $processManager->upTime(),
                    'total workers' => $processManager->workersCount(),
                    'idle workers' => "$availableWorkers ($idles%)",
                    'connected clients' => $processManager->clientsCount(),
                    'total clients' => $processManager->latestClientNumber(),
                    'total tasks' => $processManager->tasksCount(),
                    'tasks waiting' => $tassksStat['waiting'],
                    'tasks in Progress (current in-use workers)' => $tassksStat['inProgress'],
                    'peak in-use workers' => "$maxWorkers ($maxWorkersPercent%)",
                ]);
            },
        ];
    }
}
