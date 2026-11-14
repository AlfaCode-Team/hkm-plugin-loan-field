<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanField;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\LoanField\Application\Services\FieldService;
use Tests\Unit\Plugins\LoanField\Support\FieldHarness;

/**
 * "Customers in arrears who have not been visited or reminded in the last N
 * days." Today is 14 Nov 2026 in Kampala, so with N = 7 the window opens on the
 * calendar day 7 Nov. Kampala is UTC+3, so local midnight on 7 Nov is 21:00 UTC
 * on 6 Nov: a reminder timed in that gap is the boundary case, and it is in the
 * fixture on purpose.
 */
#[CoversClass(FieldService::class)]
final class ContactQueryTest extends TestCase
{
    private ?FieldHarness $harness = null;

    protected function tearDown(): void
    {
        // Positively identified: the one file this test created.
        $this->harness?->destroy();
        $this->harness = null;
    }

    /**
     * Customer  | state                                         | expected at N = 7
     * ----------+-----------------------------------------------+------------------
     * Aminah  1 | late, nothing recorded                         | IN
     * Bosco   2 | late, visited 8 Nov (inside window)            | out
     * Christine 3 | late, visited 6 Nov (outside window)         | IN
     * Denis   4 | late, reminded 7 Nov 10:00 local (inside)      | out
     * Esther  5 | late, reminded 6 Nov 23:59:59 local (outside)  | IN
     * Fred    6 | late, reminded 7 Nov 00:00 local (inside edge) | out
     * Grace   7 | ACTIVE loan only, nothing recorded             | out (not in arrears)
     * Henry   8 | late, visit 10 Nov but SOFT-DELETED           | IN
     * Irene   9 | two late loans, nothing recorded              | IN (one row)
     * Joel   10 | late, visited 12 Nov by ANOTHER officer       | out (any officer counts)
     * Kato   11 | late, but the customer is soft-deleted        | out
     * Lydia  12 | late, nothing recorded, officer 8             | IN
     * Mugisha 13| late loan, but the LOAN is soft-deleted       | out
     */
    private function seed(): FieldHarness
    {
        $h = $this->harness = new FieldHarness();

        $names = [
            1 => 'Aminah', 2 => 'Bosco', 3 => 'Christine', 4 => 'Denis', 5 => 'Esther', 6 => 'Fred',
            7 => 'Grace', 8 => 'Henry', 9 => 'Irene', 10 => 'Joel', 11 => 'Kato', 12 => 'Lydia', 13 => 'Mugisha',
        ];

        foreach ($names as $id => $name) {
            $h->customer($id, $name, 'active', $id === 11 ? '2026-10-01 00:00:00' : null);
        }

        $h->loan(101, 1, 'late', 7, 100_000, 30);
        $h->loan(102, 2, 'late', 7, 100_000, 25);
        $h->loan(103, 3, 'late', 7, 100_000, 45);
        $h->loan(104, 4, 'late', 7, 100_000, 8);
        $h->loan(105, 5, 'late', 7, 100_000, 10);
        $h->loan(106, 6, 'late', 7, 100_000, 6);
        $h->loan(107, 7, 'active', 7, 100_000, 0);
        $h->loan(108, 8, 'late', 7, 100_000, 20);
        $h->loan(109, 9, 'late', 7, 50_000, 5);
        $h->loan(110, 9, 'late', 7, 70_000, 12);
        $h->loan(111, 10, 'late', 7, 100_000, 9);
        $h->loan(112, 11, 'late', 7, 100_000, 40);
        $h->loan(113, 12, 'late', 8, 100_000, 3);
        $h->loan(114, 13, 'late', 7, 100_000, 15, '2026-10-20 00:00:00');

        $h->field->recordVisit(['customer_id' => 2, 'visit_type' => 'arrears_follow_up', 'visited_on' => '2026-11-08']);
        $h->field->recordVisit(['customer_id' => 3, 'visit_type' => 'arrears_follow_up', 'visited_on' => '2026-11-06']);

        $h->field->recordReminder(['customer_id' => 4, 'channel' => 'whatsapp', 'sent_at' => '2026-11-07 10:00:00']);
        $h->field->recordReminder(['customer_id' => 5, 'channel' => 'sms', 'sent_at' => '2026-11-06 23:59:59']);
        $h->field->recordReminder(['customer_id' => 6, 'channel' => 'call', 'sent_at' => '2026-11-07 00:00:00']);

        $h->field->recordVisit(['customer_id' => 8, 'visit_type' => 'arrears_follow_up', 'visited_on' => '2026-11-10']);
        $h->softDeleteVisit(
            (int) $h->row('SELECT id FROM visits WHERE customer_id = 8')['id'],
            '2026-11-11 09:00:00',
        );

        $h->rawVisit(9001, 10, '2026-11-12', 99);

        return $h;
    }

