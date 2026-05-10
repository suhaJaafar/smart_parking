<?php

namespace App\Enums;

enum RoleTypes: int
{
    case SUPER_ADMIN = 1;
    case ADMIN = 2;
    case SPACE_OWNER = 3;
    case CUSTOMER = 4;
    case USER = 5;
}
