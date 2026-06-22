<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;

class ProjectRoleService
{
    /**
     * Get allowed user IDs for a given user based on hierarchy.
     */
    public static function getAllowedUserIdsForUser(User $user): array
    {
        $roleSlug = $user->role;

        if ($roleSlug === Role::SLUG_CEO) {
            return User::whereHas('roleRelation')
                ->whereIn('id', self::getTeamMemberUserIds())
                ->pluck('id')
                ->toArray();
        }

        if ($roleSlug === Role::SLUG_COO) {
            return $user->subordinates()->where('is_active', true)->pluck('id')->toArray();
        }

        if ($roleSlug === Role::SLUG_CMO) {
            $ids = $user->subordinates()->where('is_active', true)->pluck('id')->toArray();
            array_unshift($ids, $user->id);

            return $ids;
        }

        if ($roleSlug === Role::SLUG_TECH_LEAD) {
            $ids = $user->subordinates()->where('is_active', true)->pluck('id')->toArray();
            array_unshift($ids, $user->id);

            return $ids;
        }

        if ($roleSlug === Role::SLUG_OPS) {
            $ids = $user->subordinates()->where('is_active', true)->pluck('id')->toArray();
            array_unshift($ids, $user->id);

            return $ids;
        }

        if ($roleSlug === Role::SLUG_CONTENT_LEAD) {
            $ids = $user->subordinates()->where('is_active', true)->pluck('id')->toArray();
            array_unshift($ids, $user->id);

            return $ids;
        }

        if ($roleSlug === Role::SLUG_CFO) {
            $ids = $user->subordinates()->where('is_active', true)->pluck('id')->toArray();
            array_unshift($ids, $user->id);

            return $ids;
        }

        // Individual contributors: only themselves
        if (in_array($roleSlug, Role::IC_SLUGS, true)) {
            return [$user->id];
        }

        return [];
    }

    /**
     * Get allowed user IDs for a role slug (used when User not available).
     */
    public static function getAllowedUserIdsForRole(string $roleSlug): array
    {
        if ($roleSlug === Role::SLUG_CEO) {
            return self::getTeamMemberUserIds();
        }

        $role = Role::where('slug', $roleSlug)->first();
        if (! $role) {
            return [];
        }

        if ($roleSlug === Role::SLUG_COO) {
            $coo = User::where('role_id', $role->id)->first();

            return $coo ? $coo->subordinates()->where('is_active', true)->pluck('id')->toArray() : [];
        }

        if ($roleSlug === Role::SLUG_CMO) {
            $cmo = User::where('role_id', $role->id)->first();
            if (! $cmo) {
                return [];
            }
            $ids = $cmo->subordinates()->where('is_active', true)->pluck('id')->toArray();
            array_unshift($ids, $cmo->id);

            return $ids;
        }

        if ($roleSlug === Role::SLUG_OPS) {
            $ops = User::where('role_id', $role->id)->first();
            if (! $ops) {
                return [];
            }
            $ids = $ops->subordinates()->where('is_active', true)->pluck('id')->toArray();
            array_unshift($ids, $ops->id);

            return $ids;
        }

        if ($roleSlug === Role::SLUG_CONTENT_LEAD) {
            $cl = User::where('role_id', $role->id)->first();
            if (! $cl) {
                return [];
            }
            $ids = $cl->subordinates()->where('is_active', true)->pluck('id')->toArray();
            array_unshift($ids, $cl->id);

            return $ids;
        }

        if (in_array($roleSlug, Role::IC_SLUGS, true)) {
            $u = User::where('role_id', $role->id)->whereIn('id', self::getTeamMemberUserIds())->first();

            return $u ? [$u->id] : [];
        }

        return [];
    }

    /**
     * Check if a user can access a target user (by user_id).
     */
    public static function canAccessUser(User $user, int $targetUserId): bool
    {
        $allowed = self::getAllowedUserIdsForUser($user);

        return in_array($targetUserId, $allowed, true);
    }

    /**
     * Daily-reports access: the normal role-based scope PLUS the requester's
     * direct reports (reporting_manager_id) and dotted-line reports
     * (secondary_manager_id). This MUST mirror the daily-reports picker built
     * in DashboardController — anything the picker renders as a tab must be
     * openable here, or the tab 403s.
     *
     * Direct reports matter because canAccessUser() is role-based: an IC-role
     * user (e.g. a Gen AI Developer) who is nonetheless someone's reporting
     * manager resolves to self-only there, so without this they'd be blocked
     * from their own team's tabs.
     *
     * This wider scope is intentionally limited to daily reports. Friday
     * rating, leaves, and other manager views still resolve scope from
     * reporting_manager_id via their own queries / canAccessUser().
     */
    public static function canAccessUserDailyReport(User $user, int $targetUserId): bool
    {
        if (self::canAccessUser($user, $targetUserId)) {
            return true;
        }

        return User::where('id', $targetUserId)
            ->where('is_active', true)
            ->where(function ($q) use ($user) {
                $q->where('reporting_manager_id', $user->id)
                    ->orWhere('secondary_manager_id', $user->id);
            })
            ->exists();
    }

