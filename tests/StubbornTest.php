<?php namespace Stubborn\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use Stubborn\Stubborn;
use Stubborn\Exceptions\StubbornException;
use Stubborn\Events\StubbornEvent;
use Stubborn\Events\RetryEvent;
use Stubborn\DefaultBackoffHandler;

describe('Stubborn', function ($test) {
    before_all(function ($test) {
        $test->retry_result_handler =
                function ($stubborn, $response, $run_attempt, $max_tries) {
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
                        throw new StubbornEvent('this should get thrown');
                    });
            })->to->throw('\Stubborn\Events\StubbornEvent', 'this should get thrown');
        });

        describe('with a matching exception', function ($test) {
            it('should catch/retry 4 times and then throw exception', function ($test) {
                $stubborn = Stubborn::build();
                expect(function () use (&$test, &$stubborn) {
                    $stubborn
                        ->catchExceptions(array('\Stubborn\Events\StubbornEvent'))
                        ->retries(4)
                        ->run(function () {
                            throw new StubbornEvent('this should get thrown');
                        });
                })->to->throw('\Stubborn\Events\StubbornEvent', 'this should get thrown');
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
                $rHandler = function ($stubborn, $response, $run_attempt, $max_tries) {
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
        it("should retry 4 times and 'Dog' is returned", function ($test) {
            $stubborn = new Stubborn();
            $result = $stubborn
                ->retries(3)
                ->resultHandler($test->retry_result_handler)
                ->run(function () {
                    return 'Dog';
                });
            expect($stubborn->getRetryCount())->to->be(4);
            expect($result)->to->be('Dog');
        });

    });

    describe('->handleBackoff()', function ($test) {
        it('should backoff as specified', function ($test) {
            $bHandler = new DefaultBackoffHandler(2);
            $rHandler = function ($stubborn, $response, $run_attempt, $max_tries) {
                if ($run_attempt < $max_tries) {
                    $stubborn->backoff();
                }
            };
            $stubborn = new Stubborn();
            $result = $stubborn
                ->backoffHandler($bHandler)
                ->retries(3)
                ->resultHandler($rHandler)
                ->run(function () {
                    return 'Boosh';
                });
            expect($stubborn->getRetryCount())->to->be(3);
            expect($bHandler->getTotalDuration())->to->be(6);
            expect($result)->to->be('Boosh');
        });
    });
});
