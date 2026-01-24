<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'academic_years';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'is_active',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function placements()
    {
        return $this->hasMany(Placement::class, 'academic_year_id', 'id');
    }

    public function issues()
    {
        return $this->hasMany(Issue::class, 'academic_year_id', 'id');
    }

    public static function getActive()
    {
        return self::where('is_active', true)->first();
    }
}
