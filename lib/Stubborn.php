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
 *  //TODO: v3 needs to better support an array of functions to run, although 
 *  for now that seems like a bit of an edge case
 */
class Stubborn
{
    // User Defined Handler Functions
    protected $result_handler;
    protected $exception_handler;
    
    // User Defined Configuration Properties
    protected $catchable_exceptions;
    protected $short_circuit;

    // Run State Properties
    protected $retry_count;
    protected $max_retries;
    protected $current_result;
    protected $start_time;
    protected $run_time;
    protected $total_backoff;

    /**
     *  Creates a Stubborn object with some default parameters for plug and 
     *  play capabilities. Any custom setting should happen using the setters 
     *  defined below.
     */
    public function __construct()
    {
        // Stubborn configuration
        $this->catchable_exceptions = array();
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

    /******************************
     *  Stubborn Getters For
     *  Post Run State
     * ***************************/

    /*
     * Number of times Stubborn retried;
     */
    public function getRetryCount()
    {
        return $this->retry_count;
    }

    public function getTotalTries()
    {
        return $this->retry_count + 1;
    }

    public function getMaxRetries()
    {
        return $this->max_retries;
    }
    
    /**
     *  Gets total elapsed time that executing the specific function took.
     */
    public function getRunTime()
    {
        return $this->run_time;
    }

    public function getTotalBackoff()
    {
        return $this->total_backoff;
    }
   
    /******************************
     *  Stubborn setup functions
     * ***************************/

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
     * Handles setting a Backoff Strategy for Stubborn to use.
     *
     * @param BackoffHandlerInterface $strategy Object that implements this 
     * interface
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
    
    /******************************
     * Stubborn Run Helpers
     * ***************************/
    
    /**
     * Performs a Stubborn Backoff for duration specified.
     */
    protected function handleBackoff($duration)
    {
        $next_try = $this->retry_count + 1;
        static::logger()->debug("Backoff Used({$duration}s): Retry $next_try of $this->max_retries.");
        sleep($duration);
        $this->last_backoff = $duration;
        $this->total_backoff += $duration;
    }

    protected function handleException()
    {
        if (isset($this->exception_handler)) {
            $event_handler = $this->generateEventHandler();
            call_user_func(
                $this->exception_handler,
                $event_handler
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
    protected function handleResult()
    {

        // need to define outside of if statement so we can access later
        $event_handler = $this->generateEventHandler();

        if (isset($this->result_handler)) {
            call_user_func(
                $this->result_handler,
                $event_handler
            );
        }

        // be default we can only assume that the result was a success if we've
        // made it this far, or if the result handler hasn't thrown
        // a RetryEvent to this point
        if (!$event_handler->exception() !== null) {
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
    protected function suppressException(\Exception $e)
    {
        foreach ((array) $this->catchable_exceptions as $type) {
            if (is_a($e, $type)) {
                return true;
            }
        }
        return false;
    }

    private function generateEventHandler()
    {
        return new StubbornEventHandler(
            $this->retry_count,
            $this->max_retries,
            $this->run_time,
            $this->last_backoff,
            $this->current_result
        );
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
            $this->total_backoff = 0;

            // start at 0 so to include the first attempt plus retries
            for ($this->retry_count = 0; $this->retry_count <= $this->max_retries; $this->retry_count++) {

                $this->run_time = 0;
                $this->last_backoff = 0;

                // outer try/catch handles fired stubborn events
                try {

                    // Protect against any exceptions we expect we might encounter.
                    // If the call doesn't result in a thrown exception, success!
                    try {
                        
                        $this->start_time = time();
                        $this->current_result = call_user_func($function);
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

                        // Since a non-expected exception was thrown,
                        // stop the run time now
                        $this->run_time = time() - $this->start_time;

                        // check if this is an exception we want to suppress
                        // and re-run Stubborn because of
                        $suppress = $this->suppressException($e);

                        // if we've exceeded retries, aren't handling this specific
                        // exception, or want an exception to be intentionally
                        // thrown, let it rip
                        if (!$suppress
                            || $this->short_circuit
                            || $this->retry_count == $this->max_retries
                        ) {
                            
                            // store this as a current result in case the user decides
                            // to handle and retry via evaluateResult
                            $this->current_result = $e;

                            // allow result handler to do something special with
                            // the exception and throw a Stubborn Event
                            $this->handleException();

                            // if this exception hasn't been handled by this
                            // point, it is clearly something unanticipated and
                            // should be thrown out of Stubborn
                            throw $this->current_result;
                        }

                    }

                } catch (BackoffEvent $e) {
                    // don't do backoff if we're on our last try
                    if ($this->retry_count < $this->max_retries) {
                        $this->handleBackoff($e->getMessage());
                        continue;
                    }
                }catch (RetryEvent $e) {
                    continue;
                } catch (StopEvent $e) {
                    break;
                }

            } // end of retry loop

            // TODO: make loop logic better so as not to need this
            if ($this->retry_count > $this->max_retries) {
                $this->retry_count = $this->max_retries;
            }

            $results[] = $this->current_result;

        } // end of invokable iteration

        //if we have just a single result, don't wrap it in an array
        return count($results) == 1 ? $results[0] : $results;
    }

    public static function logger()
    {
        return Trace::traceDepth(5);
    }
}
