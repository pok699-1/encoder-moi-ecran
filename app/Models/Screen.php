<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Screen extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'stream_id',
        'name',
        'code',
        'token'
    ];

    public static function validationRules()
    {
        return [
            'name' => ['required', 'max:255', 'min:1'],
            'stream_id' => ['nullable', 'exists:streams,id']
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }

    public function scopeGetMaxScreenNumber($query)
    {
        return $query->where('name', 'like', 'Экран %')
            ->get()
            ->map(function ($screen) {
                if (preg_match('/Экран (\d+)/u', $screen->name, $matches)) {
                    return (int)$matches[1];
                }
                return 0;
            })
            ->max() ?? 0;
    }
}
