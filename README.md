# Field Migrator

`Field Migrator` (`wp-field-migrator`) adds an admin interface to preview and migrate values from one field into another for a selected post type.

## Access

- WordPress Admin -> `Tools` -> `Field Migrator`
- URL: `/wp-admin/tools.php?page=wp-field-migrator`

## Current capabilities

- Source -> target field mapping for a selected post type.
- Field pickers grouped by:
  - Core Fields
  - ACF Fields (Active)
  - ACF Fields (Inactive)
  - Detected Meta Keys
- Optional `Custom meta key...` fallback for source and target.
- Optional toggle to show internal/private keys (`_meta_key`).
- Paginated preview (`Page`, `Per Page`).
- `Preview All Changes (Append)` to stream/append preview rows across all posts in async batches.
- `Stop Preview Appending` to halt append mode.
- `Export Dry-Run CSV (All Posts)` to download full preview output without writing changes.
- Async migration across all posts with configurable `Migration Batch Size`.
- Efficient cursor/keyset batching (`ID > last_id`) to avoid large offset queries.
- Skips rows with empty source values and rows where target already matches source.
- Run history with per-run status/totals, failed IDs, and downloadable CSV run reports (including per-batch logs).
- Nonce + capability checks for actions.

## Core field names

You can map to/from these special core fields:

- `core:post_title`
- `core:post_content`
- `core:post_excerpt`

## Example

Migrate `description` -> `location` on `exhibition` posts:

1. Open `Tools > Field Migrator`.
2. Set `Post Type` to `exhibition`.
3. Set `Source Field` to `description` (or select from dropdown).
4. Set `Target Field` to `location` (or select from dropdown).
5. Click `Preview Changes` and review results.
6. Optionally run `Preview All Changes (Append)` for full-scan preview.
7. Click `Run Migration (Async, All Posts)`.

## Safety

- Back up your database before using this tool.
- Migrations can irreversibly modify post data.
- Test first on staging.

## Notes

- Migration copies source values into target values.
- Source fields are not cleared automatically.
