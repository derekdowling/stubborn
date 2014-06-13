<?php namespace Stubborn\Events;

use \Stubborn\Events\StubbornEvent;

/**
 *  Thrown when you are happy with the result Stubborn recieves or the failure 
 *  is so catastrophic that you'd like to exit without any more retries.
 *
 *  Causes Stubborn to return whatever results or exceptions it has acquired.
 */
class StopEvent extends StubbornEvent
{
}
