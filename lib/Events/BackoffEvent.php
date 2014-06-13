<?php namespace Stubborn\Events;

use \Stubborn\Events\StubbornEvent;

/**
 * Non-default exception as to avoid and try/catch statement Stubborn might be 
 * run inside.
 */
class BackoffEvent extends StubbornEvent
{
}
