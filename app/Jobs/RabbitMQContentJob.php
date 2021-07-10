<?php

namespace App\Jobs;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use VladimirYuldashev\LaravelQueueRabbitMQ\Horizon\RabbitMQQueue as HorizonRabbitMQQueue;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob as BaseJob;

class RabbitMQContentJob extends BaseJob
{
   /**
     * Get the decoded body of the job.
     *
     * @return array
     */
     public function fire()
	    {
	        $payload = $this->payload();
	
	        $class = \App\Jobs\Scrapy\ProcessRawContent::class;
	        $method = 'handle';
	
	        ($this->instance = $this->resolve($class))->{$method}($this, $payload);
	    }
    public function payload()
    {
		   $data =  $json = json_decode($this->getRawBody(), true);
		   
        return [
            'job'  => 'App\Jobs\Scrapy\ProcessRawContent@handle',
            'data' => $data
        ];
    }
}