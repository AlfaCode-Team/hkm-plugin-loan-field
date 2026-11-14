<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanField\Support;

use Plugins\LoanLoans\API\Contracts\LoanServiceContract;

/**
 * Field asks Loans for ONE thing: which `loans.status` means "in arrears".
 *
 * Standing up a real LoanService here would drag its repositories, id
 * allocator and revision counter into a Field test to answer a single string.
 * Every other method throws, so if Field ever starts leaning on Loans for more
 * than this, a test says so instead of the dependency quietly widening.
 */
final class ArrearsStub implements LoanServiceContract
{
    public function arrearsStatus(): string
    {
        return 'late';
    }

    public function list(array $filters): array
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::list() — it needs the arrears status and nothing else.',
        );
    }

    public function show(int $id): array
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::show() — it needs the arrears status and nothing else.',
        );
    }

    public function create(array $input): array
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::create() — it needs the arrears status and nothing else.',
        );
    }

    public function update(int $id, array $changes): array
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::update() — it needs the arrears status and nothing else.',
        );
    }

    public function markLate(int $id): array
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::markLate() — it needs the arrears status and nothing else.',
        );
    }

    public function cure(int $id): array
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::cure() — it needs the arrears status and nothing else.',
        );
    }

    public function markPaid(int $id): array
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::markPaid() — it needs the arrears status and nothing else.',
        );
    }

    public function close(int $id): array
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::close() — it needs the arrears status and nothing else.',
        );
    }

    public function flagForReview(int $id, string $reason): array
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::flagForReview() — it needs the arrears status and nothing else.',
        );
    }

    public function clearReview(int $id, string $note): array
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::clearReview() — it needs the arrears status and nothing else.',
        );
    }

    public function delete(int $id): void
    {
        throw new \LogicException(
            'Field must not call LoanServiceContract::delete() — it needs the arrears status and nothing else.',
        );
    }
}
