<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::all()->keyBy('slug');
        if ($roles->isEmpty()) {
            return;
        }

        $assignments = [
            'kpi.edit_entry' => [Role::SLUG_CMO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_QA_ANALYST, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'kpi.set_target' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_TECH_LEAD],
            'kpi.save_ceo_note' => [Role::SLUG_CEO],
            'kpi.manage_definitions' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_TECH_LEAD],
            'escalation.view' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_OPS, Role::SLUG_TECHNICAL_SUPPORT],
            'escalation.create' => [Role::SLUG_OPS, Role::SLUG_TECHNICAL_SUPPORT],
            'escalation.manage' => [Role::SLUG_CEO, Role::SLUG_COO],
            'escalation.escalate_to_ceo' => [Role::SLUG_COO],
            'daily_report.edit' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_TECH_LEAD, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_QA_ANALYST, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'template.manage' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_CFO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_ACCOUNTANT, Role::SLUG_TECH_LEAD, Role::SLUG_QA_ANALYST, Role::SLUG_FULL_STACK_DEVELOPER, Role::SLUG_GEN_AI_DEVELOPER, Role::SLUG_LEAD_AI_ENGINEER, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'meeting.access' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_CFO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_ACCOUNTANT, Role::SLUG_TECH_LEAD, Role::SLUG_QA_ANALYST, Role::SLUG_FULL_STACK_DEVELOPER, Role::SLUG_GEN_AI_DEVELOPER, Role::SLUG_LEAD_AI_ENGINEER, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'org.view' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_CFO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_ACCOUNTANT, Role::SLUG_TECH_LEAD, Role::SLUG_QA_ANALYST, Role::SLUG_FULL_STACK_DEVELOPER, Role::SLUG_GEN_AI_DEVELOPER, Role::SLUG_LEAD_AI_ENGINEER, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'feature.meetings' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_CFO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_ACCOUNTANT, Role::SLUG_TECH_LEAD, Role::SLUG_QA_ANALYST, Role::SLUG_FULL_STACK_DEVELOPER, Role::SLUG_GEN_AI_DEVELOPER, Role::SLUG_LEAD_AI_ENGINEER, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'feature.dashboard' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_CFO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_ACCOUNTANT, Role::SLUG_TECH_LEAD, Role::SLUG_QA_ANALYST, Role::SLUG_FULL_STACK_DEVELOPER, Role::SLUG_GEN_AI_DEVELOPER, Role::SLUG_LEAD_AI_ENGINEER, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'feature.calendar' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_TECH_LEAD, Role::SLUG_QA_ANALYST, Role::SLUG_FULL_STACK_DEVELOPER, Role::SLUG_GEN_AI_DEVELOPER, Role::SLUG_LEAD_AI_ENGINEER, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'feature.daily_reports' => [Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_TECH_LEAD, Role::SLUG_QA_ANALYST, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'feature.kpi' => [Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_TECH_LEAD, Role::SLUG_QA_ANALYST, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'feature.mkpi' => [Role::SLUG_CEO],
            'feature.escalations' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_TECHNICAL_SUPPORT],
            'feature.org' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_CFO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_ACCOUNTANT, Role::SLUG_TECH_LEAD, Role::SLUG_QA_ANALYST, Role::SLUG_FULL_STACK_DEVELOPER, Role::SLUG_GEN_AI_DEVELOPER, Role::SLUG_LEAD_AI_ENGINEER, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'feature.templates' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_CFO, Role::SLUG_OPS, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_SOCIAL_MEDIA, Role::SLUG_HR, Role::SLUG_PRODUCT_MANAGER, Role::SLUG_TECHNICAL_SUPPORT, Role::SLUG_ACCOUNTANT, Role::SLUG_TECH_LEAD, Role::SLUG_QA_ANALYST, Role::SLUG_FULL_STACK_DEVELOPER, Role::SLUG_GEN_AI_DEVELOPER, Role::SLUG_LEAD_AI_ENGINEER, Role::SLUG_DATA_ANALYST, Role::SLUG_BUSINESS_ANALYST],
            'feature.hr_resumes' => [Role::SLUG_CEO, Role::SLUG_CFO],
            'feature.scripts' => [Role::SLUG_CEO, Role::SLUG_CMO, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_SOCIAL_MEDIA],
            'scripts.generate' => [Role::SLUG_CMO, Role::SLUG_CONTENT_LEAD, Role::SLUG_CONTENT_CREATOR, Role::SLUG_VIDEO_EDITOR, Role::SLUG_GRAPHIC_DESIGNER, Role::SLUG_SOCIAL_MEDIA],
            'feature.revenue' => [Role::SLUG_CEO, Role::SLUG_CFO, Role::SLUG_CMO, Role::SLUG_COO],
            'feature.invoices' => [Role::SLUG_CEO, Role::SLUG_CFO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_TECH_LEAD, Role::SLUG_ACCOUNTANT],
            'feature.meta_ads' => [Role::SLUG_CEO, Role::SLUG_CMO, Role::SLUG_CFO, Role::SLUG_COO, Role::SLUG_TECH_LEAD, Role::SLUG_MARKETING, Role::SLUG_GROWTH_MANAGER],
            'feature.mission' => [Role::SLUG_CEO, Role::SLUG_CMO, Role::SLUG_COO, Role::SLUG_CFO],
            'feature.employees' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CFO, Role::SLUG_HR, Role::SLUG_BUSINESS_ANALYST],
            'feature.hr_dashboard' => [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CFO, Role::SLUG_HR, Role::SLUG_BUSINESS_ANALYST],
            'feature.team_status' => [Role::SLUG_CEO],
            'invoice.review' => [Role::SLUG_ACCOUNTANT, Role::SLUG_CFO, Role::SLUG_CEO],
            'admin.access' => [Role::SLUG_ADMIN],
        ];

        $permissionCount = 0;
        foreach ($assignments as $permission => $roleSlugs) {
            foreach ($roleSlugs as $slug) {
                $role = $roles->get($slug);
                if ($role) {
                    Permission::firstOrCreate(
                        ['role_id' => $role->id, 'permission' => $permission],
                        ['role_id' => $role->id, 'permission' => $permission]
                    );
                    $permissionCount++;
                } else {
                    Log::warning('PermissionSeeder: Role not found', [
                        'slug' => $slug,
                        'permission' => $permission,
                    ]);
                }
            }
        }
        // HR Operations is a functional clone of the HR role: it carries the
        // exact same permission rows as HR — including ones granted by standalone
        // migrations (feature.signoff, feature.tickets) that are NOT listed in
        // $assignments above. Mirror HR's live rows here so the two roles never
        // drift and a fresh install reproduces HR Operations = HR. Additive only.
        $hrRole = Role::where('slug', Role::SLUG_HR)->first();
        $hrOpsRole = Role::where('slug', Role::SLUG_HR_OPERATIONS)->first();
        if ($hrRole && $hrOpsRole) {
            foreach (Permission::where('role_id', $hrRole->id)->pluck('permission') as $permission) {
                Permission::firstOrCreate(
                    ['role_id' => $hrOpsRole->id, 'permission' => $permission],
                    ['role_id' => $hrOpsRole->id, 'permission' => $permission]
                );
                $permissionCount++;
            }
        }

        // Content Moderator & QA is a functional clone of the QA Analyst role
        // (same portal as Ranjini's other QA reports, just a distinct display
        // name). Mirror qa_analyst's live permission rows — including ones added
        // by standalone migrations (feature.agile, feature.signoff, feature.tickets)
        // that are NOT in $assignments — so the two roles never drift and a fresh
        // install reproduces Content Moderator & QA = QA Analyst. Additive only.
        $qaRole = Role::where('slug', Role::SLUG_QA_ANALYST)->first();
        $contentQaRole = Role::where('slug', Role::SLUG_CONTENT_MODERATOR_QA)->first();
        if ($qaRole && $contentQaRole) {
            foreach (Permission::where('role_id', $qaRole->id)->pluck('permission') as $permission) {
                Permission::firstOrCreate(
                    ['role_id' => $contentQaRole->id, 'permission' => $permission],
                    ['role_id' => $contentQaRole->id, 'permission' => $permission]
                );
                $permissionCount++;
            }
        }

        Log::info('PermissionSeeder: Assigned permissions', [
            'count' => $permissionCount,
        ]);
    }
}
