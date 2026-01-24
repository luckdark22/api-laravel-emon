<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Issue extends Model
{
    use HasUuids;

    protected $table = 'issues';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    const CATEGORY_DISCIPLINE = 'DISCIPLINE';
    const CATEGORY_TECHNICAL = 'TECHNICAL';
    const CATEGORY_SOCIAL = 'SOCIAL';
    const CATEGORY_OTHER = 'OTHER';

    const SEVERITY_LOW = 'LOW';
    const SEVERITY_MEDIUM = 'MEDIUM';
    const SEVERITY_HIGH = 'HIGH';
    const SEVERITY_CRITICAL = 'CRITICAL';

    const STATUS_OPEN = 'OPEN';
    const STATUS_RESOLVED = 'RESOLVED';

    protected $fillable = [
        'id',
        'placement_id',
        'reporter_id',
        'resolver_id',
        'academic_year_id',
        'category',
        'severity',
        'description',
        'status',
        'resolution_notes',
        'resolved_at',
        'created_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function placement()
    {
        return $this->belongsTo(Placement::class, 'placement_id', 'id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id', 'id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolver_id', 'id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'id');
    }
}
