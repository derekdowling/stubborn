<?php namespace Stubborn\Events;

/**
 * Non-default exception as to avoid and try/catch statement Stubborn might be 
 * run inside. Various events extend this base event in order to drive various 
 * Stubborn actions.
 */
class StubbornEvent extends \Exception
{
}
