<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanField;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\LoanField\Application\Services\FieldService;
use Tests\Unit\Plugins\LoanField\Support\FieldHarness;

#[CoversClass(FieldService::class)]
final class FieldServiceTest extends TestCase
{
    private ?FieldHarness $harness = null;

    protected function tearDown(): void
    {
        // Positively identified: the one file this test created.
        $this->harness?->destroy();
        $this->harness = null;
    }

    private function harness(): FieldHarness
    {
        return $this->harness ??= new FieldHarness();
    }

    private function seededHarness(): FieldHarness
    {
        $h = $this->harness();
        $h->customer(1, 'Aminah Nakato');
        $h->customer(2, 'Bosco Okello');
        $h->loan(10, 1);

        return $h;
    }

    public function test_recording_a_visit_takes_a_rev_and_is_stamped_by_the_server(): void
    {
        $h = $this->seededHarness();

        $visit = $h->field->recordVisit([
            'customer_id' => 1,
            'loan_id'     => 10,
            'visit_type'  => 'payment_collection',
            'outcome'     => 'paid',
            'visited_on'  => '2026-11-13',
        ]);

        self::assertSame(1, $visit['rev'], 'the first write in a tenant takes rev 1');
        self::assertSame(
            FieldHarness::STAFF_ID,
            $visit['recorded_by'],
            'recorded_by is the STAFF id; the user id is a different table',
        );

        $row = $h->row('SELECT rev, device_id, recorded_by FROM visits WHERE id = :id', ['id' => $visit['id']]);

        self::assertSame(1, (int) $row['rev'], 'the rev is stored, not just returned');
        self::assertSame('server', $row['device_id']);
        self::assertSame(1, (int) $h->row('SELECT current_rev FROM sync_revision WHERE id = 1')['current_rev']);
    }

    public function test_each_write_takes_the_next_rev_from_the_shared_counter(): void
    {
        $h = $this->seededHarness();

        $a = $h->field->recordVisit(['customer_id' => 1, 'visit_type' => 'new_client', 'visited_on' => '2026-11-12']);
        $b = $h->field->recordReminder(['customer_id' => 1, 'channel' => 'whatsapp', 'amount_quoted' => 150000]);
        $c = $h->field->recordVisit(['customer_id' => 2, 'visit_type' => 'other', 'visited_on' => '2026-11-12']);

        self::assertSame([1, 2, 3], [$a['rev'], $b['rev'], $c['rev']]);
        self::assertSame(3, (int) $h->row('SELECT current_rev FROM sync_revision WHERE id = 1')['current_rev']);
    }

    public function test_a_visit_with_no_gps_fix_is_accepted_and_stores_null(): void
    {
        $h = $this->seededHarness();

        $visit = $h->field->recordVisit([
            'customer_id' => 1,
            'visit_type'  => 'arrears_follow_up',
            'outcome'     => 'absent',
            'visited_on'  => '2026-11-13',
            'latitude'    => null,
            'longitude'   => null,
        ]);

        self::assertNull($visit['latitude']);
        self::assertNull($visit['longitude']);
    }

    public function test_a_valid_fix_is_stored_as_given(): void
    {
        $h = $this->seededHarness();

        $visit = $h->field->recordVisit([
            'customer_id' => 1,
            'visit_type'  => 'business_check',
            'visited_on'  => '2026-11-13',
            'latitude'    => '0.3476',
            'longitude'   => 32.5826,
        ]);

        self::assertEqualsWithDelta(0.3476, $visit['latitude'], 1e-7);
        self::assertEqualsWithDelta(32.5826, $visit['longitude'], 1e-7);
    }

    public function test_a_swapped_or_out_of_range_coordinate_is_refused_and_takes_no_rev(): void
    {
        $h = $this->seededHarness();

        try {
            $h->field->recordVisit([
                'customer_id' => 1,
                'visit_type'  => 'payment_collection',
                'visited_on'  => '2026-11-13',
                'latitude'    => 132.6,      // longitude pasted into the latitude slot
                'longitude'   => 0.3,
            ]);
            self::fail('an out-of-range latitude must be refused');
        } catch (ServiceException $e) {
            self::assertStringContainsString('Latitude', $e->getMessage());
        }

        self::assertSame(0, (int) $h->row('SELECT COUNT(*) AS n FROM visits')['n'], 'nothing is written');
        self::assertSame(0, (int) ($h->row('SELECT current_rev FROM sync_revision WHERE id = 1')['current_rev'] ?? 0),
            'a refused write must not consume a rev');
    }

    public function test_a_half_fix_is_refused(): void
    {
        $h = $this->seededHarness();

        $this->expectException(ServiceException::class);

        $h->field->recordVisit([
            'customer_id' => 1,
            'visit_type'  => 'payment_collection',
            'visited_on'  => '2026-11-13',
            'latitude'    => 0.3476,
        ]);
    }

    public function test_an_unknown_outcome_is_refused(): void
    {
        $h = $this->seededHarness();

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessageMatches('/outcome must be an outcome, one of/');

        $h->field->recordVisit([
            'customer_id' => 1,
            'visit_type'  => 'payment_collection',
            'outcome'     => 'happy',
            'visited_on'  => '2026-11-13',
        ]);
    }

    public function test_an_unknown_visit_type_is_refused(): void
    {
        $h = $this->seededHarness();

        $this->expectException(ServiceException::class);

        $h->field->recordVisit(['customer_id' => 1, 'visit_type' => 'collections', 'visited_on' => '2026-11-13']);
    }

