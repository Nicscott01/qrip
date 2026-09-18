# WordPress QR Redirect Manager — MVP Specification

## Goal

Build a self-contained WordPress plugin that lets a site owner create durable, print-ready QR codes whose destinations can be changed later in WordPress admin.

A printed QR code should always point to a URL on the client’s own domain. That URL performs a managed redirect to the current destination. This removes dependency on third-party QR-code subscriptions and preserves control of printed materials.

Initial use case: create and manage QR codes for Zenas campaign and event materials.

## Product principles

- Own the QR URLs: codes resolve through the client’s WordPress domain.
- Avoid external QR services at runtime.
- Make printed output reliable: high-resolution SVG and PNG downloads, with quiet-zone protection.
- Keep the admin experience simple for non-technical users.
- Preserve history and basic scan reporting without overbuilding analytics in V1.
- Make redirects fast, safe, cache-friendly, and compatible with common WordPress hosting.

## MVP user stories

As a WordPress administrator, I can:

1. Create a named QR code.
2. Choose a short, readable URL path such as `/go/mstoronto`.
3. Set its destination URL.
4. Download a print-ready SVG and PNG of the QR code.
5. Copy the managed QR URL.
6. Change the destination later without changing or reprinting the QR code.
7. Pause a code when it should no longer redirect.
8. See a basic scan count and most recent scan time.
9. Review when a code was created or last edited.

## Core behavior

### Managed redirect URL

Each QR code represents a first-party URL:

`https://clientdomain.com/go/{slug}`

When visited:

- If active, log the visit and return a `302 Found` redirect to the configured destination.
- If paused, show a simple branded or unbranded “This link is no longer active” page with a `410 Gone` response.
- If the slug does not exist, allow normal WordPress 404 handling.

Use `302` as the default because destinations are intentionally editable. Do not use permanent redirects by default.

### QR-code content

The encoded value must always be the managed first-party URL, never the destination URL.

Example:

- QR code contains: `https://zenas.com/go/mstoronto-2026`
- Initial destination: `https://example.com/congress/meeting-request`
- Later destination: `https://example.com/resources/congress-recap`

The printed QR code remains valid after the destination changes.

## Admin experience

Add a top-level WordPress admin menu item: **QR Codes**.

### QR code list

Columns:

- Name
- Managed URL
- Destination
- Status: Active or Paused
- Scans
- Last scan
- Updated
- Actions: Edit, Copy URL, Download SVG, Download PNG, Pause or Activate

Provide search by name, slug, and destination URL.

### Permanent retirement

Existing records have a collapsed **Danger zone** on the edit screen; new records and list-table rows do not expose deletion controls. The retirement form is separate from the update form and shows the record name, managed URL, scan count, and most recent scan when available. It warns that printed and shared copies cannot be recalled, recommends pausing when the administrator is uncertain, and requires the exact uppercase confirmation `DELETE`.

Retirement permanently reserves the slug and converts the record into a minimal tombstone. The managed URL returns a non-cached `410 Gone` response without incrementing scans, while unknown slugs continue through the normal WordPress 404 path. The tombstone retains the original post ID, slug, deleted status, deletion time, and deleting user; destination, attachment reference, notes, UTM values, scan data, creator/editor metadata, and other campaign metadata are removed. The underlying Media Library attachment is never deleted, and rewrite rules are not flushed as part of retirement. Retired records are excluded from the normal list and search results and cannot be edited, restored, or reused.

### Create/edit screen

Fields:

