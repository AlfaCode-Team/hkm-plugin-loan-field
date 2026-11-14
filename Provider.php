<?php

declare(strict_types=1);

namespace Plugins\LoanField;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanLoans\API\Contracts\LoanServiceContract;
use Plugins\LoanOrganisation\API\Contracts\StaffDirectoryContract;
use Plugins\LoanField\API\Contracts\FieldServiceContract;
use Plugins\LoanField\Application\Services\FieldService;
use Plugins\LoanField\Infrastructure\Http\Controllers\ContactController;
use Plugins\LoanField\Infrastructure\Http\Controllers\ReminderController;
use Plugins\LoanField\Infrastructure\Http\Controllers\VisitController;
use Plugins\LoanField\Infrastructure\Persistence\ContactRepository;
use Plugins\LoanField\Infrastructure\Persistence\LegacyIdAllocator;
use Plugins\LoanField\Infrastructure\Persistence\ReminderRepository;
use Plugins\LoanField\Infrastructure\Persistence\VisitRepository;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;

/**
 * Field operations — owns 'field.operations'.
 *
 * Every binding is resolved per request, against the REQUEST's DatabasePort,
 * which on a tenancy host is that business's own database. Nothing here is
 * bound in the core container.
 */
final class Provider implements ModuleContract
{
    private const string DEFAULT_ZONE = 'Africa/Kampala';

    public function solves(): string
    {
        return 'field.operations';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return ['database.management', 'sync.offline', 'organisation.structure', 'loans.management'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [FieldServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(
            VisitRepository::class,
            static fn($c): VisitRepository => new VisitRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            ReminderRepository::class,
            static fn($c): ReminderRepository => new ReminderRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            ContactRepository::class,
            static fn($c): ContactRepository => new ContactRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            LegacyIdAllocator::class,
            static fn($c): LegacyIdAllocator => new LegacyIdAllocator($c->make(DatabasePort::class)),
        );

        $container->bind(
            FieldServiceContract::class,
            static fn($c): FieldService => new FieldService(
                visits:      $c->make(VisitRepository::class),
                reminders:   $c->make(ReminderRepository::class),
                contacts:    $c->make(ContactRepository::class),
                ids:         $c->make(LegacyIdAllocator::class),
                revisions:   $c->make(SyncRevisionContract::class),
                transaction: $c->make(TransactionManager::class),
                clock:       $c->has(ClockPort::class) ? $c->make(ClockPort::class) : new SystemClock(),
                identity:    $c->make(Identity::class),
                staff:       $c->make(StaffDirectoryContract::class),
                loans:       $c->make(LoanServiceContract::class),
                zone:        new \DateTimeZone(self::businessZone()),
            ),
        );

        $container->bind(
            VisitController::class,
            static fn($c): VisitController => new VisitController($c->make(FieldServiceContract::class)),
        );

        $container->bind(
            ReminderController::class,
            static fn($c): ReminderController => new ReminderController($c->make(FieldServiceContract::class)),
        );

        $container->bind(
            ContactController::class,
            static fn($c): ContactController => new ContactController($c->make(FieldServiceContract::class)),
        );
    }

    /** The timezone "today" and "in the last N days" are counted in. */
    private static function businessZone(): string
    {
        return (string) (env('BUSINESS_TIMEZONE') ?: self::DEFAULT_ZONE);
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Routes are declared in module.json; nothing to hook.
    }
}
