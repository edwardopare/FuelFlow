<?php

namespace App\Enums;

enum UserStatus: string
{
    case PendingFirstLogin = 'pending_first_login';
    case Active = 'active';
    case Inactive = 'inactive';
    case Locked = 'locked';
}
