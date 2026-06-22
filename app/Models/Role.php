<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    const SLUG_CEO = 'ceo';

    const SLUG_COO = 'coo';

    const SLUG_CMO = 'cmo';

    const SLUG_CFO = 'cfo';

    const SLUG_OPS = 'ops';

    const SLUG_MARKETING = 'marketing';

    const SLUG_PRODUCT_MANAGER = 'product_manager';

    const SLUG_GROWTH_MANAGER = 'growth_manager';

    const SLUG_VIDEO_EDITOR = 'video_editor';

    const SLUG_GRAPHIC_DESIGNER = 'graphic_designer';

    const SLUG_CONTENT_LEAD = 'content_lead';

    const SLUG_CONTENT_CREATOR = 'content_creator';

    const SLUG_SOCIAL_MEDIA = 'social_media';

    const SLUG_HR = 'hr';

    // Functional clone of the HR role — same Tessa portal as HR. Mirrored
    // everywhere SLUG_HR is gated (permissions + HR controller allowlists).
    const SLUG_HR_OPERATIONS = 'hr_operations';

    const SLUG_TECHNICAL_SUPPORT = 'technical_support';

    const SLUG_ACCOUNTANT = 'accountant';

    const SLUG_TECH_LEAD = 'tech_lead';

    const SLUG_QA_ANALYST = 'qa_analyst';

    const SLUG_FULL_STACK_DEVELOPER = 'full_stack_developer';

    const SLUG_GEN_AI_DEVELOPER = 'gen_ai_developer';

    const SLUG_DATA_ANALYST = 'data_analyst';

    const SLUG_BUSINESS_ANALYST = 'business_analyst';

    const SLUG_ADMIN = 'admin';

    const SLUG_FOUNDERS_OFFICE = 'founders_office';

    const SLUG_AI_INTERN = 'ai_intern';

    const SLUG_TEAM_LEAD_OPERATIONS = 'team_lead_operations';

    const SLUG_CUSTOMER_SUPPORT_EXECUTIVE = 'customer_support_executive';

    // Fida's promotion. A faithful clone of gen_ai_developer (identical
    // permissions + IC scope) with its own display name, so "Lead AI Engineer"
    // surfaces everywhere roleRelation->name is shown without changing access.
    const SLUG_LEAD_AI_ENGINEER = 'lead_ai_engineer';

    // External freelance recruiters (Yashasvi, Rohit) for the Hiring/ATS
    // pipeline. A BARE role — its portal is stripped to the Hiring tab only in
    // DashboardController::roleConfig(); NOT an employee, NOT in IC_SLUGS.
    const SLUG_FREELANCE_RECRUITER = 'freelance_recruiter';

    // Combined content-moderation + QA role. A BARE least-privilege IC role:
    // no permission rows, basic-employee portal via DashboardController fallback.
    const SLUG_CONTENT_MODERATOR_QA = 'content_moderator_qa';

    const IC_SLUGS = [
        self::SLUG_MARKETING,
        self::SLUG_PRODUCT_MANAGER,
        self::SLUG_GROWTH_MANAGER,
        self::SLUG_VIDEO_EDITOR,
        self::SLUG_GRAPHIC_DESIGNER,
        self::SLUG_CONTENT_LEAD,
        self::SLUG_CONTENT_CREATOR,
        self::SLUG_SOCIAL_MEDIA,
        self::SLUG_HR,
        self::SLUG_HR_OPERATIONS,
        self::SLUG_TECHNICAL_SUPPORT,
        self::SLUG_ACCOUNTANT,
        self::SLUG_TECH_LEAD,
        self::SLUG_QA_ANALYST,
        self::SLUG_FULL_STACK_DEVELOPER,
        self::SLUG_GEN_AI_DEVELOPER,
        self::SLUG_LEAD_AI_ENGINEER,
        self::SLUG_DATA_ANALYST,
        self::SLUG_BUSINESS_ANALYST,
        self::SLUG_FOUNDERS_OFFICE,
        // An AI intern is an individual contributor — sees only their own
        // data, same as other developer/analyst ICs.
        self::SLUG_AI_INTERN,
        // Team Lead – Operations and Customer Support Executive default to
        // individual contributors (see only their own data). They get the
        // basic-employee portal via DashboardController's fallback; grant
        // subordinate visibility / elevated features later if that's needed.
        self::SLUG_TEAM_LEAD_OPERATIONS,
        self::SLUG_CUSTOMER_SUPPORT_EXECUTIVE,
        // Content Moderator & QA is an individual contributor (sees only their
        // own data); gets the basic-employee portal via DashboardController.
        self::SLUG_CONTENT_MODERATOR_QA,
    ];

    protected $fillable = ['name', 'slug'];

    /** @var array<string, string[]> Per-request cache: role slug => permission keys */
    private static array $permissionCache = [];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    public static function roleHasPermission(string $slug, string $permission): bool
    {
        if (! isset(self::$permissionCache[$slug])) {
            $role = self::where('slug', $slug)->first();
            if (! $role) {
                Log::warning('Role::roleHasPermission role not found', [
                    'slug' => $slug,
                    'permission' => $permission,
                ]);
            }
            self::$permissionCache[$slug] = $role
                ? $role->permissions()->pluck('permission')->toArray()
                : [];
        }

        return in_array($permission, self::$permissionCache[$slug], true);
    }

    /** @return string[] Role slugs that have the given permission */
    public static function getSlugsWithPermission(string $permission): array
    {
        return self::whereHas('permissions', fn ($q) => $q->where('permission', $permission))
            ->pluck('slug')
            ->toArray();
    }
}
