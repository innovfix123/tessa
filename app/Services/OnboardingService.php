<?php

namespace App\Services;

use App\Models\User;

/**
 * New-hire onboarding completion (stage 9). A hire is "onboarded" once they've
 * filled the required profile fields and uploaded the 5 mandatory documents
 * (the same KEY_DOCS the HR Team view tracks). Drives the onboarding lock gate
 * and the "Finish onboarding" check.
 */
class OnboardingService
{
    /** Required profile fields the hire must fill (column => label). */
    public const REQUIRED_FIELDS = [
        'personal_mobile' => 'Mobile number',
        'personal_email' => 'Personal email',
        'emergency_contact_name' => 'Emergency contact name',
        'emergency_contact_number' => 'Emergency contact number',
    ];

    /** The 5 mandatory documents (mirrors EmployeeController::KEY_DOCS). */
    public const KEY_DOCS = [
        'aadhar_front_path' => 'Aadhaar (front)',
        'pan_path' => 'PAN card',
        'passport_photo_path' => 'Photo',
        'signed_offer_letter_path' => 'Signed offer letter',
        'nda_path' => 'NDA',
    ];

    /** @return array{fields:array,docs:array,complete:bool} */
    public function status(User $user): array
    {
        $fields = [];
        foreach (self::REQUIRED_FIELDS as $key => $label) {
            $fields[] = ['key' => $key, 'label' => $label, 'done' => trim((string) $user->{$key}) !== ''];
        }
        $docs = [];
        foreach (self::KEY_DOCS as $key => $label) {
            // A failed upload stores the '0' sentinel (falsy) → still "not uploaded".
            $docs[] = ['key' => $key, 'label' => $label, 'done' => (bool) $user->{$key}];
        }
        $complete = ! collect(array_merge($fields, $docs))->contains(fn ($i) => ! $i['done']);

        return ['fields' => $fields, 'docs' => $docs, 'complete' => $complete];
    }

    public function isComplete(User $user): bool
    {
        return $this->status($user)['complete'];
    }
}
