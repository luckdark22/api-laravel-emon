<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlacementMutation extends Model
{
    use HasUuids;

    protected $table = 'placement_mutations';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    const STATUS_PENDING = 'PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'id',
        'student_id',
        'old_dudi_id',
        'new_dudi_id',
        'reason',
        'status',
        'rejection_reason',
        'requested_by',
        'processed_by',
        'approved_at',
        'academic_year_id',
        'new_teacher_id',
        'created_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'user_id');
    }

    public function oldDudi()
    {
        return $this->belongsTo(Dudi::class, 'old_dudi_id', 'id');
    }

    public function newDudi()
    {
        return $this->belongsTo(Dudi::class, 'new_dudi_id', 'id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by', 'id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by', 'id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'id');
    }
}
