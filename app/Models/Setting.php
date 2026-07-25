<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);

        return $setting === null ? $default : json_decode($setting->value, true);
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }
}
