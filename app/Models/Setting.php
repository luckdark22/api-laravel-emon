<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $casts = [
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public static function getValue($key, $default = null)
    {
        $setting = self::find($key);
        return $setting ? $setting->value : $default;
    }

    public static function setValue($key, $value, $description = null)
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'description' => $description]
        );
    }
}
