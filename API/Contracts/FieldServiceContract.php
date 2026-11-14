<?php

declare(strict_types=1);

namespace Plugins\LoanField\API\Contracts;

/**
 * The published surface of field operations: visits and reminders.
 *
 * Every write takes a sync revision inside its transaction, so a visit recorded
 * on the web reaches the handsets on their next pull, exactly as a device push
 * does.
 *
 * Money in this domain is whole UGX. A reminder's amount_quoted is what the
 * customer was told, and is stored as quoted.
 */
interface FieldServiceContract
{
    /**
     * @param  array<string, mixed> $input  customer_id?, loan_id?, visit_type, visited_on?,
     *         visited_at?, outcome?, notes?, latitude?, longitude?, photo_path?
     * @return array<string, mixed>
     */
    public function recordVisit(array $input): array;

    /**
     * @param  array<string, mixed> $filters  customer_id, loan_id, recorded_by, visit_type,
     *         outcome, from, to (Y-m-d on visited_on), page, per_page
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, total: int}
     */
    public function listVisits(array $filters): array;

    /** @return array<string, mixed> */
    public function showVisit(int $id): array;

    /**
     * @param  array<string, mixed> $input  customer_id, loan_id?, channel, amount_quoted?,
     *         message?, sent_at?
     * @return array<string, mixed>
     */
    public function recordReminder(array $input): array;

    /**
     * @param  array<string, mixed> $filters  customer_id, loan_id, from, to (Y-m-d on sent_at, business
     *         timezone), page, per_page
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, total: int}
     */
    public function listReminders(array $filters): array;

    /**
     * Customers with at least one LATE loan who have had neither a visit nor a
     * reminder inside the last `days` days.
     *
     * "Within the last N days" means on or after the calendar day N days before
     * today, in the business timezone. A contact on that day counts.
     *
     * @param  array<string, mixed> $filters  days (1-365, default 14), field_officer_id, page, per_page
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, total: int, days: int, since: string}
     */
    public function customersNotContacted(array $filters): array;
}
