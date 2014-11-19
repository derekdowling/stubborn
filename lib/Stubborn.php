<?php namespace Stubborn;

use Belt\Trace;

use Stubborn\Exceptions\StubbornException;
use Stubborn\Events\StopEvent;
use Stubborn\Events\RetryEvent;
use Stubborn\Events\BackoffEvent;
use Stubborn\Events\DelayRetryEvent;
use Stubborn\Events\ResetEvent;

/**
 *  Stubborn is designed to execute functions that require a higher level of 
 *  robustness and configurability against exceptions and backoff for when 
 *  there is the risk of mission critical infrastructure failing for variable 
 *  or unknown reasoning against blackbox systems.
 *
 *  //TODO: v3 needs to better support an array of functions to run, although 
 *  for now that seems like a bit of an edge case
 */
class Stubborn
{
    // User Defined Handlers
    protected $event_handler_class;
    protected $result_handler;
    protected $exception_handler;
    
    // User Defined Configuration Properties
    protected $catchable_exceptions;
    protected $short_circuit;

    // Run State Properties
    protected $current_invokable;
    protected $retry_count;
    protected $max_retries;
    protected $current_result;
    protected $current_exception;
    protected $start_time;
    protected $run_time;
    protected $total_backoff;

    /**
     *  Creates a Stubborn object with some default parameters for plug and 
     *  play capabilities. Any custom setting should happen using the setters 
     *  defined below.
     */
    public function __construct($event_handler_class = 'Stubborn\EventHandler')
    {
        // Stubborn configuration
        $this->current_invokable = null;
        $this->catchable_exceptions = array();
        $this->short_circuit = false;
        $this->max_retries = 0;
        $this->retry_count = 0;
        $this->current_exception = null;
        $this->current_result = null;
        $this->run_time = 0;
        $this->total_backoff = 0;
        $this->result_handler = null;
        $this->exception_handler = null;
        $this->event_handler_class = $event_handler_class;
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

    /******************************
     *  Stubborn Getters For
     *  Post Run State
     * ***************************/

    /**
     * @return int number of times retried
     */
    public function totalRetries()
    {
        return $this->retry_count;
    }

    /**
     * @return int total attempts tried
     */
    public function totalTries()
    {
        return $this->retry_count + 1;
    }

    /**
     *  @return int maximum retries allowed
     */
    public function maxRetries()
    {
        return $this->max_retries;
    }
    
    /**
     *  @return int time in millis that executing the current run took
     */
    public function runTime()
    {
        return $this->run_time;
    }

    /**
     *  @return float time in seconds backed off for current call
     */
    public function totalBackoffTime()
    {
        return $this->total_backoff;
    }

    /**
     * @return exception|null the exception that was thrown this current run
     */
    public function exception()
    {
        return $this->current_exception ?: null;
    }

    /**
     *  @return mixed result returned by current run
     */
    public function result()
    {
        return $this->current_result ?: null;
    }

    /**
     * @return float time in seconds that was backed off on last run
     */
    public function lastBackoff()
    {
        return $this->last_backoff ?: null;
    }
  
    /**
     *  Returns the number of retries that have currently been attempted or 
     *  set how many times after the first attempt Stubborn should 
     *  try to execute the provided function.
     *
     *  @param int $retries number of additional reries to perform before quiting
     *
     *  @return itself for chaining or the current retry count
     */
    public function retries($retries = null)
    {
        if (!$retries) {

            return $this->retry_count;

        } elseif (!is_int($retries)) {
            throw new StubbornException('Parameter should be an integer');
        }

        // want to try once, plus whatever additional number of times specified
        // by the user
        $this->max_retries = $retries;
        return $this;
    }
 
    /******************************
     *  Stubborn setup functions
     * ***************************/

    /**
     *  Use this function to set exceptions we want to be stubborn against.
     *
     *  @param Array $exception_types fully namespaced clas names of the exceptions
     *      we want to suppress and retry against
     *      
     *  @return itself for chaining
     */
    public function catchExceptions($exception_types)
    {
        if (!is_array($exception_types)) {
            throw new StubbornException('Parameter must be an array');
        }
        $this->catchable_exceptions = array_merge($this->catchable_exceptions, $exception_types);
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
     * Allows you to change the currently running invokable on the fly, useful 
     * for any number of situations where the user's API arguments must make 
     * dynamic changes.
     *
     * Doesn't handle resetting Stubborn's run state, use the 
     * StubbornEventHandler 'resetAndRun' method to accomplish both of these 
     * tasks.
     *
     * @param invokable $invokable the new function that should be executed
     *
     * @return itself for chaining
     */
    public function invokable($invokable)
    {
        $this->current_invokable = $invokable;
        return $this;
    }

    /**
     * Used to set a user defined strategy via an anonymous function for 
     * dealing with exceptions thrown while executing call in Stubborn. Assumes 
     * the handler will throw Stubborn events via the StubbornEventHandler 
     * passed as a parameter to the function.
     *
     * @param function $handler defined user function for handling exceptions
     *
     * @return itself for chaining
     */
    public function exceptionHandler($handler)
    {
        if ($this->exception_handler) {
            static::logger()->debug($handler);
            throw new StubbornException('Exception handler already specified');
        }
        
        $this->exception_handler = $handler;
        return $this;
    }

    /**
     *  Used to define a custom result handler. Assumes that the handler will 
     *  throw Stubborn events via the provided ResultEventHandler provided 
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
    
    /******************************
     * Stubborn Run Helpers
     * ***************************/
    
    /**
     * Performs a Stubborn Backoff for duration specified.
     */
    private function handleBackoff($duration)
    {
        $next_try = $this->retry_count + 1;
        static::logger()->debug("Backoff Used({$duration}s): Retry $next_try of $this->max_retries.");
        sleep($duration);
        $this->last_backoff = $duration;
        $this->total_backoff += $duration;
    }

    /*
     *  Deals with the execution of an exception handler if defined.
     */
    private function handleException()
    {
        if (isset($this->exception_handler)) {
            call_user_func(
                $this->exception_handler,
                new $this->event_handler_class($this)
            );
        }
    }
    
    /**
     * Stub function for implementing a customized result handler.
     * No way to know if the function was unsuccesful by default so assume it 
     * worked and Stubborn needn't continue retrying.
     *
     * Should not be called manually.
     */
    private function handleResult()
    {
        if (isset($this->result_handler)) {
            call_user_func(
                $this->result_handler,
                new $this->event_handler_class($this)
            );
        }

        // By default we can only assume that the result was a success if we've
        // made it this far, or if the result handler hasn't thrown
        // a RetryEvent to this point
        if ($this->exception() == null) {
            throw new StopEvent;
        }
    }
    
    /**
     *  Checks if an exception thrown as a result of a Stubborn run should be 
     *  suppressed for a retry or if the Exception should travel up the stack.
     *
     *  @param Exception $e the exception in question
     *
     *  @return bool whether to throw the exception or suppress it
     */
    private function suppressException()
    {
        foreach ((array) $this->catchable_exceptions as $type) {
            if (is_a($this->current_exception, $type)) {
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
    private function execute($invokable)
    {
        $this->current_invokable = $invokable;
        $this->total_backoff = 0;

        // start at 0 so to include the first attempt plus retries
        for ($this->retry_count = 0; $this->retry_count <= $this->max_retries; $this->retry_count++) {

            $this->current_result = null;
            $this->current_exceptions = null;
            $this->run_time = 0;
            $this->last_backoff = 0;

            // outer try/catch handles fired stubborn events
            try {

                // Protect against any exceptions we expect we might encounter.
                // If the call doesn't result in a thrown exception, success!
                try {
                    
                    $this->start_time = time();
                    $this->current_result = call_user_func($this->current_invokable);
                    $this->run_time = time() - $this->start_time;

                    $this->handleResult();
                   
                // Catch everything and re-throw it if we are not intentionally
                // wanting to harden the function call against it, Stubborn
                // Events will trickle down
                } catch (\Exception $e) {

                    // if a Stubborn Event has been throw, don't do any
                    // handling here
                    if (is_a($e, 'Stubborn\Events\StubbornEvent')) {
                        throw $e;
                    }

                    // store this as a current result in case the user decides
                    // to handle and retry via evaluateResult
                    $this->current_exception = $e;

                    // Since a non-expected exception was thrown,
                    // stop the run time now
                    $this->run_time = time() - $this->start_time;

                    // if we've exceeded retries, want an exception to be
                    // intentionally thrown, or short circuit is set, let it rip
                    if ($this->retry_count == $this->max_retries
                        || !$this->suppressException()
                        || $this->short_circuit
                    ) {
                      
                        // allow result handler to do something special with
                        // the exception and throw a Stubborn Event in order to
                        // avoid the exception being thrown
                        $this->handleException();

                        // if this exception hasn't been handled by this
                        // point, it is clearly something unanticipated and
                        // should be thrown out of Stubborn
                        throw $this->current_exception;
                    }
                }
            } catch (BackoffEvent $e) {
                // don't do backoff if we're on our last try
                if ($this->retry_count < $this->max_retries) {
                    $this->handleBackoff($e->getMessage());
                    continue;
                }
            } catch (RetryEvent $e) {
                continue;
            } catch (StopEvent $e) {
                break;
            } catch (ResetEvent $e) {
                // so that the iterator sets it to be 0
                $this->retry_count = -1;
            }

        } // end of retry loop

        // TODO: make loop logic better so as not to need this
        if ($this->retry_count > $this->max_retries) {
            $this->retry_count = $this->max_retries;
        }

        return $this->current_result;
    }

    public function run($invokables)
    {
        //if the user supplies a single function, help our for loop out
        if (is_callable($invokables)) {

            return $this->execute($invokables);

        } elseif (is_array($invokables)) {
            $results = array();
            foreach ($invokables as $invokable) {
                $results[] = $this->execute($invokable);
            }
            return $results;
        } else {
            throw new StubbornException('Uncompatible Stubborn run type requested.');
        }

    }

    public static function logger()
    {
        return Trace::traceDepth(5);
    }
}
