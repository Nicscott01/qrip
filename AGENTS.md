# QR Code Host — Agent Instructions

## Project location and repository boundary

- The editable project lives at `/Users/nscott/web_repos/localdev/web/app/plugins/qrcodehost`.
- Work from that directory when reading, editing, testing, or running project-specific commands.
- This plugin is installed inside the local Bedrock site at `/Users/nscott/web_repos/localdev`, but it is intentionally excluded by the Bedrock repository's `web/app/plugins/*` rule in `/Users/nscott/web_repos/localdev/.gitignore`.
- Because the plugin is ignored by the parent Bedrock repository, this non-`-dev` directory is the editable source and is exempt from the usual requirement to work in a sibling directory suffixed with `-dev`.
- Do not make QR Code Host changes in the parent Bedrock repository or a Composer-managed copy. Confirm the working directory resolves to the path above before modifying project files.
- This directory is its own Git repository, with `origin` at `git@github.com:Nicscott01/qrip.git`. The parent Bedrock Git status does not track this project; run Git commands from this directory and confirm its project-local repository root before committing.

## How to work on this project

1. Start in the editable project directory above and read this file plus [`QR-REDIRECT-MANAGER-SPEC.md`](QR-REDIRECT-MANAGER-SPEC.md) before changing product behavior.
2. Inspect the current files and preserve any existing or uncommitted work. Keep changes focused on the request.
3. Build and test the plugin in the surrounding local Bedrock/WordPress installation, while keeping plugin source files inside this directory.
4. Use project-local dependency and test commands once manifests and tooling exist. Do not run a broad Composer update in the parent Bedrock site as part of ordinary plugin work.
5. Verify changes against the MVP behavior and the checklist below. Clearly separate automated checks from WordPress-admin, browser, print, and phone-scanning checks that remain manual.
6. Commit and push through this directory's project-local Git repository and its `origin` remote. Never commit ignored plugin source through the parent `localdev` repository.

## Project purpose

This repository builds a reusable WordPress plugin for first-party, editable QR-code redirects. The full MVP requirements are in [`QR-REDIRECT-MANAGER-SPEC.md`](QR-REDIRECT-MANAGER-SPEC.md); read that file before implementing or changing product behavior.

The primary promise is durable print QR codes: the encoded URL must be hosted on the customer’s WordPress domain and may redirect to an editable destination later.

## Non-negotiable behavior

- Encode the managed first-party URL (`/go/{slug}`), never the final destination URL.
- Active codes must use an HTTP `302` redirect by default, because destinations may change.
- Paused codes must not redirect; return `410 Gone` with a simple inactive-link response.
- Unknown QR slugs must preserve normal WordPress 404 behavior.
- Permit destination URLs only when they are absolute `http` or `https` URLs.
- Do not collect IP addresses, user agents, or other personal data for MVP analytics.
- Generate print-ready SVG and high-resolution PNG output with an adequate white quiet zone.

## WordPress implementation guidelines

- Use WordPress coding standards and a unique plugin prefix for every PHP symbol, option, metadata key, REST route, CSS class, JavaScript global, and database table.
- Require capability checks and nonces for all administration actions.
- Sanitize input, validate URLs and slugs, and escape output for its rendering context.
- Register rewrite rules carefully and flush rewrite rules only on plugin activation or deactivation—not on normal requests.
- Generate managed URLs with `home_url()`; do not store a manually entered site domain.
- Do not create public post pages for QR records.
- Keep the plugin independent of a specific theme, page builder, or managed host.
- Avoid external QR-code services at runtime. A maintained local PHP dependency is acceptable.

## Scope discipline

- Treat the MVP non-goals in the specification as out of scope unless explicitly requested.
- Do not add logo-overlaid QR codes, detailed visitor tracking, bulk tools, public APIs, multi-domain behavior, or SaaS features as incidental work.
- Preserve compatibility with standard WordPress permalink behavior and common caching setups.
- Make focused changes; do not reformat or rewrite unrelated files.

## Verification before handoff

- Run the relevant linting, static analysis, and automated tests available in the repository.
- Test activation and deactivation behavior, including rewrite-rule handling.
- Verify active, paused, and unknown-slug responses.
- Confirm that changing a destination leaves the generated QR content unchanged and changes only future redirects.
- Validate that downloaded SVG and PNG files contain the managed URL and scan successfully.
- Report what was actually tested and any remaining manual WordPress-admin or phone-scanning checks.
