<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'phone',
    'role',
    'status',
    'must_change_password',
    'email_verified_at',
    'last_authenticated_at',
    'password',
])]
#[Hidden(['password', 'remember_token'])]
class VendorUser extends Authenticatable
{
    use HasUlids, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_authenticated_at' => 'datetime',
            'must_change_password' => 'boolean',
            'status' => UserStatus::class,
            'password' => 'hashed',
        ];
    }
}
