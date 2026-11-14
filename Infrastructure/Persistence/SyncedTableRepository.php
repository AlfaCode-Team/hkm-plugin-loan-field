<?php

declare(strict_types=1);

namespace Plugins\LoanField\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * The write rules every synced table in this module shares.
 *
 * Every write takes the revision it is given, and stamps the device and the
 * timestamps. Nothing here issues DELETE. Column names come from the calling
 * repository's own code, never from a request; values are always bound.
 *
 * Copied from the Loans module's pattern, trimmed to what this module writes.
 * Module boundaries are kept by copying the shape, not by importing internals.
 */
abstract class SyncedTableRepository
{
    public function __construct(
        protected readonly DatabasePort $db,
    ) {
    }

    abstract protected function table(): string;

    /** @param array<string, mixed> $columns  including `id` */
    protected function insertRow(array $columns, int $rev, string $device, string $now): void
    {
        $values = $columns + [
            'rev'        => $rev,
            'device_id'  => $device,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        $names = array_keys($values);

        $this->db->execute(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->table(),
                implode(', ', $names),
                implode(', ', array_map(static fn(string $n): string => ':' . $n, $names)),
            ),
            $values,
        );
    }

    /**
     * Turn a driver failure into this layer's own exception, so no vendor
     * exception escapes the repository.
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    protected function guard(string $what, callable $work): mixed
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to ' . $what . '.',
                layer: 'repository.field',
                previous: $e,
            );
        }
    }
}
