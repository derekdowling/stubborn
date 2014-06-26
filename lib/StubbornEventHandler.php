<?php namespace Stubborn;

use Stubborn\Events\StopEvent;
use Stubborn\Events\RetryEvent;
use Stubborn\Events\DelayRetryEvent;
use Stubborn\Events\BackoffEvent;

/**
 *  This class defines the helper object that is passed into 
 *  StubbornResponseHandler functions. This allows you to fire events that help 
 *  drive Stubborn forward.
 */
class StubbornEventHandler
{
    protected $result;
    protected $retry_count;
    protected $max_retries;
    protected $run_time;
    protected $last_backoff;
    protected $is_exception;

    public function __construct($retry_count, $max_retries, $run_time, $last_backoff, $result)
    {
        $is_exception = $result instanceof \Exception ? true : false;

        $this->retry_count = $retry_count;
        $this->max_retries = $max_retries;
        $this->run_time = $run_time;
        $this->result = $is_exception ? null : $result;
        $this->exception = $is_exception ? $result : null;
    }

    public function retryCount()
    {
        return $this->retry_count;
    }

    public function maxRetries()
    {
        return $this->max_retries;
    }

    public function runTime()
    {
        return $this->run_time;
    }

    public function exception()
    {
        return $this->exception;
    }

    public function result()
    {
        return $this->result;
    }

    public function lastBackoff()
    {
        return $this->last_backoff;
    }
    
    /*
     *  Allows the user to perform a static backoff
     *  that remains the same length for every attempt.
     *
     *  @param int $duration time in seconds
     */
    public function staticBackoff($duration)
    {
        $this->backoff($duration);
    }

    /**
     * Allows the user to perform an exponential backoff between
     * attempts in which the backoff duration grows exponentially
     * after each call.
     */
    public function exponentialBackoff()
    {
        $duration = pow(2, $this->retry_count) + rand(0, 1000) / 1000;
        $this->backoff($duration);
    }

    /***********
     *
     * Stubborn Driver Events
     *  - Trigger's Stubborn to perform various actions based on the result 
     *  that is received
     *
     ***********/

    public function stop()
    {
        throw new StopEvent;
    }

    public function retry()
    {
        throw new RetryEvent;
    }

    public function delayRetry()
    {
        $this->exponentialBackoff();
    }

    public function accept()
    {
        throw new StopEvent;
    }

    private function backoff($duration)
    {
        throw new BackoffEvent($duration);
    }
}
