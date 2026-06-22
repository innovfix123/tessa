@php
    $layout = $config['layout'] ?? 'full';
    $features = $config['features'] ?? [];
    $featureLabels = [
        'dashboard' => 'Dashboard',
        'ai' => 'Tessa',
        'tessa' => 'Tessa',
        'tasks' => 'Tasks',
        'checklists' => 'Checklists',
        'meetings' => 'Meetings',
        'daily' => 'Daily Reports',
        'mkpi' => 'Marketing KPIs',
        'org' => 'Org Chart',
        'signoff' => 'Sign Off',
        'scripts' => 'Scripts',
        'tickets' => 'Tickets',
        'revenue' => 'Revenue',
        'hima_revenue_sheet' => 'Hima Revenue Sheet',
        'onlycare_revenue_sheet' => 'Only Care Revenue Sheet',
        'sudar_revenue_sheet' => 'Sudar Revenue Sheet',
        'cpa_master_sheet' => 'CPA Master Sheet',
        'hr_records' => 'Employee Records',
        'invoices' => 'Invoices',
        'meta_ads' => 'Meta Ads',
        'google_ads' => 'Google Ads',
        'mission' => 'Mission',
        'employees' => 'Team',
        'hr_dashboard' => 'HR Overview',
        'letters' => 'Offer Letters',
        'team_status' => 'Team Status',
        'profile' => 'My Profile',
        'agile' => 'Agile',
        'leave' => 'Leave',
        'team_leave' => 'Team Leave',
        'holidays' => 'Holidays & Birthdays',
        'policies' => 'Innovfix Policies',
        'my_score' => (auth()->user()?->role === \App\Models\Role::SLUG_CEO) ? 'Team KRAs' : 'My KRAs',
        'manager_ratings' => 'Manager Ratings',
        'kpi_report' => 'KPI Report',
        'notes' => 'Notes',
        'logs' => 'Logs',
        'claude_context' => 'Claude Context',
        'ai_expense' => 'AI Expense',
        'salary_tool' => 'Salary Tool',
        'network_leverage' => 'Network Leverage',
        'slack' => 'Slack',
        'schedule' => 'Schedule',
        'github' => 'GitHub',
        'google' => 'Google',
        'archives' => 'Archives',
        'timesheets' => 'Timesheets',
        'weeklyTimesheet' => 'Weekly Timesheet',
        'workforceAdmin' => 'Workforce',
        'timesheetTracker' => 'Timesheet Tracker',
        'rewards' => 'Rewards',
        'attendance' => 'Attendance',
        'signin_status' => 'Sign-In Status',
        'bills' => 'Bills',
        'hiring' => 'Hiring',
        'calendar' => 'Calendar',
    ];
    $hasOrg = in_array('org', $features, true);
    /** Inline SVGs for sidebar nav (18×18, currentColor except Tessa logo) */
    $navIconSvgs = [
        'dashboard' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
        'tessa' => '<svg class="side-nav-icon-svg tessa-nav-logo" width="18" height="18" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 5 L58 5 L58 13 L40 13 L36 19 L36 55 L24 55 L24 19 L20 13 L2 13 Z" fill="#3b82f6"/></svg>',
        'ai' => '<svg class="side-nav-icon-svg tessa-nav-logo" width="18" height="18" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 5 L58 5 L58 13 L40 13 L36 19 L36 55 L24 55 L24 19 L20 13 L2 13 Z" fill="#3b82f6"/></svg>',
        'tasks' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        'checklists' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 9l2 2 4-4"/><path d="M8 15h8"/></svg>',
        'meetings' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
        'daily' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>',
        'mkpi' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 7v10M14 10v7M10 13v4M6 16v1"/></svg>',
        'org' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'notes' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.5 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.5L15.5 3z"/><polyline points="14 3 14 8 21 8"/></svg>',
        'logs' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        'claude_context' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/><path d="m9.5 10.5 1 1.8 1.8 1-1.8 1-1 1.8-1-1.8-1.8-1 1.8-1z"/></svg>',
        'ai_expense' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="6"/><rect x="13" y="7" width="3" height="10"/></svg>',
        'salary_tool' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="14" x2="8" y2="14"/><line x1="12" y1="14" x2="12" y2="14"/><line x1="16" y1="14" x2="16" y2="18"/><line x1="8" y1="18" x2="12" y2="18"/></svg>',
        'network_leverage' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'signoff' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'scripts' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        'tickets' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v2M13 17v2M13 11v2"/></svg>',
        'revenue' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'hima_revenue_sheet' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>',
        'onlycare_revenue_sheet' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>',
        'sudar_revenue_sheet' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>',
        'cpa_master_sheet' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>',
        'hr_records' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8M8 9h2"/></svg>',
        'invoices' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h2M8 17h2M14 13h2M14 17h2"/></svg>',
        'meta_ads' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>',
        'google_ads' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.64 5.64l2.12 2.12M16.24 16.24l2.12 2.12M5.64 18.36l2.12-2.12M16.24 7.76l2.12-2.12"/></svg>',
        'mission' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
        'employees' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'hr_dashboard' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><path d="M7 10l3-3 2 2 5-5"/></svg>',
        'letters' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="M9 13h6M9 17h6"/><path d="M18.5 14.5l3 3-2.5.5.5-2.5z"/></svg>',
        'team_status' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
        'profile' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'agile' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="8" width="5" height="13" rx="1"/><rect x="17" y="5" width="5" height="16" rx="1"/></svg>',
        'leave' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01"/></svg>',
        'team_leave' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="m16 11 2 2 4-4"/></svg>',
        'holidays' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>',
        'my_score' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'policies' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M9 7h7M9 11h5"/></svg>',
        'manager_ratings' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V8l4-3 4 3v13"/><path d="M13 21V12l4-2 4 2v9"/><circle cx="9" cy="11" r="1"/><circle cx="17" cy="14" r="1"/></svg>',
        'kpi_report' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2h6a1 1 0 0 1 1 1v2H8V3a1 1 0 0 1 1-1z"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M8 17v-3M12 17v-5M16 17v-2"/></svg>',
        'google' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>',
        'github' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>',
        'schedule' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'slack' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 10c-.83 0-1.5-.67-1.5-1.5v-5c0-.83.67-1.5 1.5-1.5s1.5.67 1.5 1.5v5c0 .83-.67 1.5-1.5 1.5z"/><path d="M20.5 10H19V8.5c0-.83.67-1.5 1.5-1.5s1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/><path d="M9.5 14c.83 0 1.5.67 1.5 1.5v5c0 .83-.67 1.5-1.5 1.5S8 21.33 8 20.5v-5c0-.83.67-1.5 1.5-1.5z"/><path d="M3.5 14H5v1.5c0 .83-.67 1.5-1.5 1.5S2 16.33 2 15.5 2.67 14 3.5 14z"/><path d="M14 14.5c0-.83.67-1.5 1.5-1.5h5c.83 0 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5h-5c-.83 0-1.5-.67-1.5-1.5z"/><path d="M14 20.5c0-.83.67-1.5 1.5-1.5s1.5.67 1.5 1.5-.67 1.5-1.5 1.5-1.5-.67-1.5-1.5z"/><path d="M10 9.5C10 10.33 9.33 11 8.5 11h-5C2.67 11 2 10.33 2 9.5S2.67 8 3.5 8h5c.83 0 1.5.67 1.5 1.5z"/><path d="M10 3.5C10 4.33 9.33 5 8.5 5S7 4.33 7 3.5 7.67 2 8.5 2s1.5.67 1.5 1.5z"/></svg>',
        'archives' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="5" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 12h4"/></svg>',
        'default' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="2"/></svg>',
        'timesheets' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        'weeklyTimesheet' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/><path d="M12 13v4l2.5 1.5"/></svg>',
        'workforceAdmin' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'timesheetTracker' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/><path d="M3 16h6"/></svg>',
        'rewards' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M8.5 12.5 7 22l5-3 5 3-1.5-9.5"/></svg>',
        'attendance' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 5-5"/><path d="M21 12c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9c2.5 0 4.76 1.02 6.4 2.66"/></svg>',
        'signin_status' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        'bills' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 2h11l3 3v17l-2.5-1.5L14 22l-2-1.5L10 22l-2.5-1.5L5 22V2z"/><path d="M9 7h6M9 11h6M9 15h4"/></svg>',
        'hiring' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>',
        'calendar' => '<svg class="side-nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>',
    ];
