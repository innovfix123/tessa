<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgendaTemplateItem extends Model
{
    protected $fillable = ['template_id', 'section_title', 'point_question', 'sort_order'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(AgendaTemplate::class, 'template_id');
    }
}
