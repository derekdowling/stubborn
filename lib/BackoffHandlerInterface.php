<?php namespace Stubborn;

interface BackoffHandlerInterface
{
    /**
     *  Enforces and explicitly defines the expected parameters the backoff 
     *  strategy will be called with.
     *
     *  @param mixed|exception $call_result Either the result returned or the 
     *    exception that was thrown from invoking the call.
     *  @param int $last_backoff_duration time in seconds of the last attempt 
     *    backoff
     *  @param int $run_attempt
     *  @param int $max_retries
     */
    public function handleBackoff($stubborn, $call_result, $run_attempt, $max_tries);
}
