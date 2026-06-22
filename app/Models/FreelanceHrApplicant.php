<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreelanceHrApplicant extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'experience_summary',
        'resume_file',
        'status',
        'charge',
        'notes',
    ];

    protected $casts = [
        'status' => 'string',
    ];
}
