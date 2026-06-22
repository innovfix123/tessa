<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAdReport extends Model
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
        'ad_set_name',
        'ad_name',
        'reach',
        'impressions',
        'frequency',
        'result_type',
        'results',
        'amount_spent',
        'cost_per_result',
        'cpc',
        'cpm',
        'ctr',
        'app_installs',
        'cost_per_install',
        'new_user_first_purchase',
        'cost_per_first_purchase',
        'reporting_starts',
        'reporting_ends',
        'uploaded_by',
        'source_file',
        'row_hash',
    ];

    protected $casts = [
        'reach' => 'integer',
        'impressions' => 'integer',
        'frequency' => 'decimal:8',
        'results' => 'integer',
        'amount_spent' => 'decimal:2',
        'cost_per_result' => 'decimal:2',
        'cpc' => 'decimal:2',
        'cpm' => 'decimal:2',
        'ctr' => 'decimal:8',
        'app_installs' => 'integer',
        'cost_per_install' => 'decimal:2',
        'new_user_first_purchase' => 'integer',
        'cost_per_first_purchase' => 'decimal:2',
        'reporting_starts' => 'date',
        'reporting_ends' => 'date',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
