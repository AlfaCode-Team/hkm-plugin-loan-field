<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanField;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\LoanField\API\Contracts\FieldServiceContract;
use Plugins\LoanField\Application\Services\FieldService;
use Plugins\LoanField\Infrastructure\Http\Controllers\ContactController;
use Plugins\LoanField\Infrastructure\Http\Controllers\ReminderController;
use Plugins\LoanField\Infrastructure\Http\Controllers\VisitController;
use Plugins\LoanField\Provider;
use Plugins\LoanLoans\Provider as LoansProvider;
use Plugins\LoanOrganisation\Provider as OrganisationProvider;
use Plugins\LoanSync\Provider as SyncProvider;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\Unit\Plugins\LoanField\Support\FieldHarness;

/**
 * Provider.php wired through the REAL ModuleContainer, the way OnDemandLoader
 * wires it, with Sync's own Provider supplying the revision contract. Then the
 * controllers are driven with real HTTP-shaped requests against the real
 * tenant database.
 *
 * This is the test that catches a wrong binding, a missing scope, or a
 * controller that cannot be built. The service tests cannot see those.
 */
#[CoversClass(Provider::class)]
#[CoversClass(VisitController::class)]
#[CoversClass(ReminderController::class)]
#[CoversClass(ContactController::class)]
final class WiringTest extends TestCase
{
    private ?FieldHarness $harness = null;

    protected function tearDown(): void
    {
        // Positively identified: the one file this test created.
        $this->harness?->destroy();
        $this->harness = null;
    }

    private function container(): ModuleContainer
    {
        $h = $this->harness = new FieldHarness();

        $core = new CoreContainer();
        $core->instance(DatabasePort::class, $h->db);
        $core->instance(ClockPort::class, $h->clock);
        $core->instance(TransactionManager::class, new TransactionManager($h->db));
        $core->instance(Identity::class, new Identity(
            userId: '42',
            tenantId: 'tenant-test',
            roles: ['officer'],
            permissions: [],
            tokenType: 'jwt',
        ));

        $m = new ModuleContainer($core);

        $m->setScope('sync.offline');
        (new SyncProvider())->register($m);
        // Field resolves `recorded_by` through Organisation's staff directory.
        (new OrganisationProvider())->register($m);
        // Field asks Loans which status means "in arrears".
        (new LoansProvider())->register($m);

        $m->setScope('field.operations');
        (new Provider())->register($m);

        return $m;
    }

    private function http(string $method, string $uri, ?array $json = null): Request
    {
        $sym = SymfonyRequest::create(
            $uri,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR),
        );

        return Request::createFromBase($sym);
    }

    public function test_the_contract_and_every_controller_resolve_in_their_own_scope(): void
    {
        $m = $this->container();

        self::assertInstanceOf(FieldService::class, $m->make(FieldServiceContract::class));
        self::assertInstanceOf(VisitController::class, $m->makeInScope(VisitController::class, 'field.operations'));
        self::assertInstanceOf(ReminderController::class, $m->makeInScope(ReminderController::class, 'field.operations'));
        self::assertInstanceOf(ContactController::class, $m->makeInScope(ContactController::class, 'field.operations'));
    }

    public function test_the_internal_repositories_are_not_reachable_from_another_module(): void
    {
        $m = $this->container();

        $this->expectException(\AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ScopeViolationException::class);

        $m->makeInScope(\Plugins\LoanField\Infrastructure\Persistence\VisitRepository::class, 'loans.lending');
    }

    public function test_a_visit_posted_to_the_controller_is_created_and_takes_a_rev(): void
    {
        $m = $this->container();
        $this->harness->customer(1, 'Aminah Nakato');

        $controller = $m->makeInScope(VisitController::class, 'field.operations');

        $response = $controller->store($this->http('POST', '/api/field/visits', [
            'customer_id' => 1,
            'visit_type'  => 'payment_collection',
            'outcome'     => 'promised_to_pay',
            'visited_on'  => '2026-11-13',
            'latitude'    => 0.3476,
            'longitude'   => 32.5826,
        ]));

        self::assertSame(201, $response->status());

        $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['rev']);
        self::assertSame('promised_to_pay', $body['outcome']);
        // The STAFF id the signed-in user maps to, not the user id.
        self::assertSame(\Tests\Unit\Plugins\LoanField\Support\FieldHarness::STAFF_ID, $body['recorded_by']);
    }

    public function test_the_list_and_contact_endpoints_answer_over_the_same_wiring(): void
    {
        $m = $this->container();
        $this->harness->customer(1, 'Aminah Nakato');
        $this->harness->loan(10, 1, 'late', 7, 90_000, 14);

        $visits = $m->makeInScope(VisitController::class, 'field.operations');
        $visits->store($this->http('POST', '/api/field/visits', [
            'customer_id' => 1, 'visit_type' => 'payment_collection', 'visited_on' => '2026-11-13',
        ]));

        $list = json_decode($visits->index($this->http('GET', '/api/field/visits?customer_id=1'))->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $list['total']);

        $contacts = $m->makeInScope(ContactController::class, 'field.operations');
        $page     = $contacts->notContacted($this->http('GET', '/api/field/contacts/not-contacted?days=7'));

        self::assertSame(200, $page->status());

        $body = json_decode($page->body(), true, 512, JSON_THROW_ON_ERROR);

        // Aminah was visited on 13 Nov, inside the 7-day window, so she is NOT listed.
        self::assertSame(0, $body['total']);
        self::assertSame('2026-11-07', $body['since']);
    }

    public function test_a_refused_coordinate_reaches_the_caller_as_a_service_exception(): void
    {
        $m = $this->container();
        $this->harness->customer(1, 'Aminah Nakato');

        $controller = $m->makeInScope(VisitController::class, 'field.operations');

        $this->expectException(ServiceException::class);

        $controller->store($this->http('POST', '/api/field/visits', [
            'customer_id' => 1, 'visit_type' => 'other', 'visited_on' => '2026-11-13',
            'latitude' => 0.3476,            // half a fix
        ]));
    }
}