    /** @return list<string> */
    private function names(array $page): array
    {
        return array_column($page['data'], 'full_name');
    }

    public function test_not_contacted_in_seven_days_returns_exactly_the_right_customers_in_arrears_order(): void
    {
        $h = $this->seed();

        $page = $h->field->customersNotContacted(['days' => 7]);

        self::assertSame(7, $page['days']);
        self::assertSame('2026-11-07', $page['since'], 'the window opens on the calendar day 7 days before today');
        self::assertSame(6, $page['total']);

        // Most overdue first: Christine 45, Aminah 30, Henry 20, Irene 12, Esther 10, Lydia 3.
        self::assertSame(['Christine', 'Aminah', 'Henry', 'Irene', 'Esther', 'Lydia'], $this->names($page));
    }

    public function test_a_customer_with_two_late_loans_is_one_row_with_both_summed(): void
    {
        $h = $this->seed();

        $page = $h->field->customersNotContacted(['days' => 7]);
        $irene = array_values(array_filter($page['data'], static fn(array $r): bool => $r['full_name'] === 'Irene'));

        self::assertCount(1, $irene);
        self::assertSame(2, $irene[0]['late_loans']);
        self::assertSame(120_000, $irene[0]['loan_balance_total']);
        self::assertSame(12, $irene[0]['max_days_overdue']);
    }

    public function test_a_narrower_window_puts_back_the_customers_contacted_before_it(): void
    {
        $h = $this->seed();

        // Window opens 13 Nov: Bosco (8 Nov), Christine (6 Nov), Denis (7 Nov),
        // Fred and Esther (reminders), and Joel (12 Nov) are no longer "recent".
        $page = $h->field->customersNotContacted(['days' => 1]);

        self::assertSame('2026-11-13', $page['since']);
        self::assertSame(10, $page['total']);
        self::assertContains('Bosco', $this->names($page));
        self::assertContains('Joel', $this->names($page));
        self::assertNotContains('Grace', $this->names($page), 'an active loan is never arrears');
        self::assertNotContains('Kato', $this->names($page), 'a deleted customer is never listed');
        self::assertNotContains('Mugisha', $this->names($page), 'a deleted loan is not an arrears loan');
    }

    public function test_the_officer_filter_counts_only_that_officers_late_loans(): void
    {
        $h = $this->seed();

        $page = $h->field->customersNotContacted(['days' => 7, 'field_officer_id' => 7]);

        self::assertSame(5, $page['total']);
        self::assertSame(['Christine', 'Aminah', 'Henry', 'Irene', 'Esther'], $this->names($page));
        self::assertNotContains('Lydia', $this->names($page), 'Lydia belongs to officer 8');
    }

    public function test_pages_are_counted_against_the_whole_list(): void
    {
        $h = $this->seed();

        $first = $h->field->customersNotContacted(['days' => 7, 'per_page' => 4, 'page' => 1]);
        $last  = $h->field->customersNotContacted(['days' => 7, 'per_page' => 4, 'page' => 2]);

        self::assertSame(6, $first['total']);
        self::assertCount(4, $first['data']);
        self::assertSame(['Esther', 'Lydia'], $this->names($last));
    }

    public function test_a_window_longer_than_a_year_is_refused(): void
    {
        $h = $this->seed();

        $this->expectException(ServiceException::class);

        $h->field->customersNotContacted(['days' => 366]);
    }
}
