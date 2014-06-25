<?php namespace Stubborn;

use Belt\Trace;

use Stubborn\Exceptions\StubbornException;
use Stubborn\Events\StopEvent;
use Stubborn\Events\RetryEvent;
use Stubborn\Events\BackoffEvent;
use Stubborn\Events\DelayRetryEvent;

/**
 *  Stubborn is designed to execute functions that require a higher level of 
 *  robustness and configurability against exceptions and backoff for when 
 *  there is the risk of mission critical infrastructure failing for variable 
 *  or unknown reasoning against blackbox systems.
 *
 *  //TODO: Implement a solution which allows a user to specifies exceptions 
 *  that they'd like to catch and as a result trigger backoff.
 *
 *  //TODO: Figure out how we want to re-throw exceptions we want to boil out 
 *  if we have specified more than one function we want to execute in a linearly 
 *  in a single stubborn
 */
class Stubborn
{
    protected $backoff_handler;
    protected $result_handler;
    protected $exceptions;
    protected $short_circuit;
    protected $retry_count;
    protected $max_retries;
    protected $current_result;
    protected $is_dirty;

    // profiling properties
    protected $run_time;
    protected $start_time;

    /**
     *  Creates a Stubborn object with some default parameters for plug and 
     *  play capabilities. Any custom setting should happen using the setters 
     *  defined below.
     */
    public function __construct()
    {
        // Stubborn configuration
        $this->exceptions = array();
        $this->short_circuit = false;
        $this->max_retries = 0;

    }

    /**
     *  Convenience function for initializing and configuring a Stubborn 
     *  request in one single step.
     *
     *  @return Stubborn new stubborn object
     */
    public static function build()
    {
        return new self();
    }

    public function getRetryCount()
    {
        return $this->retry_count;
    }

    public function getMaxRetries()
    {
        return $this->max_retries;
    }

    /**
     *  Use this to set how many times after the first attempt Stubborn should 
     *  try to execute the provided function.
     *
     *  @param int $retries number of additional reries to perform before quiting
     *
     *  @return itself for chaining
     */
    public function retries($retries)
    {
        if (!is_int($retries)) {
            throw new StubbornException('Parameter should be an integer');
        }

        // want to try once, plus whatever additional number of times specified
        // by the user
        $this->max_retries = $retries;
        return $this;
    }

    /**
     *  Use this function to set exceptions we want to be stubborn against.
     *
     *  @param Array $exception_types class names of the exceptions we want to 
     *      handle
     *      
     *  @return itself for chaining
     */
    public function catchExceptions($exception_types)
    {
        if (!is_array($exception_types)) {
            throw new StubbornException('Parameter must be an array');
        }
        $this->exceptions = array_merge($this->exceptions, $exception_types);
        return $this;
    }

    /**
     * Handles setting a Backoff Strategy for Stubborn to use.
     *
     * @param BackoffHandlerInterface $strategy Object that implements this 
     * interface
     *
     * @return itself for chaining
     */
    public function backoffHandler($handler)
    {
        if ($this->backoff_handler) {
            static::logger()->debug($handler);
            throw new StubbornException('Backoff handler already specified');
        }
        if (!$handler instanceof \Stubborn\BackoffHandlerInterface) {
            throw new StubbornException(
                'Parameter object must implement BackoffHandlerInterface'
            );
        }
        $this->backoff_handler = $handler;
        return $this;
    }

    /**
     *  This is used to bypass certain robustness for testing.
     *
     *  @return itself for chaining
     */
    public function shortCircuit()
    {
        $this->short_circuit = true;
        return $this;
    }

    /**
     * Stub function for implementing a customized result handler.
     * No way to know if the function was unsuccesful by default so assume it 
     * worked and Stubborn needn't continue retrying.
     *
     * Should not be called manually.
     *
     * @param multi $response the result returned by the invoked function
     *
     * @return the expected response as this is just a stub
     */
    protected function evaluateResult($result)
    {

        $is_exception = $result instanceof Exception ? true : false;

        if (isset($this->result_handler)) {

            $helper = new StubbornEventHandler(
                $this->retry_count,
                $this->max_retries,
                $this->run_time,
                $result,
                $is_exception
            );

            call_user_func(
                $this->result_handler,
                $helper
            );
        }

        // be default we can only assume that the result was a success if we've
        // made it this far, or if the result handler hasn't thrown
        // a RetryEvent to this point
        throw new StopEvent;
    }

    /**
     *  This is a courtesy function that works in conjunction with the 
     *  DelayRetryEvent. Used namely when you are making a call that may 
     *  result in an unexpected response due to a race condition and you'd like 
     *  to wait momentarily for the system to fully persist whatever changes 
     *  your previous call(s) have resulted in.
     *
     *  i.e. Expecting a folder to be present immediately after calling that 
     *  system to create the folder.
     *
     *  @param 
     */
    protected function generateRetryDelay($msg = 'none')
    {
        $waitTime = pow(2, $this->run_attempt) + rand(0, 1000) / 1000;
        
        static::logger()->debug(
            "Attempt failure $this->run_attempt of " .
            "$this->max_retries: sleeping for " .
            "$waitTime seconds."
        );

        sleep($waitTime);
    }

