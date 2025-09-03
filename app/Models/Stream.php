<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stream extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'user_id',
        'interval',
        'running_line',
        'contents',
        'ads',
        'is_muted',
        'is_active'
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contents' => 'array',
            'ads' => 'array'
        ];
    }

    protected $attributes = [
        'contents' => '{}',
        'ads' => '{}',
    ];


    public static function allowedFormats()
    {
        return [
            'image' => ['jpeg', 'png', 'jpg', 'webp'],
            'video' => ['webm', 'mp4', 'mov', 'avi']
        ];
    }

    public static function validationRules()
    {
        return [
            'name' => ['required', 'max:255'],
            'interval' => ['required',  'integer', 'min:1', 'max:300'],
            'running_line' => ['max:255'],
            'contents' => ['array'],
            'contents.*.url' => ['required', 'string'],
            'contents.*.duration' => ['required', 'string', new \App\Rules\DurationRule()],
            'ads.*.url' => ['required', 'string'],
            'ads.*.duration' => ['required', 'string', new \App\Rules\DurationRule()],
            'ads' => ['array'],
            'is_muted' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function screens()
    {
        return $this->hasMany(Screen::class);
    }
}
