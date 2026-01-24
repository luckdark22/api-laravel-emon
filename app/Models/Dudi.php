<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dudi extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'dudis';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'name',
        'logo',
        'address',
        'contact',
        'latitude',
        'longitude',
        'radius_meters',
        'start_date',
        'end_date',
        'work_start_time',
        'work_end_time',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meters' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'deleted_at' => 'datetime',
    ];

    public function mentors()
    {
        return $this->hasMany(Mentor::class, 'dudi_id', 'id');
    }

    public function placements()
    {
        return $this->hasMany(Placement::class, 'dudi_id', 'id');
    }
}
