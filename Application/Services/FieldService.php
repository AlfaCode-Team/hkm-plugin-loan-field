<?php

declare(strict_types=1);

namespace Plugins\LoanField\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanLoans\API\Contracts\LoanServiceContract;
use Plugins\LoanOrganisation\API\Contracts\StaffDirectoryContract;
use Plugins\LoanField\API\Contracts\FieldServiceContract;
use Plugins\LoanField\Domain\Rules\RecordingWindow;
use Plugins\LoanField\Domain\ValueObjects\Coordinates;
use Plugins\LoanField\Domain\ValueObjects\QuotedAmount;
use Plugins\LoanField\Infrastructure\Persistence\ContactRepository;
use Plugins\LoanField\Infrastructure\Persistence\LegacyIdAllocator;
use Plugins\LoanField\Infrastructure\Persistence\ReminderRepository;
use Plugins\LoanField\Infrastructure\Persistence\VisitRepository;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;

/**
 * Field operations: a visit, a reminder, and who has not been contacted.
 *
 * Every write follows one shape: begin, reserve the revision INSIDE the
 * transaction, write the row under that revision, commit. A row written
 * without a rev never reaches a handset.
 *
 * What this module RECORDS about a reminder is an attempt. It does not send
 * one; see ReminderChannel.
 */
final class FieldService implements FieldServiceContract
{
    private const string DEVICE = 'server';

    public function __construct(
        private readonly VisitRepository $visits,
        private readonly ReminderRepository $reminders,
        private readonly ContactRepository $contacts,
        private readonly LegacyIdAllocator $ids,
        private readonly SyncRevisionContract $revisions,
        private readonly TransactionManager $transaction,
        private readonly ClockPort $clock,
        private readonly Identity $identity,
        private readonly StaffDirectoryContract $staff,
        private readonly LoanServiceContract $loans,
        private readonly \DateTimeZone $zone,
    ) {
    }

    public function recordVisit(array $input): array
    {
        $actor = $this->actor();
        $now   = $this->clock->now();
        $in    = FieldInput::visit($input, $this->zone, $now);

        $this->assertCustomer($in['customer_id']);
        $this->assertLoan($in['loan_id'], $in['customer_id']);

        try {
            $coords = Coordinates::optional($in['latitude'], $in['longitude']);
            RecordingWindow::assertNotFuture($in['visited_on'], $now, 'visit date');

            if ($in['visited_at'] !== null) {
                RecordingWindow::assertNotFuture($in['visited_at'], $now, 'visit time');
            }
        } catch (\DomainException $e) {
            throw new ServiceException($e->getMessage(), layer: 'service.field', previous: $e);
        }

        $this->transaction->begin();

        try {
            $id  = $this->ids->reserve('visits');
            $rev = $this->revisions->next();

            $this->visits->insert([
                'id'          => $id,
                'customer_id' => $in['customer_id'],
                'loan_id'     => $in['loan_id'],
                'visit_type'  => $in['visit_type']->value,
                'visited_on'  => $in['visited_on']->format('Y-m-d'),
                'visited_at'  => $in['visited_at'] === null ? null : self::utc($in['visited_at']),
                'outcome'     => $in['outcome']?->value,
                'notes'       => $in['notes'],
                'latitude'    => $coords?->latitude,
                'longitude'   => $coords?->longitude,
                'photo_path'  => $in['photo_path'],
                'recorded_by' => $actor,
            ], $rev, self::DEVICE, self::utc($now));

            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw $this->wrap($e, 'Failed to record the visit.');
        }

        return self::visitView($this->requireVisit($id));
    }

