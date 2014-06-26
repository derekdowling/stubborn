<?php namespace Stubborn\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use Stubborn\Stubborn;
use Stubborn\Exceptions\StubbornException;
use Stubborn\Events\StubbornEvent;
use Stubborn\Events\RetryEvent;
use Stubborn\DefaultBackoffHandler;

class StubbornTestException extends \Exception
{
}

describe('Stubborn', function ($test) {
    before_all(function ($test) {
        $test->retry_result_handler =
                function ($stubborn) {
                    $stubborn->retry();
                };
    });

    describe('->run()', function ($test) {
        it('should execute and return the expected value for the provided function', function ($test) {
            $stubborn = new Stubborn();
            $result = $stubborn->run(function () {
                return 5;
            });
            expect($result)->to->be(5);
        });
        it('should execute and return all of the expected results for an array of provided functions', function ($test) {
            $stubborn = new Stubborn();
            $result = $stubborn->run(
                array(
                    function () {
                        return 5;
                    },
                    function () {
                        return 'Pineapple';
                    }
                )
            );
            expect(is_array($result))->to->be(true);
            expect(count($result))->to->be(2);
            expect($result[1])->to->be('Pineapple');
        });
    });

    describe('->catchExceptions()', function ($test) {
        it('should throw a non-handled exception', function ($test) {
            expect(function () use (&$test) {
                Stubborn::build()
                    ->run(function () {
                        throw new StubbornTestException('this should get thrown');
                    });
            })->to->throw('Stubborn\Tests\StubbornTestException', 'this should get thrown');
        });

        describe('with a matching exception', function ($test) {
            it('should catch/retry 4 times and then throw exception', function ($test) {
                $stubborn = Stubborn::build();
                expect(function () use (&$test, &$stubborn) {
                    $stubborn
                        ->catchExceptions(array('Stubborn\Tests\StubbornTestException'))
                        ->retries(4)
                        ->run(function () {
                            throw new StubbornTestException('this should get thrown');
                        });
                })->to->throw('Stubborn\Tests\StubbornTestException', 'this should get thrown');
                expect($stubborn->getRetryCount())->to->be(4);
            });
        });

        describe('with a non-matching exception and retries set', function ($test) {
            it('should immediately throw the exception despite of retries', function ($test) {
                $stubborn = Stubborn::build();
                expect(function () use (&$stubborn) {
                    $stubborn
                        ->retries(3)
                        ->run(
                            function () {
                                throw new \Exception('this should get thrown');
                            }
                        );
                })->to->throw('\Exception', 'this should get thrown');
                expect($stubborn->getRetryCount())->to->be(0);
            });
        });
    });

    describe('->resultHandler()', function ($test) {
        it('should control the result outcome', function ($test) {
            expect(function () {
                $rHandler = function ($stubborn) {
                    throw new \Exception('Result Handler called');
                };
                Stubborn::build()
                    ->resultHandler($rHandler)
                    ->run(function () {
                        return 5;
                    });
            })->to->throw('\Exception', 'Result Handler called');
        });
    });

    describe('->retries()', function ($test) {
        it("should retry 3 times and 'Dog' is returned", function ($test) {
            $stubborn = new Stubborn();
            $result = $stubborn
                ->retries(3)
                ->resultHandler($test->retry_result_handler)
                ->run(function () {
                    return 'Dog';
                });
            expect($stubborn->getRetryCount())->to->be(3);
            expect($result)->to->be('Dog');
        });

    });

    describe('StubbornEventHandler', function ($test) {
        it('->retry()', function ($test) {
            $stubborn = new Stubborn();
            $result = $stubborn
                ->retries(5)
                ->resultHandler(function ($stubborn) {
                    $stubborn->retry();
                })
                ->run(function () {
                    return array(1,6,9);
                });
            expect($stubborn->getTotalTries())->to->be(6);
            expect($result)->to->be(array(1,6,9));
        });
        it('->stop()', function ($test) {
            $stubborn = new Stubborn();
            $result = $stubborn
                ->resultHandler(function ($stubborn) {
                    if ($stubborn->retryCount() == 1) {
                        $stubborn->stop();
                    }
                    $stubborn->retry();
                })
                ->retries(2)
                ->run(function () {
                    return 5;
                });
            expect($stubborn->getTotalTries())->to->be(2);
            expect($result)->to->be(5);
        });

        it('->staticBackoff()', function ($test) {
            $stubborn = new Stubborn();
            $result = $stubborn
                ->resultHandler(function ($stubborn) {
                    if ($stubborn->retryCount() < $stubborn->maxRetries()) {
                        $stubborn->staticBackoff(1);
                    }
                })
                ->retries(2)
                ->run(function () {
                    return 1;
                });

            // kind of arbitrary, can't think of a more accurate way to test
            // this at this point
            expect($stubborn->getTotalBackoff())->to->be(2);
            expect($stubborn->getTotalTries())->to->be(3);
            expect($result)->to->be(1);
        });

        it('->exponentialBackoff()', function ($test) {
            $stubborn = new Stubborn();
            $result = $stubborn
                ->resultHandler(function ($stubborn) {
                    if ($stubborn->retryCount() < $stubborn->maxRetries()) {
                        $stubborn->exponentialBackoff();
                    }
                })
                ->retries(2)
                ->run(function () {
                    return 77;
                });

            // kind of arbitrary, can't think of a more accurate way to test
            // this at this point
            expect($stubborn->getTotalBackoff())->to->be->within(3, 5);
            expect($stubborn->getTotalTries())->to->be(3);
            expect($result)->to->be(77);
        });

        it('->delayRetry()', function ($test) {
            $stubborn = new Stubborn();
            $result = $stubborn
                ->retries(1)
                ->resultHandler(function ($stubborn) {
                    $stubborn->delayRetry();
                })
                ->run(function () {
                    return 'Kaboom';
                });
            expect($stubborn->getTotalBackoff())->to->be->within(1, 2);
            expect($stubborn->getTotalTries())->to->be(2);
            expect($result)->to->be('Kaboom');
        });
    });

    describe('->exceptionHandler()', function ($test) {
        it('should make Stubborn retry 3 times', function ($test) {

            $exception = null;
            $e_type = 'Stubborn\Tests\StubbornTestException';

            $rHandler = function ($stubborn) use ($e_type) {
                if ($stubborn->retryCount() < $stubborn->maxRetries()) {
                    throw new $e_type;
                }
            };
            $eHandler = function ($stubborn) use (&$exception) {
                $exception = $stubborn->exception();
                $stubborn->retry();
            };
            $stubborn = new Stubborn();
            $result = $stubborn
                ->retries(3)
                ->exceptionHandler($eHandler)
                ->resultHandler($rHandler)
                ->run(function () {
                    return 'Boosh';
                });
            expect(is_a($exception, $e_type))->to->be(true);
            expect($stubborn->getTotalTries())->to->be(4);
            expect($result)->to->be('Boosh');
        });
    });
});
