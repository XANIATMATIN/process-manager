<?php

namespace MatinUtils\ProcessManager\ServiceOrders\Logics;

trait CurrentTasks
{
    public function tasksDetails()
    {
        foreach ($this->taskQueue as $task) {
            $data[] = [
                'input' => $task->input(),
                'startTime' => $task->startTime()
            ];
        }
        return $data ?? [];
    }
}
