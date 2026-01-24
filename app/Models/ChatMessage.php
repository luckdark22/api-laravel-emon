<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'chat_messages';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    const DELETED_AT = 'deletedAt';

    protected $fillable = [
        'id',
        'placementId',
        'senderId',
        'role',
        'message',
        'createdAt',
    ];

    protected $casts = [
        'createdAt' => 'datetime',
        'deletedAt' => 'datetime',
    ];

    public function placement()
    {
        return $this->belongsTo(Placement::class, 'placementId', 'id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'senderId', 'id');
    }
}
