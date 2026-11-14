<?php

declare(strict_types=1);

namespace Plugins\LoanField\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * The read side of "who has not been contacted", plus two existence checks.
 *
 * This is READ-ONLY over `customers` and `loans`, which belong to other
 * modules. It writes nothing there. It reads them because "in arrears" is a
 * fact about a loan and "who the customer is" is a fact about a customer, and
 * the question cannot be answered without both.
 *
 * Arrears means a loan whose status is 'late' (LoanStatus::Late), not deleted.
 *
 * Portable SQL only: no date functions, no driver clause. Every date is
 * computed in PHP and bound here.
 */
final class ContactRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    public function customerExists(int $id): bool
    {
        return $this->guard('read a customer', fn() => $this->db->queryOne(
            'SELECT id FROM customers WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        )) !== null;
    }

    public function loanBelongsTo(int $loanId, int $customerId): bool
    {
        return $this->guard('read a loan', fn() => $this->db->queryOne(
            'SELECT id FROM loans WHERE id = :id AND customer_id = :customer AND deleted_at IS NULL',
            ['id' => $loanId, 'customer' => $customerId],
        )) !== null;
    }

    /**
     * One row per customer, however many late loans they hold.
     *
     * THIS QUERY READS TWO TABLES THIS MODULE DOES NOT OWN (`customers` and
     * `loans`), and that is a deliberate exception rather than an oversight.
     * Going through the owning modules' contracts would mean loading every
     * customer in arrears into PHP to join them against this module's visits
     * and reminders — losing the aggregation, the paging and the ordering to
     * gain nothing a reader can point at.
     *
     * What the exception costs is COUPLING, so the part that would otherwise
     * rot silently is removed: the meaning of "in arrears" is not hardcoded
     * here. It is asked of the Loans module, which owns it, and an unknown
     * `loans.management` dependency now fails the boot instead of quietly
     * returning nobody.
     *
     * @param  string $arrearsStatus  the `loans.status` meaning "in arrears", asked of the
     *                                 Loans module rather than hardcoded here
     * @param  ?int   $officerId      when set, only late loans collected by this officer count
     * @param  string $visitSince     'Y-m-d': a visit on or after this date is a contact
     * @param  string $reminderSince  'Y-m-d H:i:s' UTC: a reminder at or after this is a contact
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function notContacted(
        string $arrearsStatus,
        ?int $officerId,
        string $visitSince,
        string $reminderSince,
        int $limit,
        int $offset,
    ): array {
        $officerClause = $officerId === null ? '' : ' AND l.field_officer_id = :officer';

        $base = <<<SQL
            SELECT c.id AS customer_id,
                   c.full_name,
                   c.phone,
                   COUNT(l.id) AS late_loans,
                   COALESCE(SUM(l.loan_balance), 0) AS loan_balance_total,
                   COALESCE(MAX(l.days_overdue), 0) AS max_days_overdue,
                   (SELECT MAX(v.visited_on) FROM visits v
                      WHERE v.customer_id = c.id AND v.deleted_at IS NULL) AS last_visit_on,
                   (SELECT MAX(r.sent_at) FROM reminders r
                      WHERE r.customer_id = c.id AND r.deleted_at IS NULL) AS last_reminded_at
              FROM customers c
              JOIN loans l ON l.customer_id = c.id
                          AND l.deleted_at IS NULL
                          AND l.status = :arrears{$officerClause}
             WHERE c.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM visits v
                                WHERE v.customer_id = c.id AND v.deleted_at IS NULL
                                  AND v.visited_on >= :visit_since)
               AND NOT EXISTS (SELECT 1 FROM reminders r
                                WHERE r.customer_id = c.id AND r.deleted_at IS NULL
                                  AND r.sent_at >= :reminder_since)
             GROUP BY c.id, c.full_name, c.phone
            SQL;

        $params = [
            'arrears'        => $arrearsStatus,
            'visit_since'    => $visitSince,
            'reminder_since' => $reminderSince,
        ];

        if ($officerId !== null) {
            $params['officer'] = $officerId;
        }

        $total = $this->guard('count customers not contacted', fn() => $this->db->queryOne(
            'SELECT COUNT(*) AS n FROM (' . $base . ') t',
            $params,
        ));

        $rows = $this->guard('list customers not contacted', fn() => $this->db->query(
            $base
                . ' ORDER BY COALESCE(MAX(l.days_overdue), 0) DESC, c.full_name ASC, c.id ASC'
                . SqlDialect::of($this->db)->page($limit, $offset),
            $params,
        ));

        return ['rows' => $rows, 'total' => (int) ($total['n'] ?? 0)];
    }

    /**
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    private function guard(string $what, callable $work): mixed
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to ' . $what . '.', layer: 'repository.field.contacts', previous: $e);
        }
    }
}
