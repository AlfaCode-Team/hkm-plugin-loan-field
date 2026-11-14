<?php

declare(strict_types=1);

namespace Plugins\LoanField\Domain\ValueObjects;

/**
 * Why the officer went. A closed set so that "collections" and "collection" do
 * not become two rows in a report. The handset must send one of these values;
 * the server refuses anything else.
 */
enum VisitType: string
{
    case PaymentCollection = 'payment_collection';
    case NewClient         = 'new_client';
    case ArrearsFollowUp   = 'arrears_follow_up';
    case BusinessCheck     = 'business_check';
    case Other             = 'other';
}
