<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'description'])]
class TranslationKey extends Model
{
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }
}
