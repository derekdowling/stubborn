<?php namespace Stubborn;

use Stubborn\Exceptions\StubbornException;
use Stubborn\Events\StopEvent;
use Stubborn\Events\RetryEvent;
use Stubborn\Events\DelayRetryEvent;
use Stubborn\Events\BackoffEvent;

/**
 *  This class defines the helper object that is passed into 
 *  StubbornResponseHandler functions. This allows you to fire events that help 
 *  drive Stubborn forward.
 *
 *  The assumption is that any functions defined within this class rely upon 
 *  Stubborn being in a currently running state and any events they fire will 
 *  be caught and handled appropriately.
 */
class StubbornEventHandler
{

    protected $stubborn_runner;

    public function __construct($stubborn)
    {
        $this->stubborn_runner = $stubborn;
    }

    public function __call($function, $args)
    {
        if (method_exists($this->stubborn_runner, $function)) {
            return call_user_func_array(array($this->stubborn_runner, $function), $args);
        }

        throw new StubbornException("Function '$function' not defined");
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
     *  Allows the user to perform an exponential backoff between
     *  attempts in which the backoff duration grows exponentially
     *  after each call.
     */
    public function exponentialBackoff()
    {
        $duration = pow(2, $this->stubborn_runner->retries()) + rand(0, 1000) / 1000;
        $this->backoff($duration);
    }

    /**
     *  Allows you to reset Stubborn mid-run and run again with the newly 
     *  provided function to execute.
     *
     *  @param invokable function to run against the Stubborn configuration
     */
    public function resetAndRun($invokable)
    {
        if (!is_callable($invokable)) {
            throw new StubbornException('Non-function provided as invokable');
        }

        $stubborn->invokable($invokable);
        $this->reset();
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

    public function reset()
    {
        throw new ResetEvent();
    }
}