    public function listVisits(array $filters): array
    {
        $f = FieldInput::visitFilters($filters, $this->zone);

        $result = $this->visits->search(
            [
                'customer_id' => $f['customer_id'],
                'loan_id'     => $f['loan_id'],
                'recorded_by' => $f['recorded_by'],
                'visit_type'  => $f['visit_type'],
                'outcome'     => $f['outcome'],
                'from'        => $f['from'],
                'to'          => $f['to'],
            ],
            $f['per_page'],
            ($f['page'] - 1) * $f['per_page'],
        );

        return [
            'data'     => array_map(self::visitView(...), $result['rows']),
            'page'     => $f['page'],
            'per_page' => $f['per_page'],
            'total'    => $result['total'],
        ];
    }

    public function showVisit(int $id): array
    {
        return self::visitView($this->requireVisit($id));
    }

    public function recordReminder(array $input): array
    {
        $actor = $this->actor();
        $now   = $this->clock->now();
        $in    = FieldInput::reminder($input, $this->zone, $now);

        $this->assertCustomer($in['customer_id']);
        $this->assertLoan($in['loan_id'], $in['customer_id']);

        try {
            $amount = $in['amount_quoted'] === null ? null : QuotedAmount::ugx($in['amount_quoted'])->shillings;
            RecordingWindow::assertNotFuture($in['sent_at'], $now, 'reminder');
        } catch (\DomainException $e) {
            throw new ServiceException($e->getMessage(), layer: 'service.field', previous: $e);
        }

        $this->transaction->begin();

        try {
            $id  = $this->ids->reserve('reminders');
            $rev = $this->revisions->next();

            $this->reminders->insert([
                'id'            => $id,
                'customer_id'   => $in['customer_id'],
                'loan_id'       => $in['loan_id'],
                'channel'       => $in['channel']->value,
                'amount_quoted' => $amount,
                'message'       => $in['message'],
                'sent_at'       => self::utc($in['sent_at']),
                'sent_by'       => $actor,
            ], $rev, self::DEVICE, self::utc($now));

            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw $this->wrap($e, 'Failed to record the reminder.');
        }

        return self::reminderView($this->requireReminder($id));
    }

    public function listReminders(array $filters): array
    {
        $f = FieldInput::reminderFilters($filters, $this->zone);

        $result = $this->reminders->search(
            ['customer_id' => $f['customer_id'], 'loan_id' => $f['loan_id'], 'from' => $f['from'], 'to' => $f['to']],
            $f['per_page'],
            ($f['page'] - 1) * $f['per_page'],
        );

        return [
            'data'     => array_map(self::reminderView(...), $result['rows']),
            'page'     => $f['page'],
            'per_page' => $f['per_page'],
            'total'    => $result['total'],
        ];
    }

    public function customersNotContacted(array $filters): array
    {
        $f = FieldInput::contacts($filters);

        // Business-day arithmetic in the business timezone. "Last N days" is the
        // calendar day N days before today, onward. Visits compare on that date;
        // reminders compare on the UTC instant that day starts at locally.
        $since = $this->clock->now()
            ->setTimezone($this->zone)
            ->setTime(0, 0)
            ->modify('-' . $f['days'] . ' days');

        $visitSince    = $since->format('Y-m-d');
        $reminderSince = self::utc($since);

        $result = $this->contacts->notContacted(
            $this->loans->arrearsStatus(),
                $f['field_officer_id'],
            $visitSince,
            $reminderSince,
            $f['per_page'],
            ($f['page'] - 1) * $f['per_page'],
        );

        return [
            'data'     => array_map(self::contactView(...), $result['rows']),
            'page'     => $f['page'],
            'per_page' => $f['per_page'],
            'total'    => $result['total'],
            'days'     => $f['days'],
            'since'    => $visitSince,
        ];
    }

    /** Only a signed-in officer can record field work: recorded_by is NOT NULL. */
    private function actor(): int
    {
        if ($this->identity->isGuest()) {
            throw new ServiceException('Only a signed-in officer can record field work.', layer: 'service.field');
        }

        // `recorded_by` is a staff_members.id, not the users.id on the token.
        // Both are numeric, so confusing them fails silently and attributes a
        // visit to the wrong officer.
        $staffId = $this->staff->staffIdForUser($this->identity->userId);

        if ($staffId === null) {
            throw new ServiceException(
                'This account is not a staff member of this business.',
                layer: 'service.field',
                context: ['user_id' => $this->identity->userId],
            );
        }

        return $staffId;
    }

