<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Connection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'use_ssh' => 'boolean',
            'port' => 'integer',
            'ssh_port' => 'integer',
        ];
    }

    protected function password(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function sshPassword(): Attribute
    {
        return $this->encryptedAttribute();
    }

    private function encryptedAttribute(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : Crypt::decryptString($value),
            set: fn (?string $value) => $value === null ? null : Crypt::encryptString($value),
        );
    }

    public function queryHistory(): HasMany
    {
        return $this->hasMany(QueryHistory::class);
    }
}
