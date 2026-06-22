<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Bill or Reimbursement request raised by an employee, settled by an admin
 * (Ayush #4 / Shoyab #32). See config/bills_access.php and BillService.
 */
class Bill extends Model
{
    protected $fillable = [
        'user_id', 'type', 'title', 'description', 'category',
        'amount', 'currency', 'vendor_name',
        'file_path', 'file_name', 'file_size', 'files', 'sheet_url',
        'status', 'reviewed_by', 'reviewed_at',
        'transaction_id', 'proof_path', 'proof_name', 'payment_note',
        'rejection_reason', 'paid_announced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'file_size' => 'integer',
        'files' => 'array',
        'reviewed_at' => 'datetime',
        'paid_announced_at' => 'datetime',
    ];

    /**
     * All attachments as [{path, name, size}, ...]. Falls back to the legacy
     * single file_path column for rows created before multi-file support.
     */
    public function attachments(): array
    {
        if (is_array($this->files) && $this->files) {
            return $this->files;
        }
        if ($this->file_path) {
            return [['path' => $this->file_path, 'name' => $this->file_name, 'size' => $this->file_size]];
        }

        return [];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
