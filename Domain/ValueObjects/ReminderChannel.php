<?php

declare(strict_types=1);

namespace Plugins\LoanField\Domain\ValueObjects;

/**
 * How the nudge was made.
 *
 * This module RECORDS that a reminder happened. It does not send one. The app
 * hands off to WhatsApp, Messages or the dialler and logs the attempt here.
 * Server-side SMS sending is deliberately not in this module: the sms.delivery
 * plugin ships only a Twilio transport today, and the Ugandan gateway is not
 * yet chosen. When it is, the sender belongs in its own gateway, and this enum
 * stays the record of what was attempted.
 */
enum ReminderChannel: string
{
    case WhatsApp = 'whatsapp';
    case Sms      = 'sms';
    case Call     = 'call';
}
