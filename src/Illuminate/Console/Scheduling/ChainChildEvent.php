<?php

namespace Illuminate\Console\Scheduling;

class ChainChildEvent extends Event
{
    /** @var bool */
    public $shouldContinueOnFailure = false;

    public function __construct(EventMutex $mutex, $command, public readonly string $displayName, $timezone = null)
    {
        parent::__construct($mutex, $command, $timezone);
    }

    public function continueOnFailure()
    {
        $this->shouldContinueOnFailure = true;

        return $this;
    }
}
