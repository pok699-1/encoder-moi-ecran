<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MediaFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'path',
        'thumb',
        'duration',
        'original_size',
        'real_size',
        'is_processed',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