    private function assertCustomer(?int $customerId): void
    {
        if ($customerId !== null && !$this->contacts->customerExists($customerId)) {
            throw new ServiceException(sprintf('Customer [%d] was not found.', $customerId), layer: 'service.field');
        }
    }

    private function assertLoan(?int $loanId, ?int $customerId): void
    {
        if ($loanId === null) {
            return;
        }

        if ($customerId === null || !$this->contacts->loanBelongsTo($loanId, $customerId)) {
            throw new ServiceException(
                sprintf('Loan [%d] was not found for that customer.', $loanId),
                layer: 'service.field',
            );
        }
    }

    /** @return array<string, mixed> */
    private function requireVisit(int $id): array
    {
        return $this->visits->find($id)
            ?? throw new ServiceException(sprintf('Visit [%d] was not found.', $id), layer: 'service.field');
    }

    /** @return array<string, mixed> */
    private function requireReminder(int $id): array
    {
        return $this->reminders->find($id)
            ?? throw new ServiceException(sprintf('Reminder [%d] was not found.', $id), layer: 'service.field');
    }

    private function wrap(\Throwable $e, string $fallback): ServiceException
    {
        if ($e instanceof ServiceException) {
            return $e;
        }

        return new ServiceException($fallback, layer: 'service.field', previous: $e);
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function visitView(array $r): array
    {
        return [
            'id'          => (int) $r['id'],
            'customer_id' => self::nullableInt($r['customer_id']),
            'loan_id'     => self::nullableInt($r['loan_id']),
            'visit_type'  => (string) $r['visit_type'],
            'visited_on'  => (string) $r['visited_on'],
            'visited_at'  => $r['visited_at'] === null ? null : (string) $r['visited_at'],
            'outcome'     => $r['outcome'] === null ? null : (string) $r['outcome'],
            'notes'       => $r['notes'] === null ? null : (string) $r['notes'],
            'latitude'    => $r['latitude'] === null ? null : (float) $r['latitude'],
            'longitude'   => $r['longitude'] === null ? null : (float) $r['longitude'],
            'photo_path'  => $r['photo_path'] === null ? null : (string) $r['photo_path'],
            'recorded_by' => (int) $r['recorded_by'],
            'rev'         => (int) $r['rev'],
            'created_at'  => (string) $r['created_at'],
            'updated_at'  => (string) $r['updated_at'],
        ];
    }

    /**
     * @param  array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function reminderView(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'customer_id'   => (int) $r['customer_id'],
            'loan_id'       => self::nullableInt($r['loan_id']),
            'channel'       => (string) $r['channel'],
            'amount_quoted' => self::nullableInt($r['amount_quoted']),
            'message'       => $r['message'] === null ? null : (string) $r['message'],
            'sent_at'       => (string) $r['sent_at'],
            'sent_by'       => (int) $r['sent_by'],
            'rev'           => (int) $r['rev'],
            'created_at'    => (string) $r['created_at'],
            'updated_at'    => (string) $r['updated_at'],
        ];
    }

    /**
     * @param  array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function contactView(array $r): array
    {
        return [
            'customer_id'        => (int) $r['customer_id'],
            'full_name'          => (string) $r['full_name'],
            'phone'              => $r['phone'] === null ? null : (string) $r['phone'],
            'late_loans'         => (int) $r['late_loans'],
            'loan_balance_total' => (int) $r['loan_balance_total'],
            'max_days_overdue'   => (int) $r['max_days_overdue'],
            'last_visit_on'      => $r['last_visit_on'] === null ? null : (string) $r['last_visit_on'],
            'last_reminded_at'   => $r['last_reminded_at'] === null ? null : (string) $r['last_reminded_at'],
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
