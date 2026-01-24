<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasUuids;

    protected $table = 'holidays';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'date',
        'description',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public static function isHoliday($date)
    {
        return self::whereDate('date', $date)->exists();
    }
}
