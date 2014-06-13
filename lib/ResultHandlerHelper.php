<?php namespace Stubborn;

use \Stubborn\Events\StopEvent;
use \Stubborn\Events\RetryEvent;
use \Stubborn\Events\DelayRetryEvent;
use \Stubborn\Events\BackoffEvent;

/**
 *  This class defines the helper object that is passed into 
 *  StubbornResponseHandler functions. This allows you to fire events that help 
 *  drive Stubborn forward.
 */
class ResultHandlerHelper
{
    public function fail()
    {
        throw new StopEvent;
    }

    public function retry()
    {
        throw new RetryEvent;
    }

    public function delayRetry()
    {
        throw new DelayRetryEvent;
    }

    public function accept()
    {
        throw new StopEvent;
    }

    public function backoff()
    {
        throw new BackoffEvent;
    }
}
