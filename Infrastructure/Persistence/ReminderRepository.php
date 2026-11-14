<?php

declare(strict_types=1);

namespace Plugins\LoanField\Infrastructure\Persistence;

/** The `reminders` table: a nudge that was ATTEMPTED, by whatever channel. */
final class ReminderRepository extends SyncedTableRepository
{
    protected function table(): string
    {
        return 'reminders';
    }

    /** @param array<string, mixed> $columns  including `id` */
    public function insert(array $columns, int $rev, string $device, string $now): void
    {
        $this->guard('insert a reminder', fn() => $this->insertRow($columns, $rev, $device, $now));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->guard('read a reminder', fn() => $this->db->queryOne(
            'SELECT * FROM reminders WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        ));
    }

    /**
     * @param  array{customer_id: ?int, loan_id: ?int, from: ?string, to: ?string} $f
     *         from / to are UTC 'Y-m-d H:i:s' bounds on sent_at, already converted
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $f, int $limit, int $offset): array
    {
        $clauses = ['deleted_at IS NULL'];
        $params  = [];

        if ($f['customer_id'] !== null) {
            $clauses[]           = 'customer_id = :customer_id';
            $params['customer_id'] = $f['customer_id'];
        }

        if ($f['loan_id'] !== null) {
            $clauses[]       = 'loan_id = :loan_id';
            $params['loan_id'] = $f['loan_id'];
        }

        if ($f['from'] !== null) {
            $clauses[]      = 'sent_at >= :from';
            $params['from'] = $f['from'];
        }

        if ($f['to'] !== null) {
            $clauses[]    = 'sent_at < :to';
            $params['to'] = $f['to'];
        }

        $where = implode(' AND ', $clauses);

        $total = $this->guard('count reminders', fn() => $this->db->queryOne(
            'SELECT COUNT(*) AS n FROM reminders WHERE ' . $where,
            $params,
        ));

        $rows = $this->guard('list reminders', fn() => $this->db->query(
            sprintf(
                'SELECT * FROM reminders WHERE %s ORDER BY sent_at DESC, id DESC%s',
                $where,
                SqlDialect::of($this->db)->page($limit, $offset),
            ),
            $params,
        ));

        return ['rows' => $rows, 'total' => (int) ($total['n'] ?? 0)];
    }
}
