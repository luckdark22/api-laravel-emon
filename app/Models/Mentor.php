<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Mentor extends Model
{
    use HasUuids;

    protected $table = 'mentors';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'dudi_id',
        'position',
        'address',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function dudi()
    {
        return $this->belongsTo(Dudi::class, 'dudi_id', 'id');
    }
}
