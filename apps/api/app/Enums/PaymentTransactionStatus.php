<?php

namespace App\Enums;

enum PaymentTransactionStatus: string
{
    case PENDING = 'PENDING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case REJECTED = 'REJECTED';
}
