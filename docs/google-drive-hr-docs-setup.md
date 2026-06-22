# Google Drive — HR Documents sync + embed (setup)

The Tessa → Drive document mirroring (per-person folder under the master HR folder, files named
`First_Last_doctype.ext`, embedded in **HR → Employee Documents**) is fully built but **DORMANT**
until a Google service account is provisioned. Every entry point no-ops safely until then —
uploads still work locally and the cards show a muted "No Drive folder yet" note.

## What it does once enabled
- On every document upload (self-service *My Documents* **or** HR *Employee Documents*), the file
  is mirrored — after the HTTP response (non-blocking) — to Google Drive:
  - master folder: `https://drive.google.com/drive/folders/1NLGmXCNZwPB2-Mt-OY9PSGLTzvcs75H2`
  - a per-person subfolder named after the employee (e.g. `Arjun Kumar`)
  - file named like `Arjun_Kumar_aadhar_front.pdf`
  - the subfolder is shared **read-only** with the emails in `GOOGLE_HR_SHARE_EMAILS`
- The per-person folder id is saved to `users.google_drive_folder_id`; the Employee Documents card
  then shows **Open in Drive** + a lazy **embedded folder** view next to the tiles.

## One-time provisioning (a Google Cloud action)
1. **Service account.** In Google Cloud Console: create/reuse a project, enable the **Google Drive
   API** (+ **Sheets API** if using the HR-sheet sync), create a **service account**, and create a
   **JSON key**.
2. **Install the key** at `storage/app/google/service-account.json`, then
   `chown www-data:www-data storage/app/google/service-account.json && chmod 600` it.
3. **Share the targets with the service account email** (the `client_email` field in the JSON):
   - master Drive folder `1NLGmXCNZwPB2-Mt-OY9PSGLTzvcs75H2` → **Editor**
   - HR sheet `1aAyyJe_SsHs88pqJLYF6K1AIRy4FScovtXyXASN-A4U` → **Editor** (only if using sheet sync)
4. **.env** — config already defaults the folder/sheet ids; you mainly need the share list:
   ```
   GOOGLE_SERVICE_ACCOUNT_JSON=/var/www/Tessa/storage/app/google/service-account.json
   GOOGLE_HR_DRIVE_FOLDER_ID=1NLGmXCNZwPB2-Mt-OY9PSGLTzvcs75H2
   GOOGLE_HR_SHARE_EMAILS=meghana@innovfix.in,akshara@innovfix.in
   ```
   Then `chown www-data:www-data .env` and `bin/refresh-routes.sh`.
5. **Backfill** existing documents into Drive:
   ```
   php artisan drive:sync-hr-docs            # everyone
   php artisan drive:sync-hr-docs --user=45  # one person
   ```

## Embedded-view access note
`embeddedfolderview` renders using the **viewer's own Google session**. Since folders are shared
only with `GOOGLE_HR_SHARE_EMAILS`, an HR person must be signed into Google as one of those emails
for the embed to show files; otherwise it shows "request access" (the **Open in Drive** link still
works for anyone with access). The Employee Documents page is already HR-role-gated inside Tessa.

## Kill switch
Remove/rename `storage/app/google/service-account.json` (or unset `GOOGLE_SERVICE_ACCOUNT_JSON`) →
`GoogleDriveService::isConfigured()` is false → all uploads/sync silently no-op. Local files,
tiles, and the "X/Y uploaded" status are unaffected.
