<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'key', 'value', 'updated_by'])]
class SystemSetting extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
