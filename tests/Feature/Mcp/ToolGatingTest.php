<?php

namespace Tests\Feature\Mcp;

use App\Mcp\McpToolRegistry;
use App\Models\Role;

class ToolGatingTest extends McpTestCase
{
    public function test_ceo_sees_admin_overview_tools(): void
    {
        $ceo = $this->makeUser(Role::SLUG_CEO);
        $registry = app(McpToolRegistry::class);
        $names = array_column($registry->toolsForUser($ceo), 'name');

        $this->assertContains('admin_tasks_overview', $names);
        $this->assertContains('admin_daily_reports_overview', $names);
        $this->assertContains('tessa_request', $names);
        $this->assertContains('list_letters', $names);
    }

    public function test_hr_sees_letters_but_not_admin_tools(): void
    {
        $hr = $this->makeUser(Role::SLUG_HR);
        $registry = app(McpToolRegistry::class);
        $names = array_column($registry->toolsForUser($hr), 'name');

        $this->assertContains('list_letters', $names);
        $this->assertContains('preview_letter', $names);
        $this->assertContains('list_employees', $names);
        $this->assertContains('get_employee', $names);
        $this->assertNotContains('admin_tasks_overview', $names);
        $this->assertNotContains('tessa_request', $names);
    }

    public function test_ic_developer_sees_tasks_and_reports_but_no_admin_or_letters(): void
    {
        $dev = $this->makeUser(Role::SLUG_FULL_STACK_DEVELOPER);
        $registry = app(McpToolRegistry::class);
        $names = array_column($registry->toolsForUser($dev), 'name');

        $this->assertContains('whoami', $names);
        $this->assertContains('list_tasks', $names);
        $this->assertContains('list_my_kras', $names);
        $this->assertContains('list_daily_reports', $names);
        $this->assertNotContains('list_letters', $names);
        // list_employees is open to all staff (id/name lookup), but the rich
        // single-profile get_employee is HR/finance-only.
        $this->assertContains('list_employees', $names);
        $this->assertNotContains('get_employee', $names);
        $this->assertNotContains('admin_tasks_overview', $names);
        $this->assertNotContains('tessa_request', $names);
    }

    public function test_freelance_recruiter_sees_only_their_hiring_surface(): void
    {
        $recruiter = $this->makeUser(Role::SLUG_FREELANCE_RECRUITER);
        $registry = app(McpToolRegistry::class);
        $names = array_column($registry->toolsForUser($recruiter), 'name');

        $this->assertContains('whoami', $names);
        $this->assertContains('list_candidates', $names);
        $this->assertNotContains('list_employees', $names);
        $this->assertNotContains('get_employee', $names);
        $this->assertNotContains('list_letters', $names);
        $this->assertNotContains('admin_tasks_overview', $names);
        $this->assertNotContains('preview_letter', $names);
    }
}
