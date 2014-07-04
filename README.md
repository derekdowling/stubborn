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
            $result = $stubborn->result();
            
            if ($result == 'Success') {
                $stubborn->accept();
            } elseif ($result == 'Backoff_Needed') {
                $stubborn->staticBackoff(3);
            } elseif ($result == 'Not_Yet_Persisted') {
                // uses Stubborns built in delay mechanism before
                // trying again
                $stubborn->delayRetry();
            } elseif ($result == 'Hard_Failure') {
                $stubborn->stop();
            } elseif ($result == 'Restart_Run') {
                $stubborn->reset();
            } elseif ($result == 'Requires_Modification') {
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
    ->retries(4)
    ->run(function() use ($id) {
        return Awesome_API::add_subscriber($id); 
    });
```
