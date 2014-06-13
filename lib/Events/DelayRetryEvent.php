<?php namespace Stubborn\Events;

use Stubborn\Events\StubbornEvent;

/**
 *  Throw this namely when you encounter a call that may result in an unexpected 
 *  response due to a race condition and you'd like to try again after waiting 
 *  for the foreign system to catch up.
 *
 *  i.e. Expecting a folder to be present immediately after calling that 
 *  system to create the folder.
 */
class DelayRetryEvent extends StubbornEvent
{
}
