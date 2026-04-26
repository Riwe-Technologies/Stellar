<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OracleEvent extends Model
{
    use HasFactory;

    protected $fillable = ['index_config_id', 'polygon_id', 'observed_at', 'payload', 'triggered', 'severity', 'evidence'];

    protected $casts = [
        'payload' => 'array',
        'evidence' => 'array',
        'observed_at' => 'datetime',
        'triggered' => 'boolean',
    ];

    public function indexConfig()
    {
        return $this->belongsTo(IndexConfig::class);
    }

    public function polygon()
    {
        return $this->belongsTo(Polygon::class);
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class);
    }
}
