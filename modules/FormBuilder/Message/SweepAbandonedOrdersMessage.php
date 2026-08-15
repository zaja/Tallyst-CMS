<?php

namespace Tallyst\FormBuilder\Message;

/**
 * Ask the worker to run the unfinished-checkout deadline.
 *
 * Carries nothing: the sweep decides what is due from the clock and the database, so a message that
 * sat in the queue for a while still does the right thing when it is finally handled.
 */
final class SweepAbandonedOrdersMessage
{
}
