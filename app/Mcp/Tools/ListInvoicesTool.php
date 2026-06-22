<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListInvoicesTool extends Tool
{
    public function name(): string { return 'list_invoices'; }

    public function description(): string
    {
        return 'List and search supplier/vendor invoice submissions — each with vendor, amount, '
            . 'currency, invoice date, uploader, invoice number, notes and a link to the file. '
            . 'Reviewers (finance + invoice-reconciliation assistants) see every employee\'s '
            . 'invoices; everyone else sees only their own. There is no separate "get one" call — '
            . 'use search/filters to view a specific invoice (search also matches the uploader name).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search'      => ['type' => 'string',  'description' => 'Free-text match on vendor, service, invoice number, notes, or uploader name.'],
                'uploaded_by' => ['type' => 'integer', 'description' => 'Reviewers only: restrict to invoices uploaded by this user id.'],
                'vendor'      => ['type' => 'string',  'description' => 'Exact vendor name to filter by.'],
                'from'        => ['type' => 'string',  'description' => 'Earliest invoice date, YYYY-MM-DD.'],
                'to'          => ['type' => 'string',  'description' => 'Latest invoice date, YYYY-MM-DD.'],
                'sort_by'     => ['type' => 'string',  'enum' => ['created_at', 'invoice_date', 'amount', 'vendor_name'], 'description' => 'Sort column (default created_at).'],
                'sort_dir'    => ['type' => 'string',  'enum' => ['asc', 'desc'], 'description' => 'Sort direction (default desc).'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    // Gated by the 'invoices' feature (FEATURE_MAP) — finance roles via the
    // invoices permission + Bhuvan #59 via the extra-user grant in
    // UserFeatureService. InvoiceSubmissionController::index() re-applies
    // isReviewer() scoping, so this stays read-only and correctly scoped.
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/invoice-submissions', $args, $user);
    }
}
