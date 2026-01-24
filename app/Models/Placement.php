<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Placement extends Model
{
    use HasUuids;

    protected $table = 'placements';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_FINISHED = 'FINISHED';
    const STATUS_MOVED = 'MOVED';
    const STATUS_DROPPED = 'DROPPED';

    protected $fillable = [
        'id',
        'student_id',
        'teacher_id',
        'dudi_id',
        'academic_year_id',
        'status',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'user_id');
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }

    public function dudi()
    {
        return $this->belongsTo(Dudi::class, 'dudi_id', 'id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'placement_id', 'id');
    }

    public function journals()
    {
        return $this->hasMany(Journal::class, 'placement_id', 'id');
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class, 'placement_id', 'id');
    }

    public function assessments()
    {
        return $this->hasMany(Assessment::class, 'placement_id', 'id');
    }

    public function reports()
    {
        return $this->hasMany(FinalReport::class, 'placement_id', 'id');
    }

    public function issues()
    {
        return $this->hasMany(Issue::class, 'placement_id', 'id');
    }
}
