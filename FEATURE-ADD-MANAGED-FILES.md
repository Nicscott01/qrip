# Feature Addition: Managed File Destinations

## Summary

Add a native file-destination workflow to QRip so an administrator can select a file from the WordPress Media Library instead of copying and pasting its URL. The printed QR code must continue to encode only the durable first-party managed URL, `/go/{slug}`.

QRip should own the common, lightweight workflow. Download Monitor support may be added as an optional integration for sites that need file-version history, forced downloads, access controls, or download-specific reporting. It must not become a required dependency.

## Product goals

- Make linking a QR code to a PDF or other Media Library file simple for a non-technical administrator.
- Preserve the existing durable QR promise when a selected file changes.
- Store a WordPress attachment reference instead of copying its current URL.
- Keep existing URL-based QR records fully backward-compatible.
- Fail clearly and safely when a selected file is deleted or unavailable.
- Avoid duplicating document-management features that belong in Download Monitor.

## Non-goals

- Proxying or streaming file contents through QRip.
- Maintaining native file-version history inside QRip.
- Treating a QR scan as proof that a file download completed.
- Replacing the Media Library or Download Monitor.
- Adding access-control, email-gating, payment, or members-only features.
- Pinning a QR code to a historical Download Monitor version.

## Admin experience

Replace the single **Destination URL** input on the create/edit screen with a **Destination type** selector.

Initial destination types:

1. **Web address**
2. **Media Library file**

Add a third type, **Download Monitor download**, only when the optional integration is implemented and Download Monitor is active.

### Web address

Retain the current absolute HTTP/HTTPS URL field and validation. Show the UTM helper only for this destination type.

### Media Library file

Use the standard WordPress media frame. The interface should provide:

- **Select File** or **Replace File**
- Selected file name
- MIME type or friendly file type
- File size when available
- Upload date when available
- **View File**
- **Remove Selection**
- Explanatory text: “Replacing this destination file does not change the printed QR code.”

The workflow may select an existing attachment or upload a new one. Saving a different attachment is QRip's initial replacement workflow; it should not be labeled as file versioning.

If a separate file-replacement plugin replaces the underlying file while preserving the attachment ID, QRip should automatically resolve the attachment's current URL without special integration.

### QR Codes list

Make the Destination column describe the configured destination rather than always printing a raw URL. Suggested displays:

- **Website:** `example.com/resources`
- **PDF:** `product-guide.pdf`
- **Download:** `2026 Product Guide — current version`
- **Missing file:** a visible warning with an edit/replacement action
- **Unavailable integration:** a warning that Download Monitor is inactive

Search should continue to cover names, slugs, and URL destinations. Where practical, extend it to Media Library filenames and Download Monitor titles without making list performance fragile.

## Data model

Add prefixed post metadata:

- `_qrip_destination_type`: `url`, `media`, or `download_monitor`
- `_qrip_attachment_id`: positive WordPress attachment ID
- `_qrip_dlm_download_id`: positive Download Monitor download ID

Retain `_qrip_destination_url` for URL destinations and backward compatibility.

Existing records without `_qrip_destination_type` must behave as `url` records. Do not require a destructive or eager database migration.

When saving a record, validate only the fields required by the selected destination type. Clear or ignore stale destination values so they cannot unexpectedly affect routing after a type switch. Preserve compatibility with revisions where the current custom-post-type model permits it.

## Destination resolution

Introduce one internal destination resolver used by routing, admin displays, validation-adjacent checks, and tests. It should return either a valid absolute HTTP/HTTPS destination or a defined unavailable-destination error.

### URL resolution

- Use the stored `_qrip_destination_url`.
- Retain current absolute HTTP/HTTPS validation.
- Apply configured UTM fields only to URL destinations.

### Media resolution

- Confirm the stored ID identifies an attachment.
- Resolve its current URL at request time with WordPress attachment APIs.
- Confirm the resolved value is an absolute HTTP/HTTPS URL.
- Do not cache or persist a copied attachment URL as the source of truth.

### Download Monitor resolution

If implemented:

- Detect Download Monitor without fatals when it is absent or inactive.
- Store and resolve the Download Monitor download ID, not a copied managed URL.
- Use Download Monitor's public API/service layer to obtain its current managed download link; do not depend on undocumented database structure when an API is available.
- Link from QRip admin to the Download Monitor edit screen when possible.
- Treat a deleted download or inactive integration as unavailable.
- Do not create, replace, or delete Download Monitor versions in the initial integration.

## Request behavior

The QR payload remains unchanged:

`https://clientdomain.example/go/{slug}`

For an active QR record with a valid resolved destination:

- Increment QRip's aggregate scan count.
- Update its most recent scan time.
- Return the existing HTTP `302` redirect to the resolved destination.

QRip should redirect to the Media Library file URL or Download Monitor's managed link. It should not read, proxy, or stream the file.

This distinction must remain clear:

- QRip scan count means the managed QR URL was visited.
- It does not prove a file transfer completed.
- Download Monitor may separately count or control the actual download.

### Unavailable destinations

If a selected attachment, its underlying file URL, or a Download Monitor record/integration becomes unavailable:

- Do not fall back silently to a stale URL.
- Do not redirect.
- Return `410 Gone` with a simple “This file is no longer available” response.
- Send no-cache and no-index headers consistent with current managed-link behavior.
- Preserve the QR record and its slug so an administrator can choose a replacement.
- Display an actionable warning in the QR Codes list and edit screen.

