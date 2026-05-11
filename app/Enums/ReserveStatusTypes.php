<?php

namespace App\Enums;

enum ReserveStatusTypes: int
{
    // customer reserve a space but not yet arrived.
    case START = 1;
    // space owner enter the car and accept the reserve.
    case ACTIVE = 2;
    // space owner exit the car from the park and complete the reserve.
    case COMPLETE = 4;
    // customer reserve a space but not access to the park and the TTL expired.
    case EXPIRED = 5;
    // space owner reject the reserve.
    case REJECT = 6;
    // customer cancel the reserve before arriving to the park.
    case CANCEL = 7;
}
