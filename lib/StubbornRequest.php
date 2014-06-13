<?php namespace Stubborn;

use Belt\Trace;
use Stubborn\Stubborn;
use Stubborn\Exceptions\StubbornException;
use Stubborn\ResultHandlerHelper;

require_once dirname(__FILE__) . '/../../BackupBox/functions.inc.php';
require_once dirname(__FILE__) . '/Stubborn.php';

/**
 *  This class enables us to use Stubborn for our homebrewed CurlRequest 
 *  infrastructure. This handles retries, smart exception catching, and backoff 
 *  as well as HTTP result handling.
 *
 *  Insantiate a version of this, add HTTP Response Code Handlers, and run 
 *  this class with the $request you are interested in.
 *
 */
class StubbornRequest extends Stubborn
{
    protected $handlers;
    protected $curl_request_class;

    /**
     * TODO: figure out how to incorperate/require custom CurlRequest 
     * Implementations i.e. WebDavCurlRequest
     *
     * @param string $curl_request_class name of the CurlRequest class Stubborn 
     *  should use. Need to determine a nice way to do requires for this.
     */
    public function __construct($curl_request_class = 'CurlRequest')
    {
        // set this by default, can overide if desired using retries($number)
        $this->handlers = array();
        $this->curl_request_class = $curl_request_class;

        parent::__construct();
    }

    /** 
     * Execute a request, try some thing if it fails (see handleResponse).
     *
     * Example of the more advanced syntax for passing a block for generating
     * the request object
     *
     * @param Request $request Configured Request object 
     * 
     * @return An array of return values from the test functions. If only one
     *  function is provided, we return it's result instead.
     */
    public function runRequest($request)
    {
        if (!is_a($request, 'Request')) {
            throw new StubbornException(
                'Expected a Request object as parameter'
            );
        }

        //PHP 5.3 doesn't like $this in anon func "use" blocks
        $curl_class = $this->curl_request_class;
        $handlers = $this->handlers;
        $self = $this;

        $this->resultHandler(
            // Responsible for parsing response and firing any Stubborn events
            // that we might want from the various handlers that have been
            // specified, assumes a success if we can't find any handlers
            function ($stubborn, $response) use ($self, $handlers) {
                if (!$response) {
                    // Might want to add some better debug output here, should only
                    // really hit this case during connector development
                    throw new StubbornException(
                        'No response received from request, check Request configuration'
                    );
                }

                $http_code = $response->http_code;
                if (array_key_exists($http_code, $handlers)) {
                    call_user_func(
                        $handlers[$http_code],
                        new ResultHandlerHelper(),
                        $response
                    );
                } else {
                    $self::logger()->debug(
                        "No compatible ResponseHandler found for HTTP Code: $http_code return result anyway."
                    );
                }
                $stubborn->accept();
            }
        );
            
        return $this->run(
            function () use ($request, $curl_class) {
                // Prepare the CurlRequest and Execute
                $curlRequest = new $curl_class($request);
                $result = $curlRequest();
                return $result;
            }
        );
    }

    /**
     *  Use this call to add a response handler for the request run.
     *
     *  @param int|array $handler_key the corresponding http response code(s) that 
     *    should be specific handler should be invoked for.
     *  @param function $handler_func the anonymous function handler
     *  
     *  @return itself for chaining
     */
    public function addHandler($handler_key, $handler_func)
    {
        if (is_int($handler_key)) {
            $handler_key = array($handler_key);

        } elseif (!is_array($handler_key)) {
            throw new StubbornException('Invalid parameters specified.');
        }

        foreach ($handler_key as $code) {
            if (array_key_exists($code, $this->handlers)) {
                throw new StubbornException('Result Handler key already present');
            }
            $this->handlers[$code] = $handler_func;
        }

        return $this;
    }
}
