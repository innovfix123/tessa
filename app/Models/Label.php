<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Label extends Model
{
    protected $fillable = [
        'name',
        'color',
    ];

    public function stories(): MorphToMany
    {
        return $this->morphedByMany(Story::class, 'labelable', 'agile_labelables');
    }

    public function bugs(): MorphToMany
    {
        return $this->morphedByMany(Bug::class, 'labelable', 'agile_labelables');
    }

    public function epics(): MorphToMany
    {
        return $this->morphedByMany(Epic::class, 'labelable', 'agile_labelables');
    }
}
