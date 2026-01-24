<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'students';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'nis',
        'class_name',
        'major',
        'address',
        'bio',
        'academic_year',
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
        return $this->hasMany(Placement::class, 'student_id', 'user_id');
    }

    public function activePlacement()
    {
        return $this->hasOne(Placement::class, 'student_id', 'user_id')
            ->where('status', 'ACTIVE')
            ->latest();
    }
}