    /**
     * Check if a role slug can access a target user_id.
     */
    public static function canAccessUserByRole(string $roleSlug, int $targetUserId): bool
    {
        $allowed = self::getAllowedUserIdsForRole($roleSlug);

        return in_array($targetUserId, $allowed, true);
    }

    public static function canEditKpiEntry(string $role): bool
    {
        return Role::roleHasPermission($role, 'kpi.edit_entry');
    }

    public static function canSetKpiTarget(string $role): bool
    {
        return Role::roleHasPermission($role, 'kpi.set_target');
    }

    public static function canSaveCeoNote(string $role): bool
    {
        return Role::roleHasPermission($role, 'kpi.save_ceo_note');
    }

    public static function canEditDailyReport(string $role): bool
    {
        return Role::roleHasPermission($role, 'daily_report.edit');
    }

    public static function canManageKpiDefinitions(string $role): bool
    {
        return Role::roleHasPermission($role, 'kpi.manage_definitions');
    }

    public static function canManageTemplates(string $role): bool
    {
        return Role::roleHasPermission($role, 'template.manage');
    }

    public static function canAccessMeetings(string $role): bool
    {
        return Role::roleHasPermission($role, 'meeting.access');
    }

    public static function canViewOrg(string $role): bool
    {
        return Role::roleHasPermission($role, 'org.view');
    }

    public static function hasFeature(string $role, string $feature): bool
    {
        $key = str_starts_with($feature, 'feature.') ? $feature : 'feature.'.$feature;

        return Role::roleHasPermission($role, $key);
    }

    public static function canGenerateScripts(string $role): bool
    {
        return Role::roleHasPermission($role, 'scripts.generate');
    }

    public static function canManageAgileSprints(string $role): bool
    {
        return Role::roleHasPermission($role, 'agile.manage_sprints');
    }

    public static function canManageAgileEpics(string $role): bool
    {
        return Role::roleHasPermission($role, 'agile.manage_epics');
    }

    public static function canManageAgileSquads(string $role): bool
    {
        return Role::roleHasPermission($role, 'agile.manage_squads');
    }

    public static function canManageAgileLabels(string $role): bool
    {
        return Role::roleHasPermission($role, 'agile.manage_labels');
    }

    public static function canCrudAgileStories(string $role): bool
    {
        return Role::roleHasPermission($role, 'agile.crud_stories');
    }

    public static function canCrudAgileBugs(string $role): bool
    {
        return Role::roleHasPermission($role, 'agile.crud_bugs');
    }

    public static function canAssignAgileItems(string $role): bool
    {
        return Role::roleHasPermission($role, 'agile.assign_items');
    }

    public static function canViewAgileDashboard(string $role): bool
    {
        return Role::roleHasPermission($role, 'agile.view_dashboard');
    }

    /**
     * Get user metadata from DB (name, role, project, reporting_manager).
     */
    public static function getUserMetadata(int $userId): ?array
    {
        $u = User::with(['roleRelation', 'reportingManager', 'projects'])->find($userId);
        if (! $u) {
            return null;
        }
        $projectNames = $u->projects->pluck('name')->toArray();

        return [
            'name' => $u->name,
            'role' => $u->roleRelation?->name ?? '',
            'project' => implode(', ', $projectNames) ?: null,
            'reporting_manager' => $u->reportingManager?->name,
        ];
    }

    /**
     * Get metadata for all team members (user_id => metadata).
     */
    public static function getAllUserMetadata(): array
    {
        $userIds = self::getTeamMemberUserIds();
        $result = [];
        foreach ($userIds as $id) {
            $meta = self::getUserMetadata($id);
            if ($meta) {
                $result[$id] = $meta;
            }
        }

        return $result;
    }

    /**
     * Check if user has Operations Manager role (for ops fallback).
     */
    public static function isOpsManager(int $userId): bool
    {
        return User::find($userId)?->roleRelation?->slug === Role::SLUG_OPS;
    }

    /**
     * Team member user IDs (users who report to someone, i.e. ICs with KPIs).
     */
    private static function getTeamMemberUserIds(): array
    {
        return User::where('is_active', true)
            ->whereNotNull('reporting_manager_id')
            ->pluck('id')
            ->toArray();
    }
}
