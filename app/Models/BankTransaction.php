<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BankTransaction extends Model
{
    const MATCH_UNMATCHED = 'unmatched';
    const MATCH_MATCHED = 'matched';
    const MATCH_IGNORED = 'ignored';

    protected $fillable = [
        'uploaded_by',
        'transaction_date',
        'description',
        'reference_number',
        'amount',
        'type',
        'balance',
        'bank_name',
        'statement_month',
        'source_file',
        'match_status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function invoiceSubmission(): HasOne
    {
        return $this->hasOne(InvoiceSubmission::class, 'matched_transaction_id');
    }
}
