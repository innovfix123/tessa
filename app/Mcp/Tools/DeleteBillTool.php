<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteBillTool extends Tool
{
    public function name(): string { return 'delete_bill'; }
    public function description(): string
    {
        return 'Cancel/delete one of your own still-pending bills or reimbursements by id. Get the id from list_my_bills.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['bill_id' => ['type' => 'integer']],
            'required' => ['bill_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/bills/{$args['bill_id']}", [], $user);
    }
}