- **Name** — required internal label, e.g. “MSToronto 2026 Booth”
- **Slug** — required, editable, URL-safe; automatically generated from name
- **Destination URL** — required valid absolute `http` or `https` URL
- **Status** — Active or Paused
- **Notes** — optional internal campaign/context notes
- **UTM helper** — optional collapsed section that can append or edit `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, and `utm_term`

Show a live preview panel containing:

- The QR code preview
- Managed URL
- Download SVG button
- Download PNG button
- Copy URL button
- Print guidance: “Use SVG whenever possible. Do not crop the white border.”

### Zenas initial setup

Create a short setup guide in the plugin readme/admin help:

1. Create one code per distinct printed placement or campaign.
2. Use descriptive names, such as:
   - MSToronto 2026 — Booth
   - MSToronto 2026 — Presentation Slide
   - MSToronto 2026 — Leave-Behind Card
3. Use separate QR codes when attribution by placement matters.
4. Test each code on a real phone before sending artwork to print.
5. Keep the physical code at least 0.8 inches / 20 mm wide for ordinary print use; use larger sizes for signs or distance scanning.

## Data model

Use a custom post type or a dedicated custom database table. For MVP, prefer a custom post type named `qr_redirect` because it integrates with WordPress capabilities, admin patterns, and revision handling.

Store metadata:

- `qr_slug`
- `destination_url`
- `status`
- `notes`
- `scan_count`
- `last_scan_at`
- `created_by`
- `updated_by`
- Optional UTM fields

Do not create public post pages for this post type.

## Technical requirements

### Plugin architecture

- Plugin name: `First-Party QR Redirects` (working name)
- Prefix all PHP functions, hooks, options, metadata, CSS classes, REST endpoints, and database keys.
- Require supported current WordPress and PHP versions defined in the plugin readme.
- Follow WordPress coding standards.
- Use nonces, capability checks, output escaping, and URL validation throughout.
- Restrict administration to users with `manage_options` by default.
- Keep the plugin independent of a theme or page builder.

### Routing

- Register a rewrite rule for `/go/{slug}`.
- Resolve only registered, valid QR slugs.
- Flush rewrite rules only on activation/deactivation—not on every request.
- Use a canonical generated URL based on `home_url()` rather than manually entered site domains.
- Ensure the redirect happens before page rendering.
- Exclude redirect URLs from caching where needed, while allowing normal site caching to remain intact.

### Destination validation and security

- Permit only absolute `http` and `https` URLs.
- Reject malformed, unsafe, and protocol-relative URLs.
- Do not permit `javascript:`, `data:`, `file:`, or other non-web protocols.
- Require a unique slug.
- Log only privacy-minimized aggregate data in MVP: count and timestamp. Do not store IP addresses, user agents, or personal data.

### QR generation

- Bundle or Composer-manage a mature PHP QR-code library.
- Generate QR codes server-side with error correction level **M** by default.
- Include an adequate white quiet zone around every image.
- Provide:
  - SVG for vector-quality print production
  - PNG at 2048 × 2048 pixels or equivalent high-resolution output
- Downloaded filenames should be sanitized and descriptive, e.g. `zenas-mstoronto-2026-booth-qr.svg`.

## Explicit non-goals for MVP

- Branded/logo-overlaid QR codes
- Dynamic styling/colors beyond black on white
- Geo-location, device, or referrer analytics
- CSV export
- Bulk creation/import
- QR-code expiration scheduling
- A public REST API
- Multi-domain routing
- Consent-management integration
- Click fraud/bot filtering
- White-label SaaS multi-tenancy

These may be added after the Zenas launch workflow is proven.

## Acceptance criteria

The MVP is complete when:

1. An administrator can create a code with a name, slug, and destination.
2. The plugin produces a scannable SVG and PNG containing the managed site URL.
3. Scanning or opening `/go/{slug}` redirects to the configured destination with HTTP 302.
4. Updating the destination changes future redirects without changing the QR image.
5. Pausing a code returns HTTP 410 and does not redirect.
6. Invalid or duplicate slugs cannot be saved.
7. Unsafe destination protocols cannot be saved.
8. A valid redirect increments the aggregate scan count and updates the last-scan timestamp.
9. Downloads have a usable quiet zone and scan successfully from a phone after printing.
10. The plugin activates, deactivates, and uninstalls cleanly without breaking normal WordPress permalinks.
11. The initial Zenas codes are created, tested on mobile devices, and handed to the design/print workflow as SVG files.

## Recommended build sequence

1. Scaffold plugin, CPT, capabilities, and activation hooks.
2. Implement QR record CRUD and admin screens.
3. Add rewrite routing and redirect behavior.
4. Integrate SVG/PNG QR generation and protected downloads.
5. Add basic aggregate scan tracking.
6. Add validation, permissions, unit/integration tests, and a manual mobile test checklist.
7. Create the Zenas campaign codes and validate the actual printed/exported assets.

## Open decisions before implementation

- Confirm the primary client domain that will host the Zenas QR paths.
- Confirm whether Zenas needs a logo inside codes; this should remain out of MVP unless essential, because it adds print/scannability risk.
- Confirm whether scan counts are sufficient or whether campaign-level export/reporting is needed for the first release.
- Choose the production plugin name and slug.
