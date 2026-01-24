<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FinalReport extends Model
{
    use HasUuids;

    protected $table = 'final_reports';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    const STATUS_DRAFT = 'DRAFT';
    const STATUS_SUBMITTED = 'SUBMITTED';
    const STATUS_REVISION = 'REVISION';
    const STATUS_APPROVED = 'APPROVED';

    protected $fillable = [
        'id',
        'placement_id',
        'file_url',
        'title',
        'status',
        'teacher_notes',
        'final_grade',
        'created_at',
    ];

    protected $casts = [
        'final_grade' => 'integer',
        'created_at' => 'datetime',
    ];

    public function placement()
    {
        return $this->belongsTo(Placement::class, 'placement_id', 'id');
    }
}
