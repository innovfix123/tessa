<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListMyBillsTool extends Tool
{
    public function name(): string { return 'list_my_bills'; }
    public function description(): string
    {
        return 'List the signed-in user\'s submitted bills / reimbursements / travel claims (most recent first), each with its status (pending / paid / rejected).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    // Gated by the 'bills' feature (FEATURE_MAP) — bills_open_to_all + the
    // per-user allowlists in config/bills_access.php. BillController re-checks.
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/bills', $args, $user);
    }
}
