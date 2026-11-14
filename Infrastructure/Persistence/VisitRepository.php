<?php

declare(strict_types=1);

namespace Plugins\LoanField\Infrastructure\Persistence;

/** The `visits` table. Reads exclude tombstones unless stated otherwise. */
final class VisitRepository extends SyncedTableRepository
{
    protected function table(): string
    {
        return 'visits';
    }

    /** @param array<string, mixed> $columns  including `id` */
    public function insert(array $columns, int $rev, string $device, string $now): void
    {
        $this->guard('insert a visit', fn() => $this->insertRow($columns, $rev, $device, $now));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->guard('read a visit', fn() => $this->db->queryOne(
            'SELECT * FROM visits WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        ));
    }

    /**
     * @param  array{customer_id: ?int, loan_id: ?int, recorded_by: ?int, visit_type: ?string,
     *               outcome: ?string, from: ?string, to: ?string} $f
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::where($f);

        $total = $this->guard('count visits', fn() => $this->db->queryOne(
            'SELECT COUNT(*) AS n FROM visits WHERE ' . $where,
            $params,
        ));

        $rows = $this->guard('list visits', fn() => $this->db->query(
            sprintf(
                'SELECT * FROM visits WHERE %s ORDER BY visited_on DESC, id DESC%s',
                $where,
                SqlDialect::of($this->db)->page($limit, $offset),
            ),
            $params,
        ));

        return ['rows' => $rows, 'total' => (int) ($total['n'] ?? 0)];
    }

    /**
     * Column names are fixed here; only values come from the request, and they
     * are bound.
     *
     * @return array{string, array<string, mixed>}
     */
    private static function where(array $f): array
    {
        $clauses = ['deleted_at IS NULL'];
        $params  = [];

        foreach (['customer_id' => 'customer_id', 'loan_id' => 'loan_id', 'recorded_by' => 'recorded_by',
                  'visit_type' => 'visit_type', 'outcome' => 'outcome'] as $key => $column) {
            if ($f[$key] !== null) {
                $clauses[]  = $column . ' = :' . $key;
                $params[$key] = $f[$key];
            }
        }

        if ($f['from'] !== null) {
            $clauses[]      = 'visited_on >= :from';
            $params['from'] = $f['from'];
        }

        if ($f['to'] !== null) {
            $clauses[]    = 'visited_on <= :to';
            $params['to'] = $f['to'];
        }

        return [implode(' AND ', $clauses), $params];
    }
}
