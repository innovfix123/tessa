# Employee Date of Birth — capture procedure

Birthdays in Tessa (the **Holidays & Birthdays** calendar, the dashboard birthday card, and
the `birthdays:send-reminders` job) all read `users.date_of_birth`. The birthday match is on
**day + month only** (`format('m-d')`), so the year is for records/age — it does not affect
whether a birthday shows.

**Policy:** keep DOBs filled for **active employees only**. Skip ex-employees (inactive) and
the `Admin` system account (id 33, not a person).

## Three ways a DOB gets set

1. **Onboarding (preferred for new hires).** The **Team → Add Member** form has a **Date of
   Birth** field. HR fills it when creating the employee → `EmployeeController::handleCreate`
   (validated `nullable|date|before:today`, saved into the new user).
2. **Self-service.** Every employee can set their own under **My Profile → Personal Details →
   Date of Birth** (Save Personal Details) → `EmployeeController::profileStore`.
3. **HR backfill / document extraction (for anyone missing one).** **Team → Edit Details** has
   a **Date of Birth** field (pre-filled from the record) → `EmployeeController::handleUpdate`.
   HR enters the date here after reading it from the employee's uploaded ID document.

## Finding who's missing a DOB (active only)

```bash
php artisan tinker --execute='
foreach(App\Models\User::whereNull("date_of_birth")->where("is_active",true)
        ->where("id","!=",33)->orderBy("name")->get() as $u){
  echo $u->id." ".$u->name." (".$u->role.")\n";
}'
```

## Extracting DOB from uploaded documents

When a DOB is missing and the employee won't fill it, pull it from their uploaded ID docs.
DOB-bearing fields on the user record: `aadhar_front_path`, `pan_path`, `tenth_marksheet_path`,
`form_11_path`, `esic_intern_decl_path`. Files live on the public disk at
`storage/app/public/<path>`.

Guidance:
- Prefer the **clearest source**. A 10th-marksheet spells the date out in words and is the most
  reliable; PAN/Aadhaar scans are often rotated/low-res — do **not** commit a DOB you can't read
  with confidence. Cross-check a blurry scan against another document.
- **Verify the name** on the document matches the employee before trusting the date.
- Indian documents use **DD/MM/YYYY**. Store as `YYYY-MM-DD`.
- Extract **only the date of birth** — do not store/transcribe Aadhaar/PAN numbers.
- Then set it via **Team → Edit Details** (preferred, leaves an activity-log trail) or, for a
  bulk backfill, directly:
  ```php
  $u = App\Models\User::find(<id>); $u->date_of_birth = '<YYYY-MM-DD>'; $u->save();
  ```

## History
- 2026-06-01: Bhoomika (#60) backfilled `2000-06-04` from her Aadhaar (her June 4 birthday was
  missing). Add-Member and Edit-Details DOB fields added the same day so new hires and backfills
  don't depend on self-service. Ex-employees intentionally left blank.
