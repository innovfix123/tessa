<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class AddCandidateToTeamTool extends Tool
{
    public function name(): string { return 'add_candidate_to_team'; }
    public function description(): string
    {
        return 'Provision an accepted candidate into the team in one step (creates their Tessa account with the given role/manager).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidate_id' => ['type' => 'integer'],
                'role_id' => ['type' => 'integer'],
                'employment_type' => ['type' => 'string', 'enum' => ['full_time', 'internship', 'freelancer']],
                'reporting_manager_id' => ['type' => 'integer'],
                'joining_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'designation' => ['type' => 'string'],
            ],
            'required' => ['candidate_id', 'role_id', 'employment_type'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['candidate_id'];
        unset($args['candidate_id']);
        return ApiSubRequest::post("/hiring/candidates/{$id}/add-to-team", $args, $user);
    }
}