    /**
     *  Used to define a custom result handler. Assumes that the handler will 
     *  throw Stubborn events via the provided ResultHandlerHelper provided 
     *  parameter.
     *
     *  @param function $handler Function that evaluates a result.
     *
     *  @return itself for chaining
     */
    public function resultHandler($handler)
    {
        if (!is_callable($handler)) {
            throw new StubbornException(
                'Result Handler is expected to be a callable function'
            );
        }

        $this->result_handler = $handler;
        return $this;
    }

    /**
     *  This handles all of the specific details of performing a backoff. Any 
     *  actual actions in terms of sleeping, or implementation specific events 
     *  are left to the Backoff Strategy as set by the user.
     */
    protected function handleBackoff($result)
    {
        
        if (!isset($this->backoff_handler)) {
            throw new StubbornException(
                'Backoff Event thrown, but no Backoff Strategy set.'
            );
        }

        // TODO: redo this similar to Result Handler

        $this->backoff_handler->handleBackoff(
            new StubbornEventHandler(
                $this->retry_count,
                $this->max_retries,
                $this->run_time,
                $result
            )
        );
    }

    /**
     *  Checks if an exception thrown as a result of a Stubborn run should be 
     *  suppressed for a retry or if the Exception should travel up the stack.
     *
     *  @param Exception $e the exception in question
     *
     *  @return bool whether to throw the exception or suppress it
     */
    protected function suppressException(\Exception $e)
    {
        foreach ((array) $this->exceptions as $type) {
            if (is_a($e, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     *  Gets total elapsed time that executing the specific function took.
     */
    public function getRunTime()
    {
        return $this->run_time;
    }

    /**
     * Accepts a variable number of callback functions which will be invoked and 
     * handled based on parameters defined for the Stubborn object.
     *
     * @param array|function... $invokables functions to invoke with Stubborn
     *
     * @return An array of return values from the test functions. If only one
     *  function is provided, we return it's result instead.
     */
    public function run($invokables)
    {
        //if the user supplies a single function, help our for loop out
        if (is_callable($invokables)) {
            $invokables = array($invokables);
        } elseif (!is_array($invokables)) {
            throw new StubbornException('Uncompatible Stubborn run type requested.');
        }

        $results = array();
        foreach ($invokables as $function) {
            $this->running = true;
            $this->current_result = null;

            for ($this->retry_count = 0; $this->retry_count <= $this->max_retries; $this->retry_count++) {

                $this->run_time = 0;

                // outer try/catch handles fired stubborn events
                try {

                    // Protect against any exceptions we expect we might encounter.
                    // If the call doesn't result in a thrown exception, success!
                    try {
                        
                        $this->start_time = time();
                        $this->current_result = call_user_func($function);
                        $this->run_time += time() - $this->start_time;

                        $this->evaluateResult($this->current_result);
                       
                    // Catch everything and re-throw it if we are not intentionally
                    // wanting to harden the function call against it, Stubborn
                    // Events will trickle down
                    } catch (\Exception $e) {

                        // if we threw an exception, stop the run time
                        $this->run_time += time() - $this->start_time;

                        // store this as a current result in case the user decides
                        // to handle and retry via evaluateResult
                        $this->current_result = $e;

                        $suppress = $this->suppressException($e);

                        // if we've exceeded retries, aren't handling this specific
                        // exception, or want an exception to be intentionally
                        // thrown, let it rip
                        if (!$suppress
                            || $this->short_circuit
                            || $this->retry_count == $this->max_retries
                        ) {

                            // allow result handler to do something special with
                            // the exception
                            $this->evaluateResult($e);

                            // if we haven't yet been diverted by evaluate result
                            // let the exception travel up the call stack
                            throw $e;
                        }

                    }

                } catch (BackoffEvent $e) {
                    if ($this->retry_count < $this->max_retries) {
                        $this->handleBackoff($this->current_result, $e->getMessage());
                        continue;
                    }
                    break;
                } catch (DelayRetryEvent $e) {
                    if ($this->retry_count < $this->max_retries) {
                        $this->generateRetryDelay($e->getMessage());
                    }
                    continue;

                // If all went well, we'll recieve some sort of StubbornEvent
                // to drive forward the Stubborn Retry Loop
                } catch (StopEvent $e) {
                    break;
                } catch (RetryEvent $e) {
                    continue;
                }

            } // end of retry loop

            $results[] = $this->current_result;

        } // end of invokable iteration

        //if we have just a single result, don't wrap it in an array
        return count($results) == 1 ? $results[0] : $results;
    }

    public static function logger()
    {
        return Trace::traceDepth(7);
    }
}
