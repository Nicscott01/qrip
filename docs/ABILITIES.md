# QRip abilities and MCP

WordPress 6.9+ provides the Abilities API. Favio can call QRip through `wp_get_ability( $name )->execute( $input )` in the logged-in user's context. Enable the MCP Adapter through Agent Connector for WP, the MCP Adapter plugin, or another plugin to use the dedicated QRip MCP endpoint. QRip's admin and redirect features also support WordPress 6.4–6.8.

## Permissions

| Capability | Default requirements |
|---|---|
| `qrip_manage_codes` | `manage_options` |
| `qrip_read_code` | `manage_options` and WordPress `read_post` for the QR record |
| `qrip_edit_code` | `manage_options` and WordPress `edit_post` for the QR record |

The object capabilities apply to QR records. An authorized manager receives a lookup error for a missing record. Listing requires `qrip_manage_codes` and returns records the caller can read. A create or update that specifies `destination_type: "media"` or `attachment_id` also requires `upload_files`. A supplied `attachment_id` requires `read_post` for that attachment.

To let a delegated role use the abilities and endpoint, grant that role the `qrip_delegated_operator` primitive capability in your site's role configuration and add a `map_meta_cap` filter in site code. This example preserves WordPress's per-record checks and the Media Library requirements.

```php
add_filter( 'map_meta_cap', static function ( $caps, $cap, $user_id ) {
	if ( in_array( $cap, array( 'qrip_manage_codes', 'qrip_read_code', 'qrip_edit_code' ), true ) && user_can( $user_id, 'qrip_delegated_operator' ) ) {
		$caps = array_map( static function ( $required ) {
			return 'manage_options' === $required ? 'qrip_delegated_operator' : $required;
		}, $caps );
	}
	return $caps;
}, 20, 3 );
```

## Ability inputs and results

Send JSON objects with the documented fields. WordPress validates input and output against each ability's schema. IDs start at 1. Slugs contain 1–100 lowercase letters, digits, and single separating hyphens. `name` has 1–200 characters, `notes` up to 5,000, and `destination_url` up to 2,048. A URL destination must be an absolute HTTP or HTTPS URL. `utm` accepts `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, and `utm_term`, each a string of up to 200 characters. `status` accepts `active` or `paused`. `destination_type` accepts `url` or `media`. `expected_version` is the 16-character hexadecimal `version` returned with a code.

| Ability | Input | Result | `readonly` / `destructive` / `idempotent` |
|---|---|---|---|
| `qrip/list-codes` | Optional `search`, `status`, `page`, `per_page`. Search up to 200 characters across names, slugs, configured URLs, and Media Library titles or filenames. Status defaults to `any`. Pages start at 1. `per_page` defaults to 20 and accepts 1–50. | `codes`, `total`, `page`, `per_page`, `total_pages`. Most recently modified codes appear first. | `true` / `false` / `true` |
| `qrip/get-code` | Exactly one of `id` or `slug`. | `{ code }` | `true` / `false` / `true` |
| `qrip/create-code` | Required `name`. Optional `slug`, `destination_type` (default `url`), `status` (default `active`), `notes`, `utm`, `dry_run`. Supply `destination_url` for `url` or `attachment_id` for `media`. QRip derives an omitted slug from the name. Supply an explicit slug if the derived slug exceeds 100 characters or is already taken. | Write result. Undo pauses the new code. | `false` / `false` / `false` |
| `qrip/update-code` | Required `id` and at least one of `name`, `status`, `notes`, `destination_type`, `destination_url`, `attachment_id`, `utm`. Optional `expected_version`, `dry_run`. Supply the corresponding URL or attachment ID when switching types. Omitted fields keep their current values. A partial `utm` updates only the supplied keys. Use `""` to clear a key. | Write result. Undo restores the previous destination and other editable values. | `false` / `true` / `true` |
| `qrip/change-slug` | Required `id`, `slug`. Optional `expected_version`, `dry_run`. | Write result. The old `/go/{slug}` returns a normal WordPress 404 while that slug is unassigned. Every printed or shared copy of the old code stops working. Undo requires the old slug to remain available. | `false` / `true` / `true` |
| `qrip/regenerate-images` | Required `id`. Optional `dry_run`. | Write result. Forces both files to render again. `changed` can be `true` with an empty `changes` array if the files were rewritten at the same URLs. A dry run reports the same `changed` and `changes` a real run would, and writes, deletes and creates no files. | `false` / `false` / `true` |

Annotations follow the MCP definitions the adapter maps them to (`readOnlyHint`, `destructiveHint`, `idempotentHint`): `destructive` means the call may overwrite existing state rather than only add to it. List and get leave the QR record intact and may generate missing image files. Create adds a record and claims its slug, so repeating it with the same slug returns a duplicate error. Update and slug changes overwrite editable state and are marked destructive. Applying the same editable state again is a no-op when any supplied version matches. Regenerating the same managed URL reproduces the same files. Permanent retirement is a human action in wp-admin. Retired records stay reserved and are excluded from ability results.

