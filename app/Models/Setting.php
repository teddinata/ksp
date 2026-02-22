<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'payload',
        'type',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}