<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Visit extends Model
{
    use HasUuids;

    protected $table = 'visits';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'teacher_id',
        'dudi_id',
        'visit_date',
        'photo_evidence_url',
        'notes',
        'latitude',
        'longitude',
        'location_address',
        'created_at',
    ];

    protected $casts = [
        'visit_date' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'created_at' => 'datetime',
    ];

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }

    public function dudi()
    {
        return $this->belongsTo(Dudi::class, 'dudi_id', 'id');
    }
}