### Code object

| Fields | Meaning |
|---|---|
| `id`, `name`, `slug`, `managed_url`, `status` | Record identity, first-party `/go/{slug}` URL, and active or paused state. |
| `destination_type`, `destination_url`, `attachment_id` | Configured destination. Media uses `attachment_id` and a null `destination_url`. A URL uses `destination_url` without QRip's UTM additions and a null `attachment_id`. |
| `utm` | All five keys, with `""` for unset values or Media Library destinations. |
| `redirects_to`, `destination_available` | Resolved web address with UTM values applied where appropriate. An unavailable destination has a null `redirects_to`. A paused code can still have a configured and available destination. |
| `notes` | Internal notes as plain text. |
| `scan_count`, `last_scan_at` | Aggregate count and ISO 8601 UTC timestamp, or null timestamp before the first scan. |
| `created_at`, `updated_at` | ISO 8601 UTC timestamps. |
| `version`, `edit_url` | The first 16 hexadecimal characters of SHA-256 over the JSON-encoded editable state in fixed field order, and the WordPress edit link. Scans and image generation preserve this version. |
| `images`, `image_settings` | `images.svg` and `images.png` each contain `url`, `available`, `mime_type`, `width`, `height`, `bytes`, and `download_filename`. Settings expose `error_correction: "M"`, `size_px: 2048`, `quiet_zone_px: 96`, `foreground: "#000000"`, `background: "#ffffff"`, and `styling_supported: false`. |

The image files are public static assets under the site's uploads URL. They encode only the public managed URL and load without a WordPress session or nonce. Their filenames incorporate a hash of the encoded URL, render version, and format. Editing the name, destination, UTM values, notes, or status preserves the image URLs. A slug, site URL, or render version change produces new filenames the next time QRip materializes the cache. At that point QRip removes the stale files. Retirement removes the record's cached files. Unavailable images have `available: false`, `url: null`, and `bytes: null`.

### Write result and Favio workflow

Each write returns `dry_run`, `changed`, `changes`, `code`, and `restore`. `changes` contains `{ field, before, after }` entries for changed fields in order. UTM field names use `utm.<key>`. A null value means the field is absent, such as an attachment on a URL record or removed UTM values on a media record. Regeneration reports only changes to `images.svg.url` or `images.png.url`. A non-null `restore` includes `ability`, `input`, and a `description`.

1. Fetch the current `code.version` before an update or slug change. Preview the proposed call with `dry_run: true` and supply that version as `expected_version`. Create and regenerate take no `expected_version`: create has no existing record, and regeneration never changes the record. A create preview returns `code: null`. Other previews return the current code and its existing image URLs, so an image whose file is missing shows `url: null` in `code` and appears in `changes` with `before: null`. Previews leave the record and cache untouched: they don't write, delete or materialize image files, and they run the same permission checks as a real call.
2. Apply the same input with `dry_run: false` or omit `dry_run`. Create has no version precondition. `qrip_version_conflict` means the editable state changed since the supplied version. Fetch the record and review a new preview before retrying.
3. Journal the returned `changes` and `restore`. A successful write derives the change values from the persisted record. A no-op returns `changed: false`, an empty `changes` array, and a null `restore`.
4. Undo by executing `restore.ability` with `restore.input`. The restore includes the version after the original write. A create undo pauses the code, keeping its record and slug reserved. An update undo restores the complete prior editable state except the slug. A slug undo fails with `qrip_slug_duplicate` if another record has since claimed that slug. Restoration uses the same validation and permissions as any other ability call. Values entered in wp-admin that exceed the agent input limits remain visible in reads and may require a manual restore in wp-admin. Regeneration returns a null `restore` because the image cache is derived.

### Errors

| Error | Meaning |
|---|---|
| `qrip_invalid_lookup` | Supply exactly one of `id` or `slug`. |
| `qrip_not_found` | There is no matching QR record. |
| `qrip_code_retired` | The matching record was permanently retired. |
| `qrip_version_conflict` | The supplied `expected_version` differs from the current state. Error data contains `status: 409` and `current_version`. |
| `qrip_nothing_to_update` | Supply at least one editable field. |
| `qrip_name`, `qrip_slug`, `qrip_slug_duplicate` | The name is empty after sanitization, the slug is invalid, or the slug is already reserved by another record. |
| `qrip_destination_type`, `qrip_destination` | Use a supported destination type and supply an absolute HTTP(S) URL for a URL destination. |
| `qrip_attachment`, `qrip_attachment_unavailable` | Select a valid Media Library attachment with an available web address. |
| `qrip_destination_mismatch` | A destination field doesn't apply to the effective destination type: `attachment_id` on a `url` code, or `destination_url` or a non-empty UTM value on a `media` code. Set `destination_type` in the same call to switch types. |
| `qrip_record_not_found`, `qrip_slug_retired` | Core save validation detected a missing or retired record during a concurrent change. |

