<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleAdReport extends Model
{
    const PROJECT_HIMA = 'hima';
    const PROJECT_SUDAR = 'sudar';
    const PROJECT_THEDAL = 'thedal';

    const PROJECTS = [
        self::PROJECT_HIMA => 'Hima',
        self::PROJECT_SUDAR => 'Sudar',
        self::PROJECT_THEDAL => 'Thedal',
    ];

    protected $fillable = [
        'project',
        'campaign_name',
        'currency_code',
        'cost',
        'avg_cpc',
        'ctr',
        'cpi',
        'cpr',
        'cpftd',
        'cp_d1mp',
        'purchases',
        'cpp',
        'purchase_value',
        'reporting_date',
        'uploaded_by',
        'source_file',
        'row_hash',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'avg_cpc' => 'decimal:2',
        'ctr' => 'decimal:8',
        'cpi' => 'decimal:2',
        'cpr' => 'decimal:2',
        'cpftd' => 'decimal:2',
        'cp_d1mp' => 'decimal:2',
        'purchases' => 'integer',
        'cpp' => 'decimal:2',
        'purchase_value' => 'decimal:2',
        'reporting_date' => 'date',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
