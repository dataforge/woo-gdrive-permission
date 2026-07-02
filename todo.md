**Follow-up:** adopt the automated release flow (Flow A) from the wp-plugin-updater-guide — add `build_plugin.py` at the repo root and `.github/workflows/release.yml` (publish-on-tag). This repo currently releases manually (Flow B); the CI flow builds the zip with Python on Linux (no Windows backslash-zip risk) and fails the release if the `vX.Y.Z` tag doesn't match the `Version:` header, preventing the "perpetual update available" loop.

---

## Code review findings (2026-07-02)

### CRITICAL

- **Legacy CBC decrypt has no authentication (padding-oracle / tamper risk)** — `class-wgdp-google-auth.php:657-672`. The legacy `iv::ciphertext` path (base64, CBC, no HMAC) is used to decrypt `wgdp_accounts` / `wgdp_oauth_client_secret` options holding refresh tokens and client secrets. New writes use `v1c::`/`v2s::`/`v2g::` so this only fires for old data, but ciphertext tampering is undetectable. Migrate any remaining legacy values to the authenticated format on first read and drop the unauthenticated branch once none remain.

### HIGH

- **`mark_revoked` unlocked fallback can orphan a recipient group's claim token** — `class-wgdp-entitlements.php:228-236`. When `with_recipient_group_lock` returns a `WP_Error` (lock not acquired), the code falls through to `write_revoked_row( $id, $reason, false )` without clearing or transferring the claim token. The lock exists precisely to keep one claimable token in the group; this fallback can revoke the only row holding a live token, leaving the group with zero claimable tokens. On lock failure, clear the claim token on the row being revoked (or accept and document the orphan risk).

- **`dbDelta` receives two concatenated CREATE statements and always bumps DB version** — `class-wgdp-db.php:34-95`. Two `CREATE TABLE` statements are concatenated into one string passed to `dbDelta`, which is fragile around statement splitting, and `update_option('wgdp_db_version')` runs unconditionally afterward. A partial/failed dbDelta is never retried because the stored version already matches. Pass an array of statements to `dbDelta`, verify the tables exist, and only then bump the version.

- **Store API cart-key queue relies on `WC()->cart` at checkout** — `class-wgdp-blocks-integration.php:184-201`. During `woocommerce_store_api_checkout_update_order_from_request` the Store API cart may be empty/unavailable, so `$cart_key_queue` is empty and submitted recipients (keyed by cart item key from JS) never match `$recipients[ $cart_key ]`, falling through to the legacy single-line lookup. Derive cart keys from `$order` items or Store API extension data instead.

- **Blocks checkout enforces qty cap by array index, not count** — `class-wgdp-blocks-integration.php:130`. The `(int) $index >= (int) $qty` guard uses the raw array index; a sparse `{0:'a', 5:'b'}` payload is mishandled. Mirror `WGDP_Classic_Checkout::validate_recipient_fields`: `array_values()` first, then count-check positionally.

- **OAuth callback relies only on the nonce, no capability re-check** — OAuth callback in `class-wgdp-admin.php` (around line 213) verifies the `state` nonce but does not re-assert `current_user_can('manage_woocommerce')` (or stricter) before storing refresh tokens / completing the connect. Add an explicit capability check in the callback handler.

- **`drive.file` scope may be insufficient for granting permissions on pre-existing files** — `class-wgdp-google-auth.php:10`. The plugin grants permissions on arbitrary user-selected Drive files/folders, but `drive.file` only allows app-created/app-opened files. If customers report 403s on permission creation, this scope is the likely cause; confirm Picker token wiring or widen scope as needed.

### MEDIUM

