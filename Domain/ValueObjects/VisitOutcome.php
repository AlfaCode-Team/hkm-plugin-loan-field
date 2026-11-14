<?php

declare(strict_types=1);

namespace Plugins\LoanField\Domain\ValueObjects;

/**
 * What happened when the officer arrived. Closed for the same reason as
 * VisitType: outcome is the column every arrears report is grouped by.
 */
enum VisitOutcome: string
{
    case Paid           = 'paid';
    case PartPaid       = 'part_paid';
    case PromisedToPay  = 'promised_to_pay';
    case Absent         = 'absent';
    case Refused        = 'refused';
    case Relocated      = 'relocated';
    case BusinessClosed = 'business_closed';
    case Other          = 'other';
}