    public function test_an_unknown_reminder_channel_is_refused(): void
    {
        $h = $this->seededHarness();

        $this->expectException(ServiceException::class);

        $h->field->recordReminder(['customer_id' => 1, 'channel' => 'telegram', 'amount_quoted' => 1000]);
    }

    public function test_a_visit_in_the_future_is_refused(): void
    {
        $h = $this->seededHarness();

        $this->expectException(ServiceException::class);

        $h->field->recordVisit(['customer_id' => 1, 'visit_type' => 'other', 'visited_on' => '2026-12-01']);
    }

    public function test_amount_quoted_must_be_whole_shillings(): void
    {
        $h = $this->seededHarness();

        foreach ([1000.5, '1000.5', -200, '1,000'] as $bad) {
            try {
                $h->field->recordReminder(['customer_id' => 1, 'channel' => 'call', 'amount_quoted' => $bad]);
                self::fail('refused: ' . var_export($bad, true));
            } catch (ServiceException) {
                // expected
            }
        }

        self::assertSame(0, (int) $h->row('SELECT COUNT(*) AS n FROM reminders')['n']);
    }

    public function test_the_quoted_amount_is_stored_as_quoted_even_after_the_balance_moves(): void
    {
        $h = $this->seededHarness();

        $sent = $h->field->recordReminder([
            'customer_id'   => 1,
            'loan_id'       => 10,
            'channel'       => 'whatsapp',
            'amount_quoted' => '150000',          // a numeric string is accepted as whole shillings
            'message'       => 'Your instalment of 150,000 is due.',
        ]);

        // The customer pays part of it, and the loan's balance moves on.
        $h->db->execute("UPDATE loans SET loan_balance = 40000 WHERE id = 10");

        $row = $h->row('SELECT amount_quoted FROM reminders WHERE id = :id', ['id' => $sent['id']]);

        self::assertSame(150000, (int) $row['amount_quoted'], 'a reminder records what was SAID');
        self::assertSame(150000, $sent['amount_quoted']);
    }

    public function test_a_reminder_for_a_loan_of_another_customer_is_refused(): void
    {
        $h = $this->seededHarness();

        $this->expectException(ServiceException::class);

        $h->field->recordReminder(['customer_id' => 2, 'loan_id' => 10, 'channel' => 'sms']);
    }

    public function test_a_visit_for_a_customer_that_does_not_exist_is_refused(): void
    {
        $h = $this->seededHarness();

        $this->expectException(ServiceException::class);

        $h->field->recordVisit(['customer_id' => 999, 'visit_type' => 'new_client', 'visited_on' => '2026-11-13']);
    }

    public function test_listing_visits_filters_by_customer_outcome_officer_and_date_range(): void
    {
        $h = $this->seededHarness();

        $h->field->recordVisit(['customer_id' => 1, 'visit_type' => 'payment_collection', 'outcome' => 'paid', 'visited_on' => '2026-11-02']);
        $h->field->recordVisit(['customer_id' => 1, 'visit_type' => 'payment_collection', 'outcome' => 'absent', 'visited_on' => '2026-11-09']);
        $h->field->recordVisit(['customer_id' => 2, 'visit_type' => 'payment_collection', 'outcome' => 'paid', 'visited_on' => '2026-11-10']);
        $h->rawVisit(9001, 2, '2026-11-11', 99);

        $byCustomer = $h->field->listVisits(['customer_id' => 1]);
        self::assertSame(2, $byCustomer['total']);

        $byOutcome = $h->field->listVisits(['outcome' => 'paid']);
        self::assertSame(2, $byOutcome['total']);

        $byOfficer = $h->field->listVisits(['recorded_by' => 99]);
        self::assertSame(1, $byOfficer['total']);

        $byDates = $h->field->listVisits(['from' => '2026-11-05', 'to' => '2026-11-10']);
        self::assertSame(['2026-11-10', '2026-11-09'], array_column($byDates['data'], 'visited_on'));

        $unknown = $h->field->listVisits(['outcome' => 'paid', 'customer_id' => 2]);
        self::assertSame(1, $unknown['total']);
    }

    public function test_a_soft_deleted_visit_is_not_shown(): void
    {
        $h = $this->seededHarness();

        $visit = $h->field->recordVisit(['customer_id' => 1, 'visit_type' => 'other', 'visited_on' => '2026-11-13']);
        $h->softDeleteVisit($visit['id'], '2026-11-14 08:00:00');

        $this->expectException(ServiceException::class);

        $h->field->showVisit($visit['id']);
    }

    public function test_a_guest_cannot_record_field_work(): void
    {
        $guest = new FieldHarness('');

        try {
            $guest->customer(1, 'Anon');
            $this->expectException(ServiceException::class);
            $guest->field->recordVisit(['customer_id' => 1, 'visit_type' => 'other', 'visited_on' => '2026-11-13']);
        } finally {
            $guest->destroy();
        }
    }

    public function test_listing_reminders_by_date_uses_the_business_day(): void
    {
        $h = $this->seededHarness();

        // 23:30 on 6 Nov in Kampala is 20:30 UTC on 6 Nov: the 6th, not the 7th.
        $h->field->recordReminder(['customer_id' => 1, 'channel' => 'call', 'sent_at' => '2026-11-06 23:30:00']);
        $h->field->recordReminder(['customer_id' => 1, 'channel' => 'sms', 'sent_at' => '2026-11-07 00:30:00']);

        $sixth = $h->field->listReminders(['from' => '2026-11-06', 'to' => '2026-11-06']);

        self::assertSame(1, $sixth['total']);
        self::assertSame('call', $sixth['data'][0]['channel']);
    }
}
