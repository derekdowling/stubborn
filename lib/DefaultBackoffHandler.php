<?php namespace Stubborn;

use Belt\Trace;
use Stubborn\BackoffHandlerInterface;

class DefaultBackoffHandler implements BackoffHandlerInterface
{
    
    //public, convenience for testing
    protected $total_backoff;
    protected $static_backoff;

    /**
     *  Allows the user to set some custom backoff parameters if they are not 
     *  happy with the default method we use.
     *
     *  @param int $static_backoff_duration provide a value in seconds for 
     *  which Stubborn will backoff for each time a Backoff event is thrown.
     *
     *  @return int amount of time we backed off for in case we want to do 
     *  something fancy on the other end
     */
    public function __construct($static_backoff_duration = null)
    {
        $this->static_backoff_duration = $static_backoff_duration;
        $this->total_backoff = 0;
    }

    /**
     *  @return integer total number of seconds that were spent sleeping as 
     *  a result of the backoff handler.
     */
    public function getTotalDuration()
    {
        return $this->total_backoff;
    }

    /**
     *  Generic Backoff Handler logic. Override this method for a more specific 
     *  solution.
     */
    public function handleBackoff(
        $stubborn,
        $call_result,
        $run_attempt,
        $max_tries
    ) {
        
        $backoff = 0;
        if ($this->static_backoff_duration) {
            $backoff = $this->static_backoff_duration;
        } else {
            $backoff = $this->calculateBackoff($run_attempt);
        }

        $this->total_backoff += $backoff;

        Trace::traceDepth(7)->debug(
            "Attempt failure $run_attempt of $max_tries: backing off for {$backoff}s. Backed Total: {$this->total_backoff}s"
        );

        sleep($backoff);

        return $backoff;
    }

    /**
     *  Generic backoff calculator function. Override for a more specific 
     *  implementation.
     */
    public function calculateBackoff($run_attempt)
    {
        return pow(2, $run_attempt) + rand(0, 1000) / 1000;
    }
}
