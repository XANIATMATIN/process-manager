<?php

namespace MatinUtils\ProcessManager;

use Carbon\Carbon;

class Task
{
    protected $tries = 0, $maxTries = 1;
    protected $workerKey = Null;
    protected $clientKey, $input, $startTime;
    public $client;

    public function __construct($client, int $clientKey,  string $input)
    {
        $this->client = $client;
        $this->clientKey = $clientKey;
        $this->input = $input;
        $this->startTime = Carbon::now()->format('Y-m-d H:i');
    }

    public function input()
    {
        return $this->input;
    }

    public function startTime()
    {
        return $this->startTime;
    }

    public function removeWorkerKey()
    {
        $this->workerKey = Null;
    }

    public function inProcess(int $workerKey)
    {
        $this->workerKey = $workerKey;
        $this->tries++;
    }

    public function isInProcess()
    {
        return $this->workerKey !== Null;
    }

    public function getProperties()
    {
        return [
            'input' => $this->input,
            'workerKey' => $this->workerKey,
            'clientKey' => $this->clientKey,
            'tries' => $this->tries
        ];
    }

    public function isGivenToWorker(int $workerKey)
    {
        return $this->workerKey === $workerKey;
    }

    public function MaxedTries()
    {
        return $this->tries  >= $this->maxTries;
    }
}
