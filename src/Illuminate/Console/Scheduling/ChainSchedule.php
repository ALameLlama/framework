<?php

namespace Illuminate\Console\Scheduling;

use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class ChainSchedule
{
    /** @var array */
    protected $events = [];

    public function __construct(protected EventMutex $mutex, protected $timezone = null, protected $compileParameters = null)
    {
    }

    /**
     * Add an Artisan command to the chain.
     *
     * @return \Illuminate\Console\Scheduling\ChainChildEvent
     */
    public function command($command, array $parameters = [])
    {
        if ($command instanceof SymfonyCommand || (is_string($command) && class_exists($command))) {
            $command = $command instanceof SymfonyCommand ? $command : Container::getInstance()->make($command);
            $event = $this->exec(Application::formatCommandString($command->getName()), $parameters, $command->getName());

            return $event->description($command->getDescription());
        }

        return $this->exec(Application::formatCommandString($command), $parameters, $command);
    }

    public function exec($command, array $parameters = [], $displayName = null)
    {
        $displayName ??= $command;

        if ($parameters !== []) {
            $parameters = ($this->compileParameters)($parameters);
            $command .= ' '.$parameters;
            $displayName .= ' '.$parameters;
        }

        $this->events[] = $event = new ChainChildEvent(
            $this->mutex, $command, $displayName ?? $command, $this->timezone
        );

        return $event;
    }

    public function events()
    {
        return $this->events;
    }

}
