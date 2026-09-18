# Safe QR Code Deletion Plan

## Goal

Allow administrators to remove test and unwanted QR codes from the normal QRip view without making it easy to break a deployed printed code or accidentally reuse its managed URL.

Deletion should be deliberately harder than pausing. It should be available only from the individual QR code's edit screen, clearly explain the consequences, and require typed confirmation.

## Recommended product behavior

Treat deletion as a permanent retirement of the QR code, not as ordinary WordPress trashing and not as complete erasure of every trace of the slug.

When a QR code is deleted:

- Remove it immediately from the normal QR code list.
- Stop redirecting its managed URL.
- Return `410 Gone` for its former `/go/{slug}` URL.
- Remove its destination, notes, UTM values, scan count, and other editable campaign data.
- Retain only a minimal tombstone containing the slug and deletion audit fields needed to keep that URL retired.
- Reserve the deleted slug permanently so it cannot later be assigned to a different QR code.
- Do not delete a Media Library attachment used as the destination. QRip owns the redirect record, not the attachment.

This approach keeps the main view uncluttered while honoring QRip's core promise that a printed first-party URL will never be silently repurposed.

## Edit-screen experience

Add a collapsed **Danger zone** section near the bottom of an existing QR code's edit screen. Do not show it while creating a new code, and do not add Delete to list-table row actions.

The section should contain a destructive **Delete QR Code...** button. Selecting it expands an inline confirmation panel or opens a WordPress-style modal showing:

- The QR code name.
- Its managed URL.
- Its scan count and most recent scan time, if any.
- A clear warning: deleting does not recall printed or shared copies of this QR code.
- The result: the managed URL will stop redirecting and show an inactive-link response.
- The alternative: use **Pause** when the code may be needed again or when the administrator is unsure whether it has been deployed.
- A statement that this action cannot be undone.

Require the administrator to type the exact uppercase word `DELETE`. Keep the final **Permanently Delete QR Code** button disabled until the entry matches exactly. Do not use a browser `confirm()` dialog as the only safeguard.

Suggested warning copy:

> Permanently delete this QR code only if you are certain it was never deployed or is no longer needed. Printed and shared copies cannot be recalled. After deletion, this managed URL will stop redirecting and cannot be reused. If you may need this code again, pause it instead.

After a successful deletion, return to the QR code list and show a dismissible success notice naming the deleted code and confirming that its managed URL has been retired.

## Server-side safeguards

The server must enforce every important rule even if JavaScript is disabled or bypassed:

1. Accept deletion only through `POST`.
2. Require `manage_options`.
3. Require a record-specific nonce, such as `qrip_delete_{id}`.
4. Confirm that the submitted ID belongs to a `qr_redirect` record.
5. Require the submitted confirmation value to equal `DELETE` exactly after unslashing; do not silently normalize case.
6. Re-read the record immediately before deleting so the action uses the current slug and metadata.
7. Convert the record to a minimal deleted tombstone in one bounded operation. If that operation fails, leave the original record available and show an error rather than reporting success.
8. Record deletion time and deleting user ID for basic accountability.
9. Never accept a deletion through a URL or reusable list-row link.

The existing `admin_post_qrip_delete` handler currently hard-deletes a record after only the general capability and nonce checks. Replace that behavior with the confirmed retirement flow above before exposing any delete interface.

## Data model and routing

Recommended tombstone fields:

- Original post ID
- `_qrip_slug`
- `_qrip_status = deleted`
- `_qrip_deleted_at`
- `_qrip_deleted_by`

On retirement, clear the title or replace it with a neutral label, and delete destination, attachment reference, notes, UTM, scan, and creator/editor metadata that is no longer needed.

Update QR lookup and routing so states remain explicit:

- Active record: resolve destination, count the scan, and return `302`.
- Paused record: return `410` with the existing inactive-link response.
- Deleted tombstone: return `410` with the same neutral inactive-link response and do not count a scan.
- Unknown slug: preserve normal WordPress `404` behavior.

