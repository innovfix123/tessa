<?php

/*
|--------------------------------------------------------------------------
| KRA Composite Weights
|--------------------------------------------------------------------------
|
| Three KRA buckets: discipline, deliverables, manager_review.
|   - discipline:     computed from sign-in/off, daily reports, meeting notes, task check-ins
|   - deliverables:   manager's weekly Deliverables star rating
|   - manager_review: manager's weekly Quality of Work star rating
|
| Each person's monthly/weekly composite is a weighted average of the three.
| If a bucket returns null (e.g. no manager ratings yet), its weight is
| filled with a neutral baseline by `compositeFor()`.
|
| Weights must sum to ~1.0 per role.
|
*/

return [
    'default' => [
        'discipline'     => 0.34,
        'deliverables'   => 0.33,
        'manager_review' => 0.33,
    ],

    'tech_lead' => [
        'discipline'     => 0.34,
        'deliverables'   => 0.33,
        'manager_review' => 0.33,
    ],

    'full_stack_developer' => [
        'discipline'     => 0.34,
        'deliverables'   => 0.33,
        'manager_review' => 0.33,
    ],

    'gen_ai_developer' => [
        'discipline'     => 0.34,
        'deliverables'   => 0.33,
        'manager_review' => 0.33,
    ],

    // Clone of gen_ai_developer — Fida's "Lead AI Engineer" keeps identical
    // KRA weighting after the role rename.
    'lead_ai_engineer' => [
        'discipline'     => 0.34,
        'deliverables'   => 0.33,
        'manager_review' => 0.33,
    ],

    'qa_analyst' => [
        'discipline'     => 0.34,
        'deliverables'   => 0.33,
        'manager_review' => 0.33,
    ],

    'cmo' => [
        'discipline'     => 0.34,
        'deliverables'   => 0.33,
        'manager_review' => 0.33,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fairness tuning
    |--------------------------------------------------------------------------
    */
    'null_bucket_baseline'       => 3.0,
    'signoff_discipline_weight'  => 0.5,

    // One-time flat penalty per overdue item (sprint/story/bug/task),
    // regardless of how many days it is/was late. Applied once if the item
    // was overdue on any evaluation day inside the scoring window — whether
    // it is still open or was eventually completed late.
    'overdue_penalty_per_item'   => 0.20,
];
