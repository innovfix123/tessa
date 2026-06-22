<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetInvoiceReconciliationTool extends Tool
{
    public function name(): string { return 'get_invoice_reconciliation'; }
    public function description(): string
    {
        return 'Get the supplier-invoice reconciliation view (invoices matched against bank statement transactions).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/invoice-reconciliation', [], $user);
    }
}
