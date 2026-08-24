<?php

declare(strict_types=1);

namespace Illuminate\Tests\Console\Scheduling;

use Illuminate\Console\Scheduling\CommandChainEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Tests\Console\Fixtures\JobToTestWithSchedule;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Schedule::class)]
final class ScheduleTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container;
        Container::setInstance($this->container);
        $eventMutex = Mockery::mock(EventMutex::class);
        $this->container->instance(EventMutex::class, $eventMutex);
        $schedulingMutex = Mockery::mock(SchedulingMutex::class);
        $this->container->instance(SchedulingMutex::class, $schedulingMutex);
    }

    public function testItCanScheduleACommandChain(): void
    {
        $schedule = new Schedule;

        $event = $schedule->chain(function ($chain) {
            $chain->command('cron:a');
            $chain->command('cron:b');
            $chain->command('cron:c');
        });

        $this->assertInstanceOf(CommandChainEvent::class, $event);
        $this->assertSame($event, $schedule->events()[0]);
        $this->assertCount(3, $event->events);
        $this->assertCount(1, $schedule->events());
        $this->assertMatchesRegularExpression('/artisan.*cron:a/', $event->events[0]->command);
        $this->assertSame('cron:a → cron:b → cron:c', $event->getSummaryForDisplay());

        $event->description('Nightly cron chain');

        $this->assertSame('Nightly cron chain', $event->getSummaryForDisplay());
    }

    public function testCommandChainMayNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('may not be empty');

        (new Schedule)->chain(fn ($chain) => null);
    }

    public function testCommandChainCanContinueOnFailure(): void
    {
        $event = (new Schedule)->chain(function ($chain) {
            $chain->command('cron:a');
            $chain->command('cron:b');
        });

        $originalMutex = $event->mutexName();

        $this->assertSame($event, $event->continueOnFailure());
        $this->assertTrue($event->shouldContinueOnFailure);
        $this->assertSame($originalMutex, $event->mutexName());
    }

    public function testBackgroundChainBuildDoesNotMutateParentAndUsesStableMutex(): void
    {
        $schedule = new Schedule;
        $event = $schedule->chain(fn ($chain) => $chain->command('cron:a'))
            ->name('nightly')->user('forge')->runInBackground();
        $command = $event->command;
        $user = $event->user;
        $event->createMutexNameUsing(fn ($event) => sha1($event->command.'|'.$event->user));
        $mutex = $event->mutexName();

        $built = $event->buildCommand();

        $this->assertSame($command, $event->command);
        $this->assertSame($user, $event->user);
        $this->assertSame($mutex, $event->mutexName());
        $this->assertStringContainsString('schedule:run', $built);
        $this->assertStringContainsString('schedule:finish', $built);
        $this->assertStringNotContainsString('sudo -u forge', $built);
    }

    public function testBackgroundChainsRejectCollidingMutexes(): void
    {
        $schedule = new Schedule;
        $first = $schedule->chain(fn ($chain) => $chain->command('cron:a'))->name('first')->runInBackground();
        $second = $schedule->chain(fn ($chain) => $chain->command('cron:b'))->name('second')->runInBackground();
        $first->createMutexNameUsing('collision');
        $second->createMutexNameUsing('collision');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('mutex must be unique');

        $first->buildCommand();
    }

    public function testBackgroundChainRejectsMutexCollisionWithNormalEvent(): void
    {
        $schedule = new Schedule;
        $normal = $schedule->command('cron:normal')->createMutexNameUsing('collision');
        $chain = $schedule->chain(fn ($chain) => $chain->command('cron:a'))->name('chain')->runInBackground();
        $chain->createMutexNameUsing('collision');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('mutex must be unique');

        $chain->buildCommand();
    }

    public function testCommandChainLabelsAndForegroundExecutionDetail(): void
    {
        $event = (new Schedule)->chain(function ($chain) {
            $chain->command('cron:a');
            $chain->command('cron:b')->user('publisher')->continueOnFailure();
        })->name('0')->user('forge');

        $this->assertSame('0', $event->getLabel());
        $this->assertTrue($event->hasSelectedFailureContinuation());
        $this->assertStringNotContainsString('schedule:run --chain', $event->getExecutionDetail());
        $this->assertStringContainsString('cron:a', $event->getExecutionDetail());
        $this->assertStringContainsString('cron:b', $event->getExecutionDetail());

        if (! windows_os()) {
            $this->assertStringContainsString('sudo -u forge', $event->getExecutionDetail());
            $this->assertStringContainsString('sudo -u publisher', $event->getExecutionDetail());
        }
    }

    #[DataProvider('jobHonoursDisplayNameIfMethodExistsProvider')]
    public function testJobHonoursDisplayNameIfMethodExists(object $job, string $jobName): void
    {
        $schedule = new Schedule();
        $scheduledJob = $schedule->job($job);
        $this->assertSame($jobName, $scheduledJob->description);
        $this->assertFalse($this->container->resolved(JobToTestWithSchedule::class));
    }

    public static function jobHonoursDisplayNameIfMethodExistsProvider(): array
    {
        $job = new class implements ShouldQueue
        {
            public function displayName(): string
            {
                return 'testJob-123';
            }
        };

        return [
            [new JobToTestWithSchedule, JobToTestWithSchedule::class],
            [$job, 'testJob-123'],
        ];
    }

    public function testJobIsNotInstantiatedIfSuppliedAsClassname(): void
    {
        $schedule = new Schedule();
        $scheduledJob = $schedule->job(JobToTestWithSchedule::class);
        $this->assertSame(JobToTestWithSchedule::class, $scheduledJob->description);
        $this->assertFalse($this->container->resolved(JobToTestWithSchedule::class));
    }

    public function testItCanFilterEventsByEnvironments(): void
    {
        $schedule = new Schedule();
        $schedule->job(JobToTestWithSchedule::class)->environments('production')->daily();
        $schedule->command('inspire')->environments(['staging', 'production'])->everyMinute();
        $schedule->command('foobar', ['a' => 'b'])->environments(['local', 'uat'])->everyMinute();
        $schedule->command('foobar')->hourly();

        $filteredEvents = $schedule->eventsForEnvironments(['production', 'staging']);

        $this->assertCount(3, $filteredEvents);

        $this->assertSame(JobToTestWithSchedule::class, $filteredEvents[0]->description);
        $this->assertSame(['production'], $filteredEvents[0]->environments);
        $this->assertSame('0 0 * * *', $filteredEvents[0]->expression);

        $this->assertMatchesRegularExpression('/artisan.*inspire$/', $filteredEvents[1]->command);
        $this->assertSame(['staging', 'production'], $filteredEvents[1]->environments);
        $this->assertSame('* * * * *', $filteredEvents[1]->expression);

        $this->assertMatchesRegularExpression('/artisan.*foobar$/', $filteredEvents[2]->command);
        $this->assertSame([], $filteredEvents[2]->environments);
        $this->assertSame('0 * * * *', $filteredEvents[2]->expression);
    }

    public function testItCanAddAttributesToEvents(): void
    {
        $schedule = new Schedule();

        $event = $schedule->command('inspire')
            ->withAttributes(['team' => 'platform'])
            ->withAttributes(['labels' => ['maintenance']]);

        $this->assertSame([
            'team' => 'platform',
            'labels' => ['maintenance'],
        ], $event->attributes);
    }

    public function testItCanAddAttributesToPendingEvents(): void
    {
        $schedule = new Schedule();

        $schedule->withAttributes(['team' => 'platform'])->command('inspire');
        $schedule->command('queue:work');

        $events = $schedule->events();

        $this->assertSame(['team' => 'platform'], $events[0]->attributes);
        $this->assertSame([], $events[1]->attributes);
    }
}
