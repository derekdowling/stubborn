<?php namespace PHPUsable;

use \Stubborn\Stubborn;
use \Stubborn\StubbornException;
use \Stubborn\BackoffHandlerInterface;

require_once(dirname(__FILE__) . '/../../BackupBox/functions.inc.php');

class StubbornRequestTest extends PHPUsableTest
{
    public function tests()
    {
        PHPUsableTest::$current_test = $this;

        setup(function ($test) {
            $test->stubborn = new StubbornRequest();

            // Mock Out Curl
            $test->curl = $test->getMock('CurlRequest');
            $test->curl->expects($test->any())->method('__invoke')
                ->will($test->returnValue());

        });

        teardown(function ($test) {
        });

        describe('StubbornRequest', function ($test) {
            before(function ($test) {
            });

        });
    }
}