Slug uniqueness checks must include deleted tombstones. A deleted slug must not become available for reuse.

The normal list query should exclude deleted tombstones. A separate deleted-items screen is not necessary for the first version; the minimal audit data can remain accessible through database or WP-CLI support workflows. Restoration should be explicitly out of scope because the confirmation promises that deletion is permanent.

## Error and edge-case behavior

- Missing or invalid confirmation: return to the edit screen with a clear error and make no changes.
- Stale or invalid nonce: show the standard protected-action failure and make no changes.
- Missing record or wrong post type: return a 404-style admin error and do not redirect with a false success notice.
- Repeated submission after successful deletion: treat the record as already deleted and avoid changing other data.
- Active record with scans: allow deletion, but make the scan history and deployment warning prominent; do not guess that scan count proves or disproves physical deployment.
- Zero-scan record: show the same typed confirmation. A zero count does not prove the code was never printed.
- Media destination: retire only the QR record; never delete the underlying attachment or file.
- Caching: send the same no-cache behavior used by paused routes so a retired URL is not served as a stale redirect.

## Implementation outline

1. Add deleted-state constants and deletion audit metadata to `QRip_Core`.
2. Add a core retirement method that validates the record, preserves the slug, removes non-tombstone metadata, and returns either success or `WP_Error`.
3. Change `QRip_Admin::delete()` to require the record-specific nonce and exact typed confirmation, call the core retirement method, and handle success and failure honestly.
4. Add the edit-screen Danger zone form as a separate form from the normal Update form so saving and deleting cannot be confused.
5. Add small scoped JavaScript to enable the final button only when `DELETE` matches, while retaining full server-side validation.
6. Add scoped admin styling consistent with WordPress destructive-action patterns.
7. Exclude deleted tombstones from the normal listing and search results.
8. Update redirect routing and slug uniqueness behavior for deleted tombstones.
9. Add the deletion behavior and safety warning to the README and product specification.

## Automated verification

Add tests covering:

- The Danger zone appears only for an existing record.
- Unauthorized deletion is rejected.
- An invalid nonce is rejected.
- Missing, lowercase, or otherwise incorrect confirmation is rejected.
- A valid confirmed deletion creates the minimal tombstone and removes destination/campaign metadata.
- A deleted record disappears from normal listing and search results.
- A deleted slug returns `410`, does not redirect, and does not increment scans.
- An unknown slug still produces the normal `404` path.
- A deleted slug remains unavailable when creating or editing another record.
- Deleting a media-backed QR code does not delete its WordPress attachment.
- A repeated deletion request is harmless and cannot affect another record.
- The success notice is shown only after a confirmed successful retirement.

Run the repository's PHPUnit suite, PHP syntax checks, PHPStan with its project configuration and required memory limit, and `git diff --check`. Do not describe the existing PHPCS baseline as passing unless its current violations have separately been resolved.

## Manual acceptance checks

In a local WordPress admin:

1. Create a disposable URL-backed QR code and confirm no Delete control appears on the Add screen.
2. Open its Edit screen and confirm Delete is visually separated from Update.
3. Confirm the final button remains disabled until `DELETE` is typed exactly.
4. Try an incorrect confirmation without JavaScript and confirm the server refuses it.
5. Delete the test code and confirm it disappears from the normal list.
6. Visit its managed URL and confirm a non-cached `410` inactive response.
7. Try to create a new code with the retired slug and confirm it is rejected.
8. Repeat with a media-backed QR code and confirm the Media Library item remains intact.
9. Confirm active, paused, and unknown QR URLs still behave as `302`, `410`, and `404` respectively.

## Acceptance criteria

The feature is ready when an administrator can intentionally remove a test QR code from the working list, accidental or forged deletion attempts are blocked, old managed URLs fail safely with `410`, deleted slugs can never point somewhere new, destination files are untouched, and existing active/paused/unknown routing behavior remains unchanged.
