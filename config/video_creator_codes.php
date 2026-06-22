<?php

/**
 * Video handoff naming — per-creator language + editor code.
 *
 * Drives the standardized filename a reworked video gets once Anaz (#18)
 * approves it: {lang}_{NNN}_W{isoWeek}_{editor}, e.g. TA_001_W25_KI. The
 * orientation letter (V/S/H) is appended per crop at download time. See
 * App\Services\VideoHandoffNaming.
 *
 * The sequence counter is per *language* (the `lang` value), NOT per creator,
 * so the two Telugu creators (Disha + Haripriya) share one TE counter on a
 * first-come, first-serve basis. Single-creator languages trivially get their
 * own counter.
 *
 * Sivaranjani's Only Care videos are a special case: `oc => true` and no
 * `editor` code, named OC_TA_{NNN}_W{week} (orientation still appended on
 * download). Her `lang` is the standalone bucket `OC_TA`, so her counter stays
 * independent of Kishore's regular Tamil (TA) sequence.
 *
 * Keyed by users.id. A creator absent from this map simply gets no auto-name
 * (the deliverable keeps Anaz's uploaded filename).
 */
return [
    51 => ['lang' => 'TA',    'editor' => 'KI'], // Kishore Prabakaran — Tamil
    40 => ['lang' => 'TE',    'editor' => 'DI'], // Disha — Telugu
    49 => ['lang' => 'TE',    'editor' => 'HP'], // Haripriya — Telugu (shares the TE counter with Disha)
    56 => ['lang' => 'KA',    'editor' => 'NY'], // Y Nehal — Kannada
    52 => ['lang' => 'ML',    'editor' => 'FA'], // Fathima K P — Malayalam
    21 => ['lang' => 'HI',    'editor' => 'TY'], // Tiyasa — Hindi
    58 => ['lang' => 'OC_TA', 'oc' => true],     // Sivaranjani N — Only Care (Tamil); no editor code, own counter
];