A deliberately paused QR retains the existing paused `410 Gone` response and takes precedence over destination resolution. An unknown slug must continue through normal WordPress 404 behavior.

## Permissions and security

- Retain the existing `manage_options` requirement for QRip management unless the broader plugin permission model is intentionally changed separately.
- Require the appropriate WordPress upload/media capability before uploading or selecting attachments.
- Require nonces for every save or administrative action.
- Sanitize destination-type values and validate IDs as positive integers.
- Escape file metadata and URLs for their output contexts.
- Permit only resolved absolute HTTP/HTTPS URLs.
- Do not expose filesystem paths.
- Do not collect IP addresses, user agents, or new personal data.

## Caching and file behavior

- Preserve QRip's no-cache behavior on `/go/{slug}` so changing the selected file affects future scans promptly.
- Let the web server, WordPress, and browser handle the resolved file URL normally.
- Do not promise whether a file opens inline or downloads; that depends on file type and response headers.
- If Download Monitor is used, its force-download or redirect-to-file behavior remains Download Monitor's responsibility.

## Optional Download Monitor integration

Download Monitor is useful when a site needs a stable managed download record with versions, access rules, forced downloads, or separate download counts. QRip's own managed `/go/{slug}` URL already supplies the durability required for printed QR codes, so Download Monitor must remain optional.

Recommended first integration scope:

- Detect whether Download Monitor is active.
- Let the administrator search for and select an existing download.
- Store the selected download ID.
- Display the current download title and version/file summary when the public API makes those available.
- Resolve its current managed download URL dynamically.
- Provide **Edit in Download Monitor**.
- Gracefully return `410` and show an admin warning when the dependency or selected record is unavailable.

Do not initially add creation or version-management controls inside QRip. Those belong in Download Monitor's interface.

## Backward compatibility

- Existing URL destinations must behave exactly as before.
- Records that predate destination types are implicitly `url`.
- Managed URLs and generated SVG/PNG payloads must not change.
- Active records continue to use `302`.
- Paused records continue to use `410`.
- Unknown slugs continue to produce normal WordPress 404 handling.
- Existing UTM behavior remains intact for URL destinations and is unavailable for file destinations.

## Automated test plan

Add or expand tests for:

1. Existing records without a destination type resolving as URL records.
2. Saving and reading each supported destination type.
3. Rejecting unsupported destination types.
4. Rejecting nonexistent, non-attachment, or invalid attachment IDs.
5. Resolving the current attachment URL dynamically.
6. A changed attachment URL being used without changing the QR slug or QR payload.
7. Switching destination types without stale metadata affecting resolution.
8. Applying UTM fields only to URL destinations.
9. Missing/deleted attachments returning the defined unavailable error and ultimately `410`.
10. Paused status taking precedence over valid or invalid destination resolution.
11. Unknown slugs continuing to use WordPress 404 behavior.
12. Scan count and timestamp updates occurring only for valid active redirects.
13. Download Monitor active, inactive, missing-record, and valid-record conditions if that integration is included.
14. Media-frame scripts loading only on relevant QRip administration screens.
15. QR SVG and PNG continuing to encode the managed URL rather than any file URL.

## Manual verification plan

- Create a QR linked to an existing PDF.
- Upload and select a new PDF from the QR edit screen.
- Replace the destination with a different attachment and confirm the managed URL and QR artwork remain unchanged.
- Use a file-replacement plugin that preserves the attachment ID, if available, and confirm QRip resolves the new attachment URL.
- Delete the selected attachment and confirm the QR returns `410` and admin warnings appear.
- Restore a valid selection and confirm the QR works again.
- Switch among URL, Media Library, and optional Download Monitor destinations.
- Test active, paused, unavailable, and unknown-slug responses.
- Scan the generated QR from iPhone and Android devices.
- Confirm representative PDFs open or download according to the site's normal response headers.
- Confirm destination changes are not masked by page or edge caching.
- If Download Monitor support is present, change its current version and verify the QR reaches the new current download without changing QRip's managed URL.

## Recommended implementation sequence

1. Add destination-type constants, metadata, backward-compatible record hydration, and the central resolver.
2. Refactor redirect routing to use the resolver while preserving current status codes, headers, and analytics behavior.
3. Add the destination-type admin interface and WordPress media frame.
4. Add Media Library validation, unavailable-state warnings, and list-table presentation.
5. Expand automated coverage and run linting, static analysis, and the complete test suite.
6. Perform WordPress-admin, browser, cache, and phone-scanning checks.
7. Treat Download Monitor integration as a separate, optional phase unless it can be added cleanly without weakening or delaying the native workflow.

## Acceptance criteria

The native feature is complete when:

1. An administrator can choose **Media Library file**, select or upload a file, and save the QR record.
2. Opening the active managed URL returns a `302` to the attachment's current absolute HTTP/HTTPS URL.
3. Selecting a replacement attachment changes future redirects without changing the managed URL or QR image payload.
4. Existing URL records require no manual migration and retain their current behavior.
5. UTM controls are limited to URL destinations.
6. A deleted or unresolvable selected file produces a non-cached `410` response and an actionable admin warning.
7. Paused and unknown-slug behavior remains unchanged.
8. QRip does not proxy file contents or claim completed-download analytics.
9. Automated checks pass, and the remaining manual browser/phone checks are explicitly reported.

The optional Download Monitor integration is complete when an existing Download Monitor record can be selected and resolved safely, its current managed download link is used dynamically, and missing-plugin or missing-record states fail cleanly without breaking QRip or losing the QR record.
