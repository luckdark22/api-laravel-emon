<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Leave extends Model
{
    use HasUuids;

    protected $table = 'leaves';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const TYPE_SICK = 'SICK';
    const TYPE_PERMIT = 'PERMIT';
    const TYPE_OTHER = 'OTHER';

    const STATUS_PENDING = 'PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'id',
        'placement_id',
        'type',
        'start_date',
        'end_date',
        'reason',
        'attachment_url',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function placement()
    {
        return $this->belongsTo(Placement::class, 'placement_id', 'id');
    }
}