@endphp
@if ($layout === 'simple')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InnovFix - {{ $config['title'] }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('img/tessa-logo.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('img/favicon-16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="top-bar">
        <div class="logo">InnovFix</div>
        <div class="user" style="color:#a3a3a3;font-size:0.85rem;display:flex;align-items:center;gap:10px">
            <span>{{ now('Asia/Kolkata')->format('D, M j') }}</span>
            <span>{{ $config['userName'] ?? '' }}</span>
            <span>{{ $config['roleName'] ?? strtoupper(auth()->user()->role ?? '') }}</span>
            <form action="{{ route('logout') }}" method="POST" style="display:inline">
                @csrf
                <button type="submit" class="logout-btn" style="border:1px solid #2d2d2d;background:#151515;color:#f5f5f5;border-radius:8px;padding:8px 12px;cursor:pointer">Logout</button>
            </form>
        </div>
    </header>
    <main class="wrap">
        <style>.hero,.card{border:1px solid #232323;background:#121212;border-radius:14px;padding:20px}.hero{margin-bottom:16px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.link-btn{display:inline-block;text-decoration:none;color:#111;background:#f5f5f5;border-radius:8px;padding:9px 12px;font-weight:600;font-size:.88rem}</style>
        <section class="hero">
            <h1>{{ $config['title'] }}</h1>
            <p>Access your tools and insights quickly.</p>
        </section>
        <section class="grid">
            @foreach ($config['links'] ?? [] as $link)
            <article class="card">
                <h3>{{ $link['label'] }}</h3>
                <p>{{ $link['desc'] ?? '' }}</p>
                <a class="link-btn" href="{{ url($link['url']) }}">Open {{ $link['label'] }}</a>
            </article>
            @endforeach
        </section>
    </main>
    <script>window.__PORTAL_CONFIG = @json($config);</script>
    <script>
    (function(){
        var HEARTBEAT_MS = 10 * 60 * 1000;
        var wasHidden = false;
        var pendingReload = false;

        // True while the user is mid-edit (a focused text field / editor). We never
        // reload out from under active input — that would lose unsaved typing.
        function isEditing(){
            var el = document.activeElement;
            if (!el) return false;
            var tag = (el.tagName || '').toLowerCase();
            return tag === 'input' || tag === 'textarea' || tag === 'select' || !!el.isContentEditable;
        }

        // Reload now if it's safe; otherwise defer until the user finishes editing.
        function reloadWhenIdle(){
            if (isEditing()) { pendingReload = true; return; }
            location.reload();
        }

        // When focus leaves an editable field, honour a deferred reload — but wait a
        // moment so the daily-report blur auto-save (and similar) can finish first.
        document.addEventListener('focusout', function(){
            if (!pendingReload) return;
            setTimeout(function(){
                if (pendingReload && !isEditing()) { pendingReload = false; location.reload(); }
            }, 1500);
        });

        // Refresh every time the user returns to the Tessa tab, so server-side changes
        // (sidebar tabs, projects, team data, deploys) show without a manual reload.
        // Gate on wasHidden so a page opened in a background tab isn't reloaded on its
        // first focus; visibilitychange never fires on initial load.
        document.addEventListener('visibilitychange', function(){
            if (document.visibilityState === 'hidden') { wasHidden = true; return; }
            if (wasHidden) { wasHidden = false; reloadWhenIdle(); }
        });

        // Session heartbeat (unchanged): reload to the login if the session is lost.
        setInterval(function(){
            fetch('/api/auth/session', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(r){ return r.ok ? r.json() : null; }).then(function(d){
                if (d && d.authenticated === false) { location.reload(); }
            }).catch(function(){});
        }, HEARTBEAT_MS);
    })();
    </script>
</body>
</html>
@else
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InnovFix - {{ $config['title'] }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('img/tessa-logo.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('img/favicon-16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    @if (!empty($config['jp_ai_mode']))
    <link rel="stylesheet" href="{{ asset('css/jp-ai.css') }}?v={{ filemtime(public_path('css/jp-ai.css')) }}">
    @endif
    <link rel="stylesheet" href="{{ asset('shared/meeting.css') }}?v={{ filemtime(public_path('shared/meeting.css')) }}">
    @if ($hasOrg)
    <link rel="stylesheet" href="{{ asset('shared/org.css') }}?v={{ filemtime(public_path('shared/org.css')) }}">
    @endif
</head>
@php
    // Daily sign-in gate: until the user signs in (and again once they sign
    // off for the day) the portal is locked to Dashboard + Leave. Tag the body
    // so the CSS can hide the blocked nav items + Change Password, leaving only
    // the profile photo, monthly KRA, Dashboard/Leave and Logout. portal.js
    // keeps this in sync live as the sign-in/off state flips. Only applies
    // where the Dashboard sign-in flow exists (no Dashboard tab => no way to
    // sign in, so gating it would lock the user out).
    $onboardingLocked = !empty($config['onboardingLocked']);
    $signinGateApplies = in_array('dashboard', $features);
    // The onboarding lock supersedes the daily sign-in lock (it's stricter:
    // Profile only), so the two never apply at the same time.
    $signinLocked = !$onboardingLocked && $signinGateApplies && (empty($config['signedInToday']) || !empty($config['signedOffToday']));
    $bodyClasses = trim(
        ($signinLocked ? 'is-signin-locked ' : '')
        . ($onboardingLocked ? 'is-onboarding-locked ' : '')
        . (($config['portal'] ?? '') === 'freelance_recruiter' ? 'is-hiring-only' : '')
    );
@endphp
<body class="{{ $bodyClasses }}">

    <nav class="side-nav" aria-label="Main">
        <div class="side-nav-top">
            <div class="side-nav-brand"><img src="{{ asset('img/tessa-logo.svg') }}" alt="Tessa" class="side-nav-logo">InnovFix</div>
            <div class="side-nav-title">{{ $config['title'] }}</div>

            @php
                $pp = $config['profilePhoto'] ?? null;
                $pn = $config['userName'] ?? auth()->user()->name;
                $kra = $config['kraMonth'] ?? null;
                $kraAvg = $kra['average'] ?? null;
                $kraBand = $kraAvg === null ? 'na' : ($kraAvg >= 4 ? 'good' : ($kraAvg >= 3 ? 'mid' : 'low'));
            @endphp
            <div class="side-nav-profile">
                <button type="button" class="side-nav-avatar" id="sideNavAvatar" title="Profile photo" aria-label="Profile photo: view or change">
                    @if ($pp)
                        <img src="{{ $pp }}" alt="Profile photo" id="sideNavAvatarImg">
                    @else
                        <span class="side-nav-avatar-initial" id="sideNavAvatarImg">{{ strtoupper(mb_substr($pn, 0, 1)) }}</span>
                    @endif
                    <span class="side-nav-avatar-edit" aria-hidden="true">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </span>
                </button>
                <input type="file" id="sideNavAvatarInput" accept="image/jpeg,image/png,image/webp" hidden>
                @unless ($config['kraExcluded'] ?? false)
                <div class="side-nav-kra" id="sideNavKra">
                    <span class="side-nav-kra-label">Avg KRA{{ $kra && !empty($kra['label']) ? ' · '.$kra['label'] : ' · Last Month' }}</span>
                    @if ($kraAvg !== null)
                        <span class="side-nav-kra-val kra-{{ $kraBand }}">{{ number_format($kraAvg, 1) }}<small>/5</small></span>
                    @else
                        <span class="side-nav-kra-val kra-na">—</span>
                    @endif
                </div>
                @endunless
            </div>
        </div>
        <div class="side-nav-items">
            @php
                $jpAi = !empty($config['jp_ai_mode']);
                // JP AI mode: keep EVERY section's nav link in the DOM (so switchView,
                // active-link tracking and hash-sync all work normally) but visually
                // show only Dashboard + AI. The 'ai' view has no feature flag, so
                // prepend it. Hiding the links (not omitting them) is what keeps the
                // navigation machinery from misfiring when the AI opens a section.
                $navList = $jpAi
                    ? array_values(array_unique(array_merge(['dashboard', 'ai'], $features)))
                    : $features;
                $jpVisibleNav = ['dashboard', 'ai'];
            @endphp
            @foreach ($navList as $feature)
            @php $jpHidden = $jpAi && ! in_array($feature, $jpVisibleNav, true); @endphp
            <a href="#" class="top-nav-link {{ $jpHidden ? 'jp-ai-nav-hidden' : '' }} {{ (! $jpHidden && $loop->first) ? 'active' : '' }}" data-view="{{ $feature }}">
                <span class="side-nav-icon" aria-hidden="true">{!! $navIconSvgs[$feature] ?? $navIconSvgs['default'] !!}</span>
                <span class="side-nav-label">{{ $featureLabels[$feature] ?? $feature }}</span>
                @if ($feature === 'leave' && ($config['pendingTeamLeaves'] ?? 0) > 0)
                <span class="side-nav-badge" title="{{ $config['pendingTeamLeaves'] }} pending approval"></span>
                @elseif ($feature === 'bills' && ($config['pendingBills'] ?? 0) > 0)
                <span class="side-nav-badge" title="{{ $config['pendingBills'] }} awaiting payment"></span>
                @endif
            </a>
            @endforeach
        </div>
        <div class="side-nav-footer">
            <div class="side-nav-user">
                <span class="user-name">{{ $config['userName'] ?? auth()->user()->name }}</span>
                <span class="user-role">{{ $config['roleName'] ?? strtoupper(auth()->user()->role ?? '') }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="side-nav-logout-form">
                @csrf
                <button class="side-nav-action-btn logout-btn" type="submit" id="logoutBtn">Logout</button>
            </form>
        </div>
    </nav>

    <div class="side-content">
        @if ($config['showNextMeetingBanner'] ?? false)
        <div class="side-top-bar" id="nextMeetingBanner"></div>
        @endif

        <div class="side-top-bar birthday-banner" id="birthdayBanner" style="display:none"></div>
        <div class="bday-confetti" id="bdayConfetti" aria-hidden="true"></div>

    {{-- JP AI mode lands on the Tessa chat, so hide the default Meetings view in
         the initial HTML to avoid a flash-of-meetings before the JS boots. --}}
    <main id="meetingsView" class="mtg-page {{ !empty($config['jp_ai_mode']) ? 'hidden' : '' }}">
        @include('partials.meeting', ['hasPreviousMinutes' => $config['hasPreviousMinutes'] ?? true])
    </main>

    @if (in_array('tessa', $features))
    <section id="tessaView" class="hidden tessa-view">
        <div class="tessa-chat-main" id="tessaChatMain">
            <button class="tessa-task-tracker-btn" id="tessaTaskTrackerBtn" type="button" aria-label="View tasks" title="Tasks">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </button>
            <button class="tessa-clear-chat-btn" id="tessaClearChatBtn" type="button" aria-label="New chat" title="New Chat">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </button>
            <div class="tessa-messages" id="tessaMessages"></div>
            <div class="tessa-input-area">
                <div class="tessa-input-wrap">
                    <button id="tessaPlusBtn" class="tessa-plus-btn" type="button" aria-label="More options">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    </button>
                    <div id="tessaPlusMenu" class="tessa-plus-menu hidden">
                        <button type="button" class="tessa-plus-menu-item" data-action="assign-task">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                            Assign Task
                        </button>
                    </div>
                    <textarea id="tessaInput" placeholder="Ask Tessa anything" rows="1"></textarea>
                    <button id="tessaSendBtn" class="tessa-send-btn" type="button" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                    </button>
                </div>
                <div class="tessa-persistent-actions">
                    <button type="button" class="tessa-persist-chip" data-tessa-action="pending">Pending Work</button>
                    <button type="button" class="tessa-persist-chip" data-tessa-action="signin">Sign In</button>
                    <button type="button" class="tessa-persist-chip" data-tessa-action="signoff">Sign Off</button>
                    <button type="button" class="tessa-persist-chip tessa-clear-chip" data-tessa-action="clear">Clear Chat</button>
                </div>
            </div>
        </div>
    </section>
    @endif

    @if (!empty($config['jp_ai_mode']))
    {{-- JP AI Command Center: replaces the 44-section sidebar with one chat.
         JP types → AI replies + opens the right section / pre-fills the task modal. --}}
    {{-- Visible by default (not hidden) so the Tessa chat is the first paint for
         JP — switchView('ai') on boot keeps it shown. --}}
    <section id="aiView" class="jp-ai-view">
        <div class="jp-ai-chat-main">
            {{-- Static greeting for the first paint (before JS boots). renderView()
                 keeps it if there's no saved chat, or replaces it with history. --}}
            <div class="jp-ai-messages" id="jpAiMessages">
                <div class="jp-ai-empty">
                    <span class="jp-ai-empty-spark">✦</span>
                    <h3>Hi {{ explode(' ', $config['userName'] ?? auth()->user()->name)[0] }}, I'm Tessa.</h3>
                    <p>Ask me to open any section or assign a task — I'll take you straight there.</p>
                </div>
            </div>
            <div class="jp-ai-input-area">
                <div class="jp-ai-input-wrap">
                    <textarea id="jpAiInput" placeholder="What do you need, {{ explode(' ', $config['userName'] ?? auth()->user()->name)[0] }}?" rows="1"></textarea>
                    <button id="jpAiSendBtn" class="jp-ai-send-btn" type="button" disabled aria-label="Send">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                    </button>
                </div>
                <div class="jp-ai-chips">
                    <button type="button" class="jp-ai-chip" data-msg="Show me today's sign-in status">Sign-In Status</button>
                    <button type="button" class="jp-ai-chip" data-msg="I want to assign a task">Assign a Task</button>
                    <button type="button" class="jp-ai-chip" data-msg="What's pending for the team today?">Pending Today</button>
                    <button type="button" class="jp-ai-chip" data-msg="Show the team leave calendar">Team Leave</button>
                    <button type="button" class="jp-ai-chip jp-ai-chip-clear" data-msg="__clear__">New Chat</button>
                </div>
            </div>
        </div>
    </section>
    @endif

    @if (in_array('tasks', $features))
    <section id="tasksView" class="hidden tasks-grid-view">
        <div class="tasks-grid-header">
            <div class="tasks-header-left">
                <h3>Tasks</h3>
            </div>
            <div class="tasks-filter-bar">
                <button type="button" class="tasks-filter-btn active" data-filter="all">All</button>
                <button type="button" class="tasks-filter-btn" data-filter="assigned_to_me">Assigned to me</button>
                <button type="button" class="tasks-filter-btn" data-filter="assigned_by_me">Assigned by me</button>
                <button type="button" class="tasks-filter-btn" data-filter="awaiting_my_verification">Awaiting verification</button>
                <button type="button" class="tasks-filter-btn" data-filter="recurring">Recurring</button>
            </div>
        </div>
        <div class="cu-toolbar" id="tasksToolbar">
            <div class="cu-toolbar-left">
                <div class="cu-view-toggle" role="tablist" aria-label="View mode">
                    <button type="button" class="cu-view-btn" data-view="list">List</button>
                    <button type="button" class="cu-view-btn active" data-view="board">Board</button>
                </div>
            </div>
            <div class="cu-toolbar-right">
                <button type="button" class="cu-tb-btn" id="cuFilterPillBtn" title="Filter">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M5 8h6M7 12h2"/></svg>
                    <span id="cuFilterPillLabel">All</span>
                </button>
                <button type="button" class="cu-tb-btn" id="cuClosedBtn" title="Show closed" data-on="1">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M5.5 8l2 2 3.5-4"/></svg>
                    <span>Closed</span>
                </button>
                <button type="button" class="cu-tb-btn" id="cuAssignedByBtn" title="Filter by who assigned">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="6" r="2.5"/><path d="M3 14c0-2.8 2.2-5 5-5s5 2.2 5 5"/></svg>
                    <span id="cuAssignedByLabel">Assigned by</span>
                </button>
                <button type="button" class="cu-tb-btn" id="cuAssigneeBtn" title="View tasks I assigned to a specific person">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="6" r="2.5"/><path d="M3 14c0-2.8 2.2-5 5-5s5 2.2 5 5"/><path d="M11 8l1.5 1.5L15 7"/></svg>
                    <span id="cuAssigneeLabel">Assignee</span>
                </button>
                <span class="cu-tb-me" id="cuMeBadge" title="{{ $config['userName'] ?? '' }}"></span>
                <div class="cu-tb-search">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5l3 3"/></svg>
                    <input type="text" id="tasksSearchInput" placeholder="Search...">
                </div>
                <button type="button" class="cu-add-task-btn" id="tasksAssignBtn">+ Add Task</button>
            </div>
        </div>
        <div class="tasks-grid hidden" id="tasksGridBody"></div>
        <div class="tasks-board hidden" id="tasksBoardBody"></div>
        <div class="cu-list hidden" id="tasksListBody"></div>
    </section>
    @endif

    @if (in_array('checklists', $features))
    <section id="checklistsView" class="hidden tasks-grid-view">
        <div class="tasks-grid-header">
            <div class="tasks-header-left">
                <h3>Checklists</h3>
                <button type="button" class="tasks-assign-btn" id="checklistAssignBtnHeader">+ Assign Checklist</button>
            </div>
        </div>
        <div class="tasks-grid" id="checklistsBody"></div>
    </section>
    @endif

    @if (in_array('network_leverage', $features))
    <section id="network_leverageView" class="hidden"></section>
    @endif

    @if (in_array('dashboard', $features))
    <section id="dashboardView" class="hidden"></section>
    @endif

    @if (in_array('notes', $features))
    <section id="notesView" class="hidden"></section>
    @endif
    @if (in_array('logs', $features))
    <section id="logsView" class="hidden lg-view"></section>
    @endif
    @if (in_array('claude_context', $features))
    <section id="claude_contextView" class="hidden"></section>
    @endif
    @if (in_array('ai_expense', $features))
    <section id="ai_expenseView" class="hidden"></section>
    @endif
    @if (in_array('salary_tool', $features))
    <section id="salary_toolView" class="hidden"></section>
    @endif
    @if (in_array('daily', $features))
    <section id="dailyView" class="hidden"></section>
    @endif
    @if (in_array('mkpi', $features))
    <section id="mkpiView" class="hidden"></section>
    @endif
    @if (in_array('signoff', $features))
    <section id="signoffView" class="hidden"></section>
    @endif
    @if (in_array('org', $features))
    <section id="orgView" class="hidden"></section>
    @endif
    @if (in_array('scripts', $features))
    <section id="scriptsView" class="hidden scripts-layout">
        <div class="scripts-inner" id="scriptsRoot"></div>
    </section>
    @endif
    @if (in_array('tickets', $features))
    <section id="ticketsView" class="hidden"></section>
    @endif
    @if (in_array('revenue', $features))
    <section id="revenueView" class="hidden"></section>
    @endif
    @if (in_array('hima_revenue_sheet', $features))
    @php($himaSheetTopOffset = ($config['showNextMeetingBanner'] ?? false) ? '60px' : '0px')
    <section id="hima_revenue_sheetView" class="hidden" style="padding:0;margin:0;height:calc(100vh - {{ $himaSheetTopOffset }});overflow:hidden;overscroll-behavior:contain;">
        <iframe
            src="https://docs.google.com/spreadsheets/d/1V1SWkJ0RXtmr2b-_Gu-Y1yTXOpa6i4IzDJJpW6bAXsc/edit?usp=sharing&rm=embedded"
            style="display:block;width:100%;height:100%;border:0;background:#0b0b0b;overscroll-behavior:contain;"
            allow="clipboard-read; clipboard-write"
            allowfullscreen
            referrerpolicy="no-referrer"
            title="Hima Revenue Sheet"></iframe>
    </section>
    @endif
    @if (in_array('onlycare_revenue_sheet', $features))
    @php($onlyCareSheetTopOffset = ($config['showNextMeetingBanner'] ?? false) ? '60px' : '0px')
    <section id="onlycare_revenue_sheetView" class="hidden" style="padding:0;margin:0;height:calc(100vh - {{ $onlyCareSheetTopOffset }});overflow:hidden;overscroll-behavior:contain;">
        <iframe
            src="https://docs.google.com/spreadsheets/d/10HhzXD9r0tW5u7zNt6eM9llun-umC3Qo9ucGgL0yLow/edit?usp=sharing&rm=embedded"
            style="display:block;width:100%;height:100%;border:0;background:#0b0b0b;overscroll-behavior:contain;"
            allow="clipboard-read; clipboard-write"
            allowfullscreen
            referrerpolicy="no-referrer"
            title="Only Care Revenue Sheet"></iframe>
    </section>
    @endif
    @if (in_array('sudar_revenue_sheet', $features))
    @php($sudarSheetTopOffset = ($config['showNextMeetingBanner'] ?? false) ? '60px' : '0px')
    <section id="sudar_revenue_sheetView" class="hidden" style="padding:0;margin:0;height:calc(100vh - {{ $sudarSheetTopOffset }});overflow:hidden;overscroll-behavior:contain;">
        <iframe
            src="https://docs.google.com/spreadsheets/d/10YrHkA396cSuHxADPeuaruO2lVatJfQFryd3gWO0Zik/edit?usp=sharing&rm=embedded"
            style="display:block;width:100%;height:100%;border:0;background:#0b0b0b;overscroll-behavior:contain;"
            allow="clipboard-read; clipboard-write"
            allowfullscreen
            referrerpolicy="no-referrer"
            title="Sudar Revenue Sheet"></iframe>
    </section>
    @endif
    @if (in_array('cpa_master_sheet', $features))
    @php($cpaSheetTopOffset = ($config['showNextMeetingBanner'] ?? false) ? '60px' : '0px')
    @php($cpaSheetId = config('cpa_master_sheet.sheet_id'))
    @php($cpaTabs = config('cpa_master_sheet.tabs', []))
    @php($cpaFirstGid = $cpaTabs[0]['gid'] ?? config('cpa_master_sheet.gid'))
    {{-- Google hides a sheet's native tab strip in a cross-domain iframe, so we
         render our own language tabs that swap the embed's gid (kept clean with
         rm=embedded). Tabs + gids come from config/cpa_master_sheet.php. --}}
    <section id="cpa_master_sheetView" class="hidden" style="padding:0;margin:0;height:calc(100vh - {{ $cpaSheetTopOffset }});display:flex;flex-direction:column;overflow:hidden;overscroll-behavior:contain;">
        <div class="dash-tabs" id="cpaTabBar" style="margin:0;padding:6px 12px;background:#1a1a2e;border-bottom:1px solid #2a2a3e;flex:0 0 auto;flex-wrap:nowrap;overflow-x:auto;">
            @foreach ($cpaTabs as $i => $t)
            <button type="button" class="dash-tab {{ $i === 0 ? 'active' : '' }}" data-gid="{{ $t['gid'] }}">{{ $t['name'] }}</button>
            @endforeach
        </div>
        <iframe id="cpaSheetFrame"
            src="https://docs.google.com/spreadsheets/d/{{ $cpaSheetId }}/edit?usp=sharing&rm=embedded&gid={{ $cpaFirstGid }}"
            style="display:block;flex:1 1 auto;min-height:0;width:100%;border:0;background:#0b0b0b;overscroll-behavior:contain;"
            allow="clipboard-read; clipboard-write"
            allowfullscreen
            referrerpolicy="no-referrer"
            title="CPA Master Sheet"></iframe>
    </section>
    <script>
    (function () {
        var bar = document.getElementById('cpaTabBar');
        var frame = document.getElementById('cpaSheetFrame');
        if (!bar || !frame) return;
        var base = 'https://docs.google.com/spreadsheets/d/{{ $cpaSheetId }}/edit?usp=sharing&rm=embedded&gid=';
        bar.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-gid]');
            if (!btn) return;
            frame.src = base + btn.getAttribute('data-gid');
            Array.prototype.forEach.call(bar.querySelectorAll('.dash-tab'), function (b) {
                b.classList.toggle('active', b === btn);
            });
        });
    })();
    </script>
    @endif
    @if (in_array('hr_records', $features))
    @php($hrRecordsTopOffset = ($config['showNextMeetingBanner'] ?? false) ? '60px' : '0px')
    @php($hrSheetId = config('services.google.service_account.sheet_id'))
    @php($hrDriveFolderId = config('services.google.service_account.drive_folder_id'))
    <section id="hr_recordsView" class="hidden" style="padding:0;margin:0;height:calc(100vh - {{ $hrRecordsTopOffset }});display:flex;flex-direction:column;overflow:hidden;overscroll-behavior:contain;">
        {{-- Two tabs, both Google iframes lazy-loaded from data-src on first activation:
             ESIC Sheet (the master Sheet, HR fills by hand) + Employee Documents (the master
             HR Drive folder via embeddedfolderview, populated by the Tessa-profile → Drive sync). --}}
        <div class="dash-tabs hr-rec-tabs" style="display:flex;align-items:center;gap:8px;padding:8px 14px;background:#1a1a2e;border-bottom:1px solid #2a2a3e;flex:0 0 auto;">
            <button type="button" class="dash-tab active" data-hrtab="sheet">ESIC Sheet</button>
            <button type="button" class="dash-tab" data-hrtab="drive">Employee Documents</button>
        </div>
        <div class="hr-rec-panel" data-hrpanel="sheet" style="flex:1 1 auto;min-height:0;">
            <iframe data-src="https://docs.google.com/spreadsheets/d/{{ $hrSheetId }}/edit?usp=sharing&rm=embedded"
                style="display:block;width:100%;height:100%;border:0;background:#0b0b0b;overscroll-behavior:contain;"
                allow="clipboard-read; clipboard-write" allowfullscreen referrerpolicy="no-referrer" title="ESIC Sheet"></iframe>
        </div>
        <div class="hr-rec-panel hidden" data-hrpanel="drive" style="flex:1 1 auto;min-height:0;overflow-y:auto;">
            {{-- Employee Documents: a Tessa-native, restyled Drive folder browser rendered by
                 HRModule.renderDriveBrowser() via GET /api/employees/drive-folder (writer-backed
                 listing). Folder clicks navigate in-page; files preview inline (drive .../preview)
                 — nothing opens a new Google tab. Replaces the old cross-origin embeddedfolderview. --}}
            <div id="hrRecDocs"></div>
        </div>
    </section>
    @endif
    @if (in_array('invoices', $features))
    <section id="invoicesView" class="hidden"></section>
    @endif
    @if (in_array('meta_ads', $features))
    <section id="meta_adsView" class="hidden"></section>
    <section id="google_adsView" class="hidden"></section>
    @endif
    @if (in_array('mission', $features))
    <section id="missionView" class="hidden"></section>
    @endif
    @if (in_array('employees', $features))
    <section id="employeesView" class="hidden"></section>
    <section id="addMemberView" class="hidden"></section>
    <section id="editMemberView" class="hidden"></section>
    <section id="promoteMemberView" class="hidden"></section>
    @endif
    @if (in_array('hr_dashboard', $features))
    <section id="hr_dashboardView" class="hidden"></section>
    @endif
    @if (in_array('letters', $features))
    <section id="lettersView" class="hidden"></section>
    @endif
    @if (in_array('team_status', $features))
    <section id="team_statusView" class="hidden"></section>
    @endif
    <section id="profileView" class="hidden"></section>

    <section id="leaveView" class="hidden"></section>
    <section id="team_leaveView" class="hidden" style="padding:24px;"></section>
    <section id="holidaysView" class="hidden" style="padding:24px;">
        <h2 style="color:#fafafa;font-size:1.25rem;font-weight:600;margin:0 0 16px;">InnovFix Holidays &amp; Birthdays 2026</h2>
        <div id="holidayLegend"></div>
        <div id="holidayCalendarGrid"></div>
        <div id="holidayUpcomingList"></div>
    </section>
    @php($policiesTopOffset = ($config['showNextMeetingBanner'] ?? false) ? '60px' : '0px')
    {{-- Innovfix Policies — the company policy handbook is maintained as a standalone,
         self-navigating page (public/policies.html, its own category sidebar). We embed
         it same-origin so any edit there flows straight through; lazy-loaded from data-src
         on first open (see onSwitchView in portal.js). --}}
    <section id="policiesView" class="hidden" style="padding:0;margin:0;height:calc(100vh - {{ $policiesTopOffset }});display:flex;flex-direction:column;overflow:hidden;overscroll-behavior:contain;">
        <iframe id="policiesFrame" data-src="{{ asset('policies.html') }}"
            style="display:block;flex:1 1 auto;min-height:0;width:100%;border:0;background:#0b0b0b;overscroll-behavior:contain;"
            referrerpolicy="no-referrer"
            title="Innovfix Policies"></iframe>
    </section>
    <section id="my_scoreView" class="hidden"></section>
    <section id="manager_ratingsView" class="hidden"></section>
    <section id="kpi_reportView" class="hidden"></section>
    <section id="scheduleView" class="hidden"></section>
    <section id="archivesView" class="hidden">
        <div class="dash-tabs arch-tabs">
            <button type="button" class="dash-tab active" data-archtab="slack">Slack</button>
            <button type="button" class="dash-tab" data-archtab="github">GitHub</button>
            <button type="button" class="dash-tab" data-archtab="google">Google</button>
        </div>
        <div class="arch-panel" data-archpanel="slack"><section id="slackView"></section></div>
        <div class="arch-panel hidden" data-archpanel="github"><section id="githubView"></section></div>
        <div class="arch-panel hidden" data-archpanel="google"><section id="googleView"></section></div>
    </section>
    @if (in_array('agile', $features))
    <section id="agileView" class="hidden agile-layout">
        <div class="agile-root" id="agileRoot"></div>
    </section>
    @endif
    @if (in_array('timesheets', $features))
    <section id="timesheetsView" class="hidden"></section>
    @endif
    @if (in_array('weeklyTimesheet', $features))
    <section id="weeklyTimesheetView" class="hidden"></section>
    @endif
    @if (in_array('workforceAdmin', $features))
    <section id="workforceAdminView" class="hidden"></section>
    @endif
    @if (in_array('timesheetTracker', $features))
    <section id="timesheetTrackerView" class="hidden"></section>
    @endif
    @if (in_array('rewards', $features))
    <section id="rewardsView" class="hidden"></section>
    @endif
    @if (in_array('attendance', $features))
    <section id="attendanceView" class="hidden" style="padding:24px;"></section>
    @endif
    @if (in_array('signin_status', $features))
    <section id="signin_statusView" class="hidden" style="padding:24px;"></section>
    @endif
    @if (in_array('bills', $features))
    <section id="billsView" class="hidden"></section>
    @endif
    @if (in_array('hiring', $features))
    <section id="hiringView" class="hidden"></section>
    @endif
    @if (in_array('calendar', $features))
    <section id="calendarView" class="hidden"></section>
    @endif

    </div>{{-- /.side-content --}}

    {{-- Floating Tessa Chat Widget --}}
    <button type="button" class="tessa-fab" id="tessaFab" title="Chat with Tessa">
        <span class="tessa-fab-icon">T</span>
        <span class="tessa-fab-pulse"></span>
    </button>
    <div class="tessa-widget hidden" id="tessaWidget">
        <div class="tessa-widget-header">
            <div class="tessa-widget-brand">
                <span class="tessa-widget-logo">T</span>
                <div>
                    <div class="tessa-widget-name">Tessa</div>
                    <div class="tessa-widget-status">AI Assistant</div>
                </div>
            </div>
            <button type="button" class="tessa-widget-close" id="tessaWidgetClose">&times;</button>
        </div>
        <div class="tessa-widget-messages" id="tessaWidgetMessages">
            <div class="tessa-widget-welcome">
                <span class="tessa-widget-welcome-icon">T</span>
                <p>Hi! I'm <strong>Tessa</strong>. I can help you create tasks, check pending work, or answer questions.</p>
            </div>
        </div>
        <div class="tessa-widget-chips">
            <button type="button" class="tessa-widget-chip" data-msg="Create a task">Create Task</button>
            <button type="button" class="tessa-widget-chip" data-msg="What are my pending tasks?">Pending Tasks</button>
            <button type="button" class="tessa-widget-chip" data-msg="What's on my agenda today?">Today's Agenda</button>
        </div>
        <div class="tessa-widget-input">
            <textarea id="tessaWidgetInput" rows="1" placeholder="Ask Tessa anything..."></textarea>
            <button type="button" class="tessa-widget-send" id="tessaWidgetSend">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
            </button>
        </div>
    </div>

    @if ($hasOrg)
    <script src="{{ asset('shared/org.js') }}?v={{ filemtime(public_path('shared/org.js')) }}"></script>
    @endif
    <script src="{{ asset('shared/meeting.js') }}?v={{ filemtime(public_path('shared/meeting.js')) }}"></script>
    <script>window.__PORTAL_CONFIG = @json($config);</script>
    @if (in_array('scripts', $features))
    <script src="{{ asset('js/scripts.js') }}?v={{ filemtime(public_path('js/scripts.js')) }}"></script>
    @endif
    @if (in_array('agile', $features))
    <script src="{{ asset('js/agile.js') }}?v={{ filemtime(public_path('js/agile.js')) }}"></script>
    @endif
    @if (in_array('tasks', $features))
    <script src="{{ asset('js/tasks.js') }}?v={{ filemtime(public_path('js/tasks.js')) }}"></script>
    <script src="{{ asset('js/tasks-popovers.js') }}?v={{ filemtime(public_path('js/tasks-popovers.js')) }}"></script>
    <script src="{{ asset('js/tasks-list.js') }}?v={{ filemtime(public_path('js/tasks-list.js')) }}"></script>
    @endif
    <script src="{{ asset('js/marketing.js') }}?v={{ filemtime(public_path('js/marketing.js')) }}"></script>
    <script src="{{ asset('js/finance.js') }}?v={{ filemtime(public_path('js/finance.js')) }}"></script>
    {{-- Legacy interactive sheet module (replaced by Google Sheet iframe). File is preserved at public/js/hima-revenue-sheet.js for re-activation. --}}
    @if (false && file_exists(public_path('js/hima-revenue-sheet.js')))
    <script src="{{ asset('js/hima-revenue-sheet.js') }}?v={{ filemtime(public_path('js/hima-revenue-sheet.js')) }}"></script>
    @endif
    <script src="{{ asset('js/tessa-chat.js') }}?v={{ filemtime(public_path('js/tessa-chat.js')) }}"></script>
    @if (!empty($config['jp_ai_mode']))
    <script src="{{ asset('js/jp-ai.js') }}?v={{ filemtime(public_path('js/jp-ai.js')) }}"></script>
    @endif
    @if (in_array('logs', $features))
    <script src="{{ asset('js/logs.js') }}?v={{ filemtime(public_path('js/logs.js')) }}"></script>
    @endif
    @if (in_array('claude_context', $features))
    <script src="{{ asset('js/claude-context.js') }}?v={{ filemtime(public_path('js/claude-context.js')) }}"></script>
    @endif
    @if (in_array('ai_expense', $features))
    <script src="{{ asset('js/ai-expense.js') }}?v={{ filemtime(public_path('js/ai-expense.js')) }}"></script>
    @endif
    @if (in_array('salary_tool', $features))
    <script src="{{ asset('js/salary-tool.js') }}?v={{ filemtime(public_path('js/salary-tool.js')) }}"></script>
    @endif
    <script src="{{ asset('js/hr-portal.js') }}?v={{ filemtime(public_path('js/hr-portal.js')) }}"></script>
    @if (in_array('letters', $features))
    <script src="{{ asset('js/letters.js') }}?v={{ filemtime(public_path('js/letters.js')) }}"></script>
    @endif
    @if (in_array('timesheets', $features))
    <script src="{{ asset('js/timesheet-assistant.js') }}?v={{ filemtime(public_path('js/timesheet-assistant.js')) }}"></script>
    <script src="{{ asset('js/timesheets.js') }}?v={{ filemtime(public_path('js/timesheets.js')) }}"></script>
    @endif
    @if (in_array('weeklyTimesheet', $features))
    <script src="{{ asset('js/weekly-timesheet.js') }}?v={{ filemtime(public_path('js/weekly-timesheet.js')) }}"></script>
    @endif
    @if (in_array('workforceAdmin', $features))
    <script src="{{ asset('js/workforce.js') }}?v={{ filemtime(public_path('js/workforce.js')) }}"></script>
    @endif
    @if (in_array('timesheetTracker', $features))
    <script src="{{ asset('js/timesheet-tracker.js') }}?v={{ filemtime(public_path('js/timesheet-tracker.js')) }}"></script>
    @endif
    @if (in_array('rewards', $features))
    <script src="{{ asset('js/rewards.js') }}?v={{ filemtime(public_path('js/rewards.js')) }}"></script>
    @endif
    @if (in_array('bills', $features))
    <script src="{{ asset('js/bills.js') }}?v={{ filemtime(public_path('js/bills.js')) }}"></script>
    @endif
    @if (in_array('hiring', $features))
    <script src="{{ asset('js/hiring.js') }}?v={{ filemtime(public_path('js/hiring.js')) }}"></script>
    @endif
    @if (in_array('calendar', $features))
    <script src="{{ asset('js/calendar.js') }}?v={{ filemtime(public_path('js/calendar.js')) }}"></script>
    @endif
    <script src="{{ asset('js/team-status-table.js') }}?v={{ filemtime(public_path('js/team-status-table.js')) }}"></script>
    <script src="{{ asset('js/portal.js') }}?v={{ filemtime(public_path('js/portal.js')) }}"></script>
    <script src="{{ asset('js/grammar-fix.js') }}?v={{ filemtime(public_path('js/grammar-fix.js')) }}"></script>
    <script>
    (function(){
        var HEARTBEAT_MS = 10 * 60 * 1000;
        var wasHidden = false;
        var pendingReload = false;

        // True while the user is mid-edit (a focused text field / editor). We never
        // reload out from under active input — that would lose unsaved typing.
        function isEditing(){
            var el = document.activeElement;
            if (!el) return false;
            var tag = (el.tagName || '').toLowerCase();
            return tag === 'input' || tag === 'textarea' || tag === 'select' || !!el.isContentEditable;
        }

        // Reload now if it's safe; otherwise defer until the user finishes editing.
        function reloadWhenIdle(){
            if (isEditing()) { pendingReload = true; return; }
            location.reload();
        }

        // When focus leaves an editable field, honour a deferred reload — but wait a
        // moment so the daily-report blur auto-save (and similar) can finish first.
        document.addEventListener('focusout', function(){
            if (!pendingReload) return;
            setTimeout(function(){
                if (pendingReload && !isEditing()) { pendingReload = false; location.reload(); }
            }, 1500);
        });

        // Refresh every time the user returns to the Tessa tab, so server-side changes
        // (sidebar tabs, projects, team data, deploys) show without a manual reload.
        // Gate on wasHidden so a page opened in a background tab isn't reloaded on its
        // first focus; visibilitychange never fires on initial load.
        document.addEventListener('visibilitychange', function(){
            if (document.visibilityState === 'hidden') { wasHidden = true; return; }
            if (wasHidden) { wasHidden = false; reloadWhenIdle(); }
        });

        // Session heartbeat (unchanged): reload to the login if the session is lost.
        setInterval(function(){
            fetch('/api/auth/session', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(r){ return r.ok ? r.json() : null; }).then(function(d){
                if (d && d.authenticated === false) { location.reload(); }
            }).catch(function(){});
        }, HEARTBEAT_MS);
    })();
    </script>
</body>
</html>
@endif
