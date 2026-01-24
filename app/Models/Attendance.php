<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasUuids;

    protected $table = 'attendances';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    const STATUS_ON_TIME = 'ON_TIME';
    const STATUS_LATE = 'LATE';
    const STATUS_ABSENT = 'ABSENT';
    const STATUS_PERMIT = 'PERMIT';
    const STATUS_SICK = 'SICK';

    protected $fillable = [
        'id',
        'placement_id',
        'date',
        'clock_in',
        'clock_out',
        'clock_in_photo',
        'clock_out_photo',
        'latitude',
        'longitude',
        'location_address',
        'status',
        'created_at',
    ];

    protected $casts = [
        'date' => 'date',
        'latitude' => 'float',
        'longitude' => 'float',
        'created_at' => 'datetime',
    ];

    public function placement()
    {
        return $this->belongsTo(Placement::class, 'placement_id', 'id');
    }
}
