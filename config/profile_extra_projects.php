<?php

/*
|--------------------------------------------------------------------------
| Profile-only project descriptors
|--------------------------------------------------------------------------
|
| Extra "project" labels appended to the Projects field on a user's profile
| (and the HR roster), shown after their real project assignments.
|
| These are DESCRIPTIVE ONLY. They are not rows in the `projects` table, are
| not agile projects, and never appear in any agile/task project picker. Use
| them to communicate work areas that aren't tracked as formal projects.
|
| Map: user id => array of label strings.
|
*/

return [
    // Fida Taneem (#41) — Lead AI Engineer; builds automations across Finance, HR & Ops.
    41 => ['Automations (Finance, HR and Ops)'],
];
