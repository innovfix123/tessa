<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSubmission extends Model
{
    const VERIFICATION_PENDING = 'pending';
    const VERIFICATION_VERIFIED = 'verified';
    const VERIFICATION_MISMATCH = 'mismatch';
    const VERIFICATION_NO_MATCH = 'no_match';

    const STATUS_PENDING = 'pending';
    const STATUS_REVIEWED = 'reviewed';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REVIEWED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    const CATEGORIES = [
        'general',
        'travel',
        'software',
        'hardware',
        'office_supplies',
        'marketing',
        'consulting',
        'other',
    ];

    protected $fillable = [
        'user_id',
        'vendor_name',
        'service',
        'amount',
        'currency',
        'invoice_date',
        'category',
        'file_path',
        'file_name',
        'notes',
        'status',
        'reviewed_by',
        'review_notes',
        'reviewed_at',
        'matched_transaction_id',
        'ai_extracted_vendor',
        'ai_extracted_amount',
        'ai_extracted_date',
        'match_confidence',
        'verification_status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoice_date' => 'date',
        'reviewed_at' => 'datetime',
        'ai_extracted_amount' => 'decimal:2',
        'ai_extracted_date' => 'date',
        'match_confidence' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'matched_transaction_id');
    }
}
