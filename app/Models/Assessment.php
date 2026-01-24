<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    use HasUuids;

    protected $table = 'assessments';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'placement_id',
        'month_period',
        'technical_score',
        'discipline_score',
        'social_score',
        'managerial_score',
        'final_score',
        'notes',
        'is_locked',
        'created_at',
    ];

    protected $casts = [
        'technical_score' => 'integer',
        'discipline_score' => 'integer',
        'social_score' => 'integer',
        'managerial_score' => 'integer',
        'final_score' => 'float',
        'is_locked' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function placement()
    {
        return $this->belongsTo(Placement::class, 'placement_id', 'id');
    }

    public function calculateFinalScore()
    {
        $this->final_score = ($this->technical_score + $this->discipline_score + $this->social_score + $this->managerial_score) / 4;
        return $this->final_score;
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->calculateFinalScore();
        });
    }
}
