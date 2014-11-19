## Stubborn

![build status](https://travis-ci.org/derekdowling/stubborn.svg?branch=master)

##### Configurable call handler that is persistent against failures.
###### Built, tested, and relied upon at [Mover.io](https://mover.io).

Frustrated by unreliable APIs that seem to fail randomly?
Use Stubborn to ensure you aren't left unprotected in the most vulnerable and unknown spots in your code.

Stubborn provides all the necessary tooling you need to handle a variety of external API calls:

-Result Handling
-Retry Handling(immediate/delayed)
-Exception Catching
-Backoff Handling

###### Example:

```php
$id = $_RESULT['user_id'];

// $result will contain either the result from each attempt, or the exception
// each attempt threw
$result = Stubborn::build()
    // Use the Stubborn Result Handler to drive your call retries
    ->resultHandler(
        function ($stubborn) use ($id) {
            // fetch the latest attempt result
            $result = $stubborn->result();
            
            if ($result == 'Success') {
                // use the Event Handler to drive Stubborn
                $stubborn->accept();
            } elseif ($result == 'Backoff_Needed') {
                // Let Stubborn backoff for 3 seconds, then retry
                $stubborn->staticBackoff(3);
            } elseif ($result == 'Not_Yet_Persisted') {
                // Let Stubborn Delay, Then Retry
                $stubborn->delayRetry();
            } elseif ($result == 'Hard_Failure') {
                // Sometimes, giving up is the best option
                $stubborn->stop();
            } elseif ($result == 'Restart_Run') {
                // Allows you to restart the run from scratch
                $stubborn->reset();
            } elseif ($result == 'Requires_Modification') {
                // Start over, but with a slightly modified API Call
                $stubborn->resetAndRun(function () use ($id) {
                     Awesome_API::add_subscriber($id, false);
                });
            } else {
                $stubborn->retry();
            }
        }
    // Handle exceptions more explicitly and perform backoff using
    // a predefined set of tools, or perform your own handling manually
    )->exceptionHandler(function ($stubborn) {
    
        if (is_a($stubborn->exception(), 'Awesome_API\BackoffException')) {
            // exponentially increasing backoff after each attempt
            $stubborn->exponentialBackoff();
        } else {
            // wait three seconds before trying again
            $stubborn->staticBackoff(3);
        }
        
    })
    // Retry if defined exceptions are thrown by your function
    ->catchExceptions(array('Awesome_API\UnexpectedError'))
    // Will retry up to four times after the first attempt
    ->retries(4)
    ->run(function() use ($id) {
        return Awesome_API::add_subscriber($id); 
    });
```
