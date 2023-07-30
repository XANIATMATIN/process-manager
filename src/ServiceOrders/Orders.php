<?php

namespace MatinUtils\ProcessManager\ServiceOrders;

class Orders
{
    private $userOrders = [], $defaultOrders = [];
    private $template = '/SO:(\w+)!/';
    protected $processManager;

    public function __construct($processManager)
    {
        $this->processManager = $processManager;
        $this->registerDefaultOrders();
        $this->registerUserOrders();
    }

    public function run(string $raw)
    {
        $orderName = $this->getOrderName($raw);
        ///> checking ser orders and default orders seperately so that user can override an order;
        $order = [];
        if (key_exists($orderName, $this->userOrders)) {
            $order = $this->userOrders[$orderName];
        } elseif (key_exists($orderName, $this->defaultOrders)) {
            $order = $this->defaultOrders[$orderName];
        } else {
            return 'Invalid input!';
        }
        try {
            $response = $order($this->processManager);
        } catch (\Throwable $th) {
            app('log')->info('Error in running order: ' . $th->getMessage());
            return 'Error in running order';
        }
        return $response ?? 'No response from order';
    }

    protected function getOrderName(string $raw)
    {
        $raw = app('easy-socket')->cleanData($raw);
        preg_match($this->template, $raw, $output_array);
        return strtolower($output_array[1] ?? '');
    }

    public function messageIsAnOrder($input)
    {
        return preg_match($this->template, $input, $output_array);
    }

    protected function registerUserOrders()
    {
        $staticOrdersClass = config('processManager.serviceOrders');
        if (class_exists($staticOrdersClass)) {
            $handler = new $staticOrdersClass;
            $this->userOrders = $handler->userOrders();
        }
    }

    protected function registerDefaultOrders()
    {
        $handler = new OrdersList;
        $this->defaultOrders = $handler->defaultOrders();
    }
}
