<?php namespace Stubborn\Events;

use \Stubborn\Events\StubbornEvent;

/**
 *  Throw when you aren't satisifed with the result that Stubborn returned 
 *  while running and you'd like it to try again.
 */
class RetryEvent extends StubbornEvent
{
}