WordPress supplies schema validation, permission denial, and post write errors. An image cache failure is reported on the corresponding image entry, so callers still receive the code and can retry generation.

## Dedicated MCP server

Connect to `https://SITE/wp-json/qrip/v1/mcp`. Tool names use hyphens in place of `/`, for example `qrip-list-codes`. The server explicitly registers the six QRip tools and checks that the authenticated user has `qrip_manage_codes`. The abilities still check per-record and attachment permissions when called.

QRip sets `meta.mcp.public` to `false`. Agent Connector for WP's `Support\Governance` filter runs on `wp_register_ability_args` at priority 99 and replaces permission callbacks on MCP-public abilities with its super-admin check. Keeping QRip abilities private to the explicit server preserves the individual callbacks and excludes them from the default server's `mcp-adapter/execute-ability` meta-tool. When Agent Connector is installed and active, QRip defaults to disabling its server when `Config::can_boot()` is false and enforces `Config::domain_lock_ok()` at the transport. Sites can control registration with `qrip_mcp_server_enabled`.

For a client that uses a local STDIO proxy, configure `@automattic/mcp-wordpress-remote` with a WordPress application password for an authorized user.

```json
{
  "mcpServers": {
    "qrip": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote"],
      "env": {
        "WP_API_URL": "https://SITE/wp-json/qrip/v1/mcp",
        "WP_API_USERNAME": "WORDPRESS_USERNAME",
        "WP_API_PASSWORD": "APPLICATION_PASSWORD",
        "OAUTH_ENABLED": "false"
      }
    }
  }
}
```

A Streamable HTTP MCP client can connect directly to the same HTTPS URL and send HTTP Basic authentication with the WordPress username and application password.

### Exposure decision

QRip registers its own server instead of opting into Agent Connector's default server (`/wp-json/mcp/mcp-adapter-default-server`).

- **Per-object checks survive.** Opting in means `meta.mcp.public = true`, and Agent Connector's governance then replaces the permission callback with its super-admin check, discarding QRip's record, attachment and Media Library checks. Verified on a local Agent Connector 1.24.1 site: a probe ability with `mcp.public = true` comes back with `AgentConnectorForWp\Support\Config::has_admin_access`, while all six QRip abilities keep their `QRip_Abilities::can_*` callbacks.
- **No second path.** The default server's `mcp-adapter/execute-ability` refuses QRip abilities ("not exposed via MCP"), and its `tools/list` shows none of them. The QRip server is the only MCP route, and the abilities aren't exposed through the Abilities REST API (`show_in_rest: false`).
- **Works with any adapter host.** The server needs only an MCP Adapter instance firing `mcp_adapter_init`, whether Agent Connector, the MCP Adapter plugin or another bundling plugin (for example Gravity Forms ships one) boots it. QRip doesn't bundle the adapter.
- **Operator switches still apply.** With Agent Connector active, QRip doesn't register its server while `Config::can_boot()` is false (plugin switched off or production block on), and the transport refuses calls while the domain lock fails. Agent Connector's audit log and MCP event logging don't cover the QRip server.

## Favio integration notes

What Favio needs to add on its side (QRip needs no further changes for these):

1. Allow-list the six `qrip/*` abilities for sites where Creare lists QRip. Treat `list-codes` and `get-code` as reads. Send the other four through a review card and human Apply.
2. Build the review card from a `dry_run: true` call on all four write abilities, with no QRip-specific preview code. Pass `expected_version` to `update-code` and `change-slug`, then apply the identical input without `dry_run`, so a changed record fails with `qrip_version_conflict` instead of overwriting. `regenerate-images` accepts only `id` and `dry_run`.
3. Journal `changes` as before/after values and store `restore` for Undo. Undo executes `restore.ability` with `restore.input` as a normal non-readonly call, through Apply. Create's Undo pauses the code. `regenerate-images` has nothing to undo.
4. Use `get-code`'s `version` as the target fingerprint. Scans don't change it.
5. Pass `images.png.url` and `images.svg.url` to the person as links. They're public static files, so they open without a session.
6. By default only administrators (`manage_options`) can use QRip, so Editors using Favio won't get these tools unless the site grants the QRip capabilities (see Permissions).
7. Map QRip's `qrip_*` error codes to messages the model may see, in line with Favio's curated-error rule.
