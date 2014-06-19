<?php namespace Stubborn;

require 'vendor/autoload.php';

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
    protected $max_tries;
    protected $backoff_handler;
    protected $result_handler;
    protected $exceptions;
    protected $short_circuit;
    protected $run_attempt;
    protected $is_dirty;

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
        $this->max_tries = 1;

        // Resetable run state
        $this->is_dirty = false;
        $this->run_attempt = 0;
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

    /**
     *  Resets the Stubborn run state so we can re-use the Stubborn 
     *  configuration as many times as desired.
     *
     */
    protected function reset()
    {
        $this->is_dirty = false;
        $this->run_attempt = 0;
    }

    public function getRetryCount()
    {
        return $this->run_attempt != 0 ? $this->run_attempt - 1 : 0;
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
        $this->max_tries = $retries + 1;
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
        if (isset($this->result_handler)) {
            call_user_func(
                $this->result_handler,
                new ResultHandlerHelper(),
                $result,
                $this->run_attempt,
                $this->max_tries
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
            "$this->max_tries: sleeping for " .
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

        $this->backoff_handler->handleBackoff(
            new ResultHandlerHelper(),
            $result,
            $this->run_attempt,
            $this->max_tries
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
        // make sure we reset state in the case that the user wants to re-run
        // an invokable on the same config
        if ($this->is_dirty) {
            $this->reset();
        }

        //if the user supplies a single function, help our for loop out
        if (is_callable($invokables)) {
            $invokables = array($invokables);
        } elseif (!is_array($invokables)) {
            throw new StubbornException('Uncompatible Stubborn run type requested.');
        }

        $results = array();
        foreach ($invokables as $function) {
            $this->running = true;
            $this->run_attempt = 1;
            $result = null;

            for ($this->run_attempt; $this->run_attempt <= $this->max_tries; $this->run_attempt++) {

                // Protect against any exceptions we expect we might encounter.
                // If the call doesn't result in a thrown exception, success!
                try {
                    
                    $result = call_user_func($function);
                    $this->evaluateResult($result);
                   
                } catch (BackoffEvent $e) {
                    if ($this->run_attempt < $this->max_tries) {
                        $this->handleBackoff($result, $e->getMessage());
                    }
                } catch (DelayRetryEvent $e) {
                    if ($this->run_attempt < $this->max_tries) {
                        $this->generateRetryDelay($e->getMessage());
                    }
                    continue;
                // If all went well, we'll recieve some sort of StubbornEvent
                // to drive forward the Stubborn Retry Loop
                } catch (StopEvent $e) {
                    break;
                } catch (RetryEvent $e) {
                    continue;
                   
                // Catch everything and re-throw it if we are not intentionally
                // wanting to harden the function call against it, Stubborn
                // Events will trickle down
                } catch (\Exception $e) {

                    $suppress = $this->suppressException($e);

                    // if we've exceeded retries, aren't handling this specific
                    // exception, or want an exception to be intentionally
                    // thrown, let it rip
                    if (!$suppress
                        || $this->short_circuit
                        || $this->run_attempt == $this->max_tries
                    ) {
                        throw $e;
                    }

                    $result = $e;
                }

            } // end of retry loop

            $results[] = $result;

        } // end of invokable iteration

        //if we have just a single result, don't wrap it in an array
        return count($results) == 1 ? $results[0] : $results;
    }

    public static function logger()
    {
        return Trace::traceDepth(7);
    }
}
