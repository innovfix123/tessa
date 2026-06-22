<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ComputeSalaryTool extends Tool
{
    public function name(): string { return 'compute_salary'; }
    public function description(): string
    {
        return 'Compute a CTC ⇄ salary-breakup using the company salary rules (basic, HRA, PF, ESI, etc.).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string', 'enum' => ['ctc', 'basic'], 'description' => 'ctc = derive breakup from total CTC; basic = derive from basic.'],
                'amount' => ['type' => 'number'],
                'period' => ['type' => 'string', 'enum' => ['annual', 'monthly']],
                'employee_category' => ['type' => 'string', 'enum' => ['fulltime', 'intern', 'freelancer']],
            ],
            'required' => ['mode', 'amount', 'employee_category'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/salary-tool', $args, $user);
    }
}
