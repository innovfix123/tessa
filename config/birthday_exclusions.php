<?php

return [
    // Employees whose birthday must never be surfaced or announced anywhere in
    // the portal — Slack eve-reminder, dashboard banner/confetti/calendar/upcoming
    // list, admin banner, and the HR directory "Birthday today" badge. Their
    // date_of_birth stays on record (HR view / My Profile); only the birthday
    // celebration + notification surfaces are suppressed.
    'user_ids' => [
        28, // Reshma — opted out of birthday notifications (2026-06-18)
    ],
];