- **`issue_otp_for_entitlement` treats `$wpdb->update` returning 0 as failure** — `class-wgdp-otp.php:77`. Zero rows affected (no column actually changed) returns a false-negative `WP_Error`. Treat only `false` as an error; on `0`, force a timestamp change or re-issue. (Note: currently benign — every issue writes fresh random `otp_hash`/`claim_token_hash`, so a matching row always changes; `0` only occurs when the id doesn't exist, where an error is correct. Low priority.)

- **`get_unassigned_order_items` decrements `$total` after pagination** — `class-wgdp-entitlements.php:1155-1192`. The SQL count is already paged; the PHP `$total--` post-filtering produces inconsistent (sometimes negative) pagination totals for the Access Manager. (Validated 2026-07-02: confirmed real — `$total` comes from a separate COUNT query over all rows, and per-page `$total--` on qualification/qty filters skews it. A clean fix is non-trivial because the qualification check is PHP-side and can't be replicated in the COUNT SQL; deferred. Options: move the qualification/qty filter into SQL, or clamp `max(0, ...)` and accept approximate totals.)

- **`create_entitlements_for_recipient` mutates `recipient_index` mid-loop** — `class-wgdp-entitlements.php:1431-1473`. When a revoked row is reused, its prior `recipient_index` can overwrite the in-flight value and propagate to newly created rows for later resources, producing inconsistent seat indices across the same recipient's files.

- **`expire_stale()` skips rows with NULL `claim_token_expires_at`** — `class-wgdp-entitlements.php:1761-1770`. Pending rows with a NULL expiry never expire and permanently consume recipient slots. Confirm intent or expire by `created_at` fallback.

- **Access Manager file count shows `0 / 0` for fully-unassigned rows** — `class-wgdp-access-manager-table.php:263-270`. `inject_unassigned_seats`/`inject_missing_email_items` add rows whose `prod_key` was never preloaded into `expected_files_cache`, so `column_files` always renders `0 / 0`. Seed the cache for the unassigned branch the same way the assigned branch does.

- **Seat number computed by active rank, not `recipient_index`** — `class-wgdp-access-manager-table.php:668-688`. Revoking a middle seat renumbers the remaining seats (1,2,3…) and discards the real `recipient_index` from the `MIN()`; the over-allocation check (`$seat > $qty`) is then unreachable. Label seats with the actual `min_index`.

- **Backfill atomic-claim swallows DB errors** — `class-wgdp-cron.php:297-311`. `$claimed` is `false` on DB error and `0` on lost race; both are treated as "nothing to do" and the queue can stall invisibly. Distinguish `false === $claimed` (log + return) from `0 === $claimed`.

- **`release-gate` cursor pagination assumes strict id-ascending order** — `class-wgdp-release-gate.php:484-518, 540-562`. `max($after_id, ...)` can jump the cursor past rows that should still be processed if the underlying query is not `ORDER BY id ASC`, silently skipping pending-release entitlements. Guarantee ordering or use the last id seen only.

- **`list_files` hand-escapes folder id into Drive `q`** — `class-wgdp-google-drive.php:130-138`. Manual backslash/quote escaping is fragile if `$folder_id` ever originates from user/picker input; validate the ID charset before interpolation.

- **Order-impact `file_count` shows old row count** — `class-wgdp-admin.php:1154`. Falls back to `count($result['all_rows'])` (pre-replacement rows) when `file_count` is absent; report the actual new count from `create_entitlements_for_recipient`.

- **Direct interpolated query in `maybe_show_backfill_error_notice`** — `class-wgdp-admin.php:857-860`. Table name is interpolated without `$wpdb->prepare`. Plugin-controlled today, but standardize.

- **`esc_html()` applied before JSON serialization** — `class-wgdp-blocks-integration.php:147`. Error text fed to a `RouteException` is HTML-escaped server-side, so the client renders `&lt;`. Move escaping to the client render layer.

### LOW

- **`consume_rate_limit` lock may run against a read replica** — `class-wgdp-db.php:118-163`. `GET_LOCK` is only effective if executed on the primary; if `$wpdb` routes the SELECT to a replica, mutual exclusion is lost. Ensure the lock runs against the primary connection.

- **Dashboard widget assumes all count keys exist** — `class-wgdp-dashboard-widget.php:36-50`. `wp_parse_args` the counts against a default array to avoid PHP notices on partial/cached results. (Validated 2026-07-02: benign in practice — the source `count_by_status()` always returns the full key set, so notices only occur if an old/partial transient shape is cached. Pure defensive hygiene; low priority.)

- **`ajax_bulk_resend_otp` doesn't clear counts transient** — `class-wgdp-entitlements-list.php:37-66`. (Validated 2026-07-02: resend only operates on non-verified/non-revoked rows and does not change any row's `grant_status`/`verification_status` category, so `count_by_status` totals are unchanged and the transient is not actually stale. Effectively a no-op; low priority. Add `delete_transient('wgdp_permission_counts')` only as defensive hygiene if verification behavior later changes.)

- **Classic checkout returns on first error** — `class-wgdp-classic-checkout.php:111-149`. `validate_recipient_fields` returns on the first invalid/duplicate/excess recipient, so multi-item carts surface one error at a time. Accumulate notices via `wc_add_notice` and continue.

- **Classic-checkout save path trusts sparse `$_POST` array** — `class-wgdp-classic-checkout.php:189`. `array_values()` before slicing and add a duplicate-email guard for defense-in-depth.

- **Blocks checkout script-data captured once at module load** — `assets/js/wgdp-checkout-block.js:13-14,70`. `qualifyingItems` is read once from `getSetting`; cart qty changes during checkout re-render blocks but never refresh script-data, so the qty-sync `useEffect` is effectively dead. Subscribe to a wc store selector for live quantities.

- **`create_entitlements` ignores lock `WP_Error`** — `class-wgdp-order-handler.php:257-311`. When `with_order_item_lock` fails, recipients silently get no entitlements and no order note explains why. Add an order note on `is_wp_error($lock_outcome)`.

- **`revoke_entitlements_for_deleted_order_item` shadows `$order_id`** — `class-wgdp-order-handler.php:649-700`. The closure reassigns `$order_id` from the entitlement row (used for the note) while the outer `$order_id` (from the WC item) is used for the counter decrement; they can diverge if the entitlement row's `order_id` disagrees with the item's order.

- **`process_am_bulk_actions` only gated by `manage_woocommerce`** — `class-wgdp-admin.php:43-52, 88-99`. Shop Managers (broad role) can bulk-revoke/retry/re-provision all customer Drive access. If revocation should be admin-only, add an explicit stricter capability check inside the destructive branches.

- **`schedule()` clears legacy hook on every `plugins_loaded`** — `class-wgdp-cron.php:394-403`, called from bootstrap `:95`. `wp_clear_scheduled_hook('wgdp_retry_failed_permissions')` does a DB write on every page load forever. Gate the legacy cleanup with a one-time option flag.

- **`notification-email` assumes `$fl['name']` exists** — `class-wgdp-notification-email.php:94-99, 127-132`. Defensive: guard keys or fall back to `$fl['link']`.

- **`$_GET['page']` / `$_GET['update_check']` compared without sanitization** — `class-wgdp-admin.php:888, 613`. Used only in strict comparisons so not exploitable, but inconsistent with the rest of the file.

---

## Validated as false positives / already mitigated (2026-07-02)

- **Access Manager `orderby`/`order` not allow-listed (formerly HIGH SQL-injection)** — NOT a vulnerability. Although the list-table layer (`class-wgdp-access-manager-table.php:551-553`) only runs `sanitize_text_field`, the actual ORDER BY is built in `get_items_for_list_table()` (`class-wgdp-entitlements.php:690-692`), which validates `orderby` against an explicit `$allowed_orderby` column allow-list and coerces `order` to `ASC`/`DESC` only before interpolation. No injection path exists. Removed from findings.
