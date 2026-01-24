<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    use HasUuids;

    protected $table = 'journals';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    const STATUS_PENDING = 'PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'id',
        'placement_id',
        'date',
        'activity',
        'description',
        'attachment_url',
        'status',
        'mentor_feedback',
        'checked_by',
        'created_at',
    ];

    protected $casts = [
        'date' => 'date',
        'created_at' => 'datetime',
    ];

    public function placement()
    {
        return $this->belongsTo(Placement::class, 'placement_id', 'id');
    }

    public function checkedByMentor()
    {
        return $this->belongsTo(Mentor::class, 'checked_by', 'user_id');
    }
}
