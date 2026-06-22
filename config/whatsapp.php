<?php

return [
    // Meta WhatsApp Cloud API. Until these are set (Meta Business verification +
    // a verified sender number + an approved template — a multi-day external
    // task), WhatsAppService runs in DRY-RUN: it logs what it WOULD send and
    // returns false, so the rest of the Hiring flow ships and works now.
    'token' => env('WHATSAPP_TOKEN', ''),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', ''),
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),

    // Language code for template messages (must match the approved template).
    'language' => env('WHATSAPP_LANG', 'en'),

    // Default country code prepended to bare 10-digit Indian numbers.
    'default_country_code' => env('WHATSAPP_DEFAULT_CC', '91'),

    // Pre-approved message template names (created in Meta Business Manager).
    // jd_assigned body, e.g.:
    //   "New job description assigned: {{1}}. Open your Tessa portal: {{2}}"
    'templates' => [
        'jd_assigned' => env('WHATSAPP_TEMPLATE_JD_ASSIGNED', 'jd_assigned'),
    ],

    // Deep-link base for the recruiter portal (defaults to APP_URL).
    'portal_url' => env('WHATSAPP_PORTAL_URL', env('APP_URL', '')),
];
