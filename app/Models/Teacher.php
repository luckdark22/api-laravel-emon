<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'teachers';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'nip',
        'address',
        'specialty',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function placements()
    {
        return $this->hasMany(Placement::class, 'teacher_id', 'user_id');
    }

    public function visits()
    {
        return $this->hasMany(Visit::class, 'teacher_id', 'user_id');
    }
}
