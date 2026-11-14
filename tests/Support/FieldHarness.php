<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanField\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanLoans\Application\Services\LoanService;
use Plugins\LoanOrganisation\Infrastructure\Persistence\StaffDirectory;
use Plugins\LoanField\Application\Services\FieldService;
use Plugins\LoanField\Infrastructure\Persistence\ContactRepository;
use Plugins\LoanField\Infrastructure\Persistence\LegacyIdAllocator;
use Plugins\LoanField\Infrastructure\Persistence\ReminderRepository;
use Plugins\LoanField\Infrastructure\Persistence\VisitRepository;
use Plugins\LoanSync\Infrastructure\Persistence\RevisionRepository;
use Tests\Unit\Plugins\LoanField\Support\TestDatabase;

/**
 * A real tenant database on disk, migrated by the real runner, behind the real
 * adapter, with the service wired exactly as Provider wires it.
 *
 * The customers and loans rows the arrears query reads are written by the
 * helpers below as raw SQL: those tables belong to other modules, and a test of
 * this module should not depend on their services.
 */
final class FieldHarness
{
    /** The staff id the seeded user maps to — intentionally NOT the user id. */
    public const int STAFF_ID = 907;

    public readonly DatabasePort $db;
    public readonly FieldService $field;
    public readonly FixedClock $clock;
    public readonly TestDatabase $database;

    /** 09:00 UTC on 14 Nov 2026 is 12:00 in Kampala, the same calendar day. */
    public function __construct(string $userId = '42', string $now = '2026-11-14 09:00:00')
    {
        // SQLite unless HKM_TEST_DB says otherwise. The same tests then run
        // against MySQL, PostgreSQL or SQL Server without a second suite.
        //
        // The schema is assembled from the PLUGINS that own each table, so
        // this suite tests against the same migrations a deployment runs.
        $this->database = TestDatabase::provision('loan_field', [
            \Plugins\LoanSync\Provider::class,
            \Plugins\LoanOrganisation\Provider::class,
            \Plugins\LoanCustomers\Provider::class,
            \Plugins\LoanLoans\Provider::class,
            \Plugins\LoanField\Provider::class,
        ]);
        $this->db    = $this->database->port;
        $this->clock = new FixedClock(new \DateTimeImmutable($now, new \DateTimeZone('UTC')));


        // `recorded_by` is a staff_members.id, so the signed-in USER must map
        // to a staff row or nothing can be recorded. Seeded here with a staff
        // id deliberately DIFFERENT from the user id, so a test that confuses
        // the two fails instead of passing by coincidence.
        $this->db->execute(
            'INSERT INTO staff_members (id, user_id, name, role, branch_id, banned, rev, created_at, updated_at)'
            . ' VALUES (:id, :user, :name, :role, :branch, :banned, 0, :created, :updated)',
            [
                'id'   => self::STAFF_ID,
                // `branch_id` and `banned` are BOUND, not written into the SQL
                // as 1 and 0: PostgreSQL refuses an integer literal in a
                // boolean column, while a bound value is sent as text and
                // coerced. A guest has no account at all, so the seeded staff
                // row points at nothing rather than at the empty string.
                'branch' => 1,
                'banned' => false,
                'user'   => $userId === '' ? null : $userId,
                'name' => 'Ruth Achieng',
                'role' => 'officer',
                'created' => '2026-11-13 08:00:00',
                'updated' => '2026-11-13 08:00:00',
            ],
        );

        $this->field = new FieldService(
            visits:      new VisitRepository($this->db),
            reminders:   new ReminderRepository($this->db),
            contacts:    new ContactRepository($this->db),
            ids:         new LegacyIdAllocator($this->db),
            revisions:   new RevisionRepository($this->db),
            transaction: new TransactionManager($this->db),
            clock:       $this->clock,
            staff:       new StaffDirectory($this->db),
            loans:       new ArrearsStub(),
            identity:    new Identity(
                userId: $userId,
                tenantId: 'tenant-test',
                roles: ['officer'],
                permissions: [],
                tokenType: 'jwt',
            ),
            zone:        new \DateTimeZone('Africa/Kampala'),
        );
    }

    public function customer(int $id, string $name, string $status = 'active', ?string $deletedAt = null): void
    {
        $this->db->execute(
            'INSERT INTO customers (id, first_name, full_name, phone, status, rev, created_at, updated_at, deleted_at)'
            . ' VALUES (:id, :first, :full, :phone, :status, 0, :created, :updated, :deleted)',
            [
                'id'      => $id,
                'first'   => $name,
                'full'    => $name,
                'phone'   => '0772' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
                'status'  => $status,
                'created' => '2026-11-01 08:00:00',
                'updated' => '2026-11-01 08:00:00',
                'deleted' => $deletedAt,
            ],
        );
    }

    public function loan(
        int $id,
        int $customerId,
        string $status = 'late',
        int $officer = 7,
        int $balance = 100_000,
        int $daysOverdue = 0,
        ?string $deletedAt = null,
    ): void {
        $this->db->execute(
            'INSERT INTO loans (id, loan_number, customer_id, customer_name, loan_amount, total_to_be_paid,'
            . ' loan_balance, payment_type, number_of_payments, given_date, due_date, status,'
            . ' field_officer_id, days_overdue, rev, created_at, updated_at, deleted_at)'
            . ' VALUES (:id, :number, :customer, :name, 100000, 120000, :balance, \'weekly\', 10,'
            . ' \'2026-09-01\', \'2026-11-10\', :status, :officer, :overdue, 0, :created, :updated, :deleted)',
            [
                'id'       => $id,
                'number'   => 'LN-TEST-' . $id,
                'customer' => $customerId,
                'name'     => 'customer ' . $customerId,
                'balance'  => $balance,
                'status'   => $status,
                'officer'  => $officer,
                'overdue'  => $daysOverdue,
                'created'  => '2026-11-01 08:00:00',
                'updated'  => '2026-11-01 08:00:00',
                'deleted'  => $deletedAt,
            ],
        );
    }

    /** A visit written as another officer would have left it: raw, with its own id. */
    public function rawVisit(int $id, int $customerId, string $visitedOn, int $recordedBy, ?string $deletedAt = null): void
    {
        $this->db->execute(
            'INSERT INTO visits (id, customer_id, visit_type, visited_on, recorded_by, rev, created_at, updated_at, deleted_at)'
            . ' VALUES (:id, :customer, \'arrears_follow_up\', :on, :by, 0, :created, :updated, :deleted)',
            [
                'id'       => $id,
                'customer' => $customerId,
                'on'       => $visitedOn,
                'by'       => $recordedBy,
                'created'  => $visitedOn . ' 10:00:00',
                'updated'  => $visitedOn . ' 10:00:00',
                'deleted'  => $deletedAt,
            ],
        );
    }

    public function softDeleteVisit(int $id, string $when): void
    {
        $this->db->execute(
            'UPDATE visits SET deleted_at = :when WHERE id = :id',
            ['when' => $when, 'id' => $id],
        );
    }

    /** @return array<string, mixed> */
    public function row(string $sql, array $params = []): array
    {
        return $this->db->queryOne($sql, $params) ?? [];
    }

    public function destroy(): void
    {
        $this->database->destroy();
    }
}
