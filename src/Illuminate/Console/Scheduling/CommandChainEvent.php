<?php

namespace Illuminate\Console\Scheduling;

use Illuminate\Console\Application;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\ProcessUtils;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class CommandChainEvent extends Event
{
    /** @var \Illuminate\Console\Scheduling\Event[] */
    public $events;

    /** @var bool */
    public $shouldContinueOnFailure = false;

    public function __construct(EventMutex $mutex, array $events, protected Schedule $schedule, $timezone = null)
    {
        $this->events = $events;

        parent::__construct($mutex, implode(' && ', array_column($events, 'command')), $timezone);
    }

    public function continueOnFailure()
    {
        $this->shouldContinueOnFailure = true;

        return $this;
    }

    public function getDisplayName()
    {
        return implode(' → ', array_map(fn ($event) => $event->displayName, $this->events));
    }

    public function hasSelectedFailureContinuation()
    {
        return ! $this->shouldContinueOnFailure && collect($this->events)
            ->contains(fn ($event) => $event->shouldContinueOnFailure);
    }

    public function getExecutionDetail()
    {
        if ($this->runInBackground) {
            return $this->buildCommand();
        }

        $builder = new CommandBuilder;

        return implode(' → ', array_map(
            fn ($event) => $builder->buildCommandWithoutOutputUsing(
                $event->command, $event->user ?? $this->user
            ),
            $this->events
        ));
    }

    public function getSummaryForDisplay()
    {
        return is_string($this->description) ? $this->description : $this->getDisplayName();
    }

    public function mutexName()
    {
        if ($this->mutexNameResolver !== null && is_callable($this->mutexNameResolver)) {
            return ($this->mutexNameResolver)($this);
        }

        $identity = $this->description !== null
            ? 'name:'.$this->description
            : 'commands:'.$this->expression.implode('|', array_column($this->events, 'command'));

        return 'framework'.DIRECTORY_SEPARATOR.'schedule-'.sha1($identity);
    }

    public function buildCommand()
    {
        if (! $this->runInBackground) {
            return parent::buildCommand();
        }

        if (! is_string($this->description) || trim($this->description) === '') {
            throw new LogicException('Background command chains must have an explicit name.');
        }

        $events = collect($this->schedule->events());
        $matches = $events->filter(
            fn ($event) => $event instanceof self && $event->description === $this->description
        );

        if ($matches->count() !== 1) {
            throw new LogicException("A background command chain name must be unique [{$this->description}].");
        }

        if ($events->filter(fn ($event) => $event->mutexName() === $this->mutexName())->count() !== 1) {
            throw new LogicException("A background command chain mutex must be unique [{$this->description}].");
        }

        $command = Application::formatCommandString('schedule:run').' --chain='.ProcessUtils::escapeArgument($this->description);

        return (new CommandBuilder)->buildBackgroundCommandUsing(
            $this, $command, null, $this->getDefaultOutput(), false
        );
    }

    public function run(\Illuminate\Contracts\Container\Container $container)
    {
        if ($this->runInBackground) {
            parent::run($container);

            return;
        }

        $this->skippedBecauseOverlapping = false;

        if ($this->shouldSkipDueToOverlapping()) {
            $this->skippedBecauseOverlapping = true;

            return;
        }

        $this->ensureMutexIsReleasedOnSignal();
        $childrenStarted = false;
        $finishEntered = false;

        try {
            $this->callBeforeCallbacks($container);
            $childrenStarted = true;
            $exitCode = $this->executeChildren($container);
            $finishEntered = true;
            $this->finish($container, $exitCode);
        } catch (Throwable $e) {
            if ($finishEntered) {
                throw $e;
            }

            if ($childrenStarted) {
                $this->exitCode = 1;

                try {
                    $this->callAfterCallbacks($container);
                } catch (Throwable) {
                    // Preserve the original child lifecycle exception.
                } finally {
                    $this->removeMutex();
                }
            } else {
                $this->removeMutex();
            }

            throw $e;
        }
    }

    protected function execute($container)
    {
        fclose($this->openOutput($this, $this->shouldAppendOutput));

        return parent::execute($container);
    }

    public function executeChildren($container, $parentAppend = null)
    {
        $context = json_encode($container[Repository::class]->dehydrate());
        $parentOutput = $this->openOutput($this, $parentAppend ?? $this->shouldAppendOutput);
        $firstFailure = 0;

        try {
            foreach ($this->events as $event) {
                $policies = [$event->runInBackground, $event->withoutOverlapping, $event->onOneServer];
                $event->runInBackground = false;
                $event->withoutOverlapping = false;
                $event->onOneServer = false;
                $user = $event->user;
                $childOutput = null;

                try {
                    $event->user ??= $this->user;
                    $event->callBeforeCallbacks($container);
                    $childOutput = $this->normalizedOutputPath($event->output) === $this->normalizedOutputPath($this->output)
                        ? null
                        : $this->openOutput($event, $event->shouldAppendOutput);
                    try {
                        $command = (new CommandBuilder)->buildCommandWithoutOutputUsing($event->command, $event->user);
                        $exitCode = $this->executeChildProcess(
                            $command, ['__LARAVEL_CONTEXT' => $context], function ($type, $line) use ($parentOutput, $childOutput) {
                            fwrite($parentOutput, $line);

                            if ($childOutput) {
                                fwrite($childOutput, $line);
                            }

                            if (laravel_cloud()) {
                                fwrite($type === 'out' ? STDOUT : STDERR, $line);
                            }
                            }
                        );
                    } finally {
                        if ($childOutput) {
                            fclose($childOutput);
                        }
                    }

                    $event->finish($container, $exitCode);
                } finally {
                    $event->user = $user;
                    [$event->runInBackground, $event->withoutOverlapping, $event->onOneServer] = $policies;
                }

                if ($exitCode !== 0 && $firstFailure === 0) {
                    $firstFailure = $exitCode;

                    if (! $this->shouldContinueOnFailure && ! $event->shouldContinueOnFailure) {
                        break;
                    }
                }
            }
        } finally {
            fclose($parentOutput);
        }

        return $firstFailure;
    }

    protected function openOutput(Event $event, $append)
    {
        $handle = @fopen($event->output, $append ? 'a' : 'w');

        if ($handle === false) {
            throw new RuntimeException("Unable to open scheduled command output [{$event->output}].");
        }

        return $handle;
    }

    protected function executeChildProcess($command, array $environment, callable $output)
    {
        return Process::fromShellCommandline(
            $command, base_path(), $environment, null, null
        )->run($output);
    }

    protected function normalizedOutputPath($path)
    {
        if (($realpath = realpath($path)) !== false) {
            return $realpath;
        }

        $directory = realpath(dirname($path)) ?: dirname($path);

        return $directory.DIRECTORY_SEPARATOR.basename($path);
    }
}
