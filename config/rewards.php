<?php

return [
    // Who can VERIFY achievements and ASSIGN bonus amounts. JP only.
    'reviewers' => [1],

    // Reward Pool creators — managers who run an off-Tessa team performance
    // reward and log only the pool (title + description + amount) for the payer
    // to settle. No assignee / approval loop. Krishnan #20. See reward_pools.
    'pool_creators' => [20],

    // Who can mark withdrawals (and reward pools) as paid. Ayush primary; Shoyab
    // co-administers payouts (same as Bills); JP kept as backup.
    'payers' => [4, 32, 1],

    // User IDs that should receive the "heads-up: bonus approved" Slack DM
    // when JP assigns an amount, so they can plan the payout. Ayush + Shoyab.
    'approval_heads_up' => [4, 32],
];
