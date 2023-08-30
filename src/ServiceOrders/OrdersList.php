<?php

namespace MatinUtils\ProcessManager\ServiceOrders;

class OrdersList
{
    public function defaultOrders()
    {
        return [
            'showstat' =>
            function ($processManager) {
                $availableWorkers = $processManager->availableWorkers();
                $tassksStat = $processManager->tasksInProgress();
                $idles = $availableWorkers * 100 / $processManager->workersCount();
                $maxWorkers =$processManager->maxWorker() + 1;
                $maxWorkersPercent = $maxWorkers * 100 / $processManager->workersCount() ;
                $data = [
                    'service' => basename(base_path()),
                    'total workers' => $processManager->workersCount(),
                    'idle workers' => "$availableWorkers ($idles%)",
                    'connected clients' => $processManager->clientsCount(),
                    'current client key' => $processManager->latestClientNumber(),
                    'total tasks' => $processManager->tasksCount(),
                    'tasks in Progress' => $tassksStat['inProgress'],
                    'tasks waiting' => $tassksStat['waiting'],
                    'highest nuumber of workers used' => "$maxWorkers ($maxWorkersPercent%)",
                ];
                return json_encode($data);
            },
        ];
    }
}
