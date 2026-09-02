<?php

namespace App\Enums;

/**
 * Outcome of a single webhook delivery, returned by PaymentWebhookService.
 */
enum PaymentWebhookResult: string
{
    case PROCESSED = 'PROCESSED';
    case ALREADY_HANDLED = 'ALREADY_HANDLED';
    case REJECTED = 'REJECTED';
    case FAILED = 'FAILED';
}
