<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuids, SoftDeletes;

    protected $table = 'users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const ROLE_ADMIN = 'ADMIN';
    const ROLE_TEACHER = 'TEACHER';
    const ROLE_MENTOR = 'MENTOR';
    const ROLE_STUDENT = 'STUDENT';

    protected $fillable = [
        'id',
        'name',
        'email',
        'password_hash',
        'role',
        'phone',
        'avatar_url',
        'is_default_password',
        'fcm_token',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        'is_default_password' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';
    const DELETED_AT = 'deleted_at';

    public function student()
    {
        return $this->hasOne(Student::class, 'user_id', 'id');
    }

    public function teacher()
    {
        return $this->hasOne(Teacher::class, 'user_id', 'id');
    }

    public function mentor()
    {
        return $this->hasOne(Mentor::class, 'user_id', 'id');
    }

    public function getAuthPassword()
    {
        return $this->password_hash;
    }
}
