<?php

namespace App\Enums;

enum PaymentStatusTypes: string
{
    case CREATED  = 'created';
    case SUCCESS  = 'success';
    case FAILED   = 'failed';
    case CANCELED = 'canceled';
}
