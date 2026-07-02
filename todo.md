**Follow-up:** adopt the automated release flow (Flow A) from the wp-plugin-updater-guide — add `build_plugin.py` at the repo root and `.github/workflows/release.yml` (publish-on-tag). This repo currently releases manually (Flow B); the CI flow builds the zip with Python on Linux (no Windows backslash-zip risk) and fails the release if the `vX.Y.Z` tag doesn't match the `Version:` header, preventing the "perpetual update available" loop.

---

## Code review findings (2026-07-02)

### CRITICAL

- **Legacy CBC decrypt has no authentication (padding-oracle / tamper risk)** — `class-wgdp-google-auth.php:657-672`. The legacy `iv::ciphertext` path (base64, CBC, no HMAC) is used to decrypt `wgdp_accounts` / `wgdp_oauth_client_secret` options holding refresh tokens and client secrets. New writes use `v1c::`/`v2s::`/`v2g::` so this only fires for old data, but ciphertext tampering is undetectable. Migrate any remaining legacy values to the authenticated format on first read and drop the unauthenticated branch once none remain.

### HIGH

- **Store API cart-key queue relies on `WC()->cart` at checkout** — `class-wgdp-blocks-integration.php:184-201`. During `woocommerce_store_api_checkout_update_order_from_request` the Store API cart may be empty/unavailable, so `$cart_key_queue` is empty and submitted recipients (keyed by cart item key from JS) never match `$recipients[ $cart_key ]`, falling through to the legacy single-line lookup. Derive cart keys from `$order` items or Store API extension data instead.

- **`drive.file` scope may be insufficient for granting permissions on pre-existing files** — `class-wgdp-google-auth.php:10`. The plugin grants permissions on arbitrary user-selected Drive files/folders, but `drive.file` only allows app-created/app-opened files. If customers report 403s on permission creation, this scope is the likely cause; confirm Picker token wiring or widen scope as needed.

### MEDIUM

- ~~**`issue_otp_for_entitlement` treats `$wpdb->update` returning 0 as failure** — `class-wgdp-otp.php:77`.~~ NOT actionable (re-validated 2026-07-02): every issue writes a fresh random `otp_hash`/`claim_token_hash`, so an existing row is *always* changed and returns ≥1. A `0` return therefore only happens when the id doesn't exist, where returning a `WP_Error` is the correct behavior. Removing the `0 ===` branch would *introduce* a bug (silent success on a bad id). No change.

- **`get_unassigned_order_items` decrements `$total` after pagination** — `class-wgdp-entitlements.php:1155-1192`. The SQL count is already paged; the PHP `$total--` post-filtering produces inconsistent (sometimes negative) pagination totals for the Access Manager. (Validated 2026-07-02: confirmed real — `$total` comes from a separate COUNT query over all rows, and per-page `$total--` on qualification/qty filters skews it. A clean fix is non-trivial because the qualification check is PHP-side and can't be replicated in the COUNT SQL; deferred. Options: move the qualification/qty filter into SQL, or clamp `max(0, ...)` and accept approximate totals.)

### LOW

- **`consume_rate_limit` lock may run against a read replica** — `class-wgdp-db.php:118-163`. `GET_LOCK` is only effective if executed on the primary; if `$wpdb` routes the SELECT to a replica, mutual exclusion is lost. Ensure the lock runs against the primary connection.

- **`ajax_bulk_resend_otp` doesn't clear counts transient** — `class-wgdp-entitlements-list.php:37-66`. (Validated 2026-07-02: resend only operates on non-verified/non-revoked rows and does not change any row's `grant_status`/`verification_status` category, so `count_by_status` totals are unchanged and the transient is not actually stale. Effectively a no-op; low priority. Add `delete_transient('wgdp_permission_counts')` only as defensive hygiene if verification behavior later changes.)

- **Blocks checkout script-data captured once at module load** — `assets/js/wgdp-checkout-block.js:13-14,70`. `qualifyingItems` is read once from `getSetting`; cart qty changes during checkout re-render blocks but never refresh script-data, so the qty-sync `useEffect` is effectively dead. Subscribe to a wc store selector for live quantities.

- **`process_am_bulk_actions` only gated by `manage_woocommerce`** — `class-wgdp-admin.php:43-52, 88-99`. Shop Managers (broad role) can bulk-revoke/retry/re-provision all customer Drive access. If revocation should be admin-only, add an explicit stricter capability check inside the destructive branches.

- ~~**`$_GET['page']` / `$_GET['update_check']` compared without sanitization** — `class-wgdp-admin.php:888, 613`.~~ Fixed 2026-07-02 (v3.4.17): line 888 now runs `sanitize_key( wp_unslash( ... ) )`. Line 613 is only an `isset()` existence check (no value read), so nothing to sanitize there.

---

## Validated as false positives / already mitigated (2026-07-02)

- **`release-gate` cursor pagination assumes strict id-ascending order (formerly MEDIUM)** — NOT actionable. Both underlying queries already sort correctly: `get_pending_release_for_product` and `get_pending_release_for_variation` (`class-wgdp-entitlements.php:348, 365`) both end in `... AND id > %d ORDER BY id ASC LIMIT %d`. With guaranteed ascending order, `max($after_id, (int)$row['id'])` is exactly equal to the last id seen, so no row can be skipped. Validated 2026-07-02.

- **Order-impact `file_count` shows old row count (formerly MEDIUM)** — NOT actionable. `class-wgdp-admin.php:1142` always sets `file_count` from `$replacement['file_count']`, and `create_entitlements_for_recipient` always returns `file_count` (`class-wgdp-entitlements.php:1483`). The `?? count($result['all_rows'])` fallback at line 1154 is therefore dead code — the actual new count is always reported. Removed from findings 2026-07-02.

- **Direct interpolated query in `maybe_show_backfill_error_notice` (formerly MEDIUM)** — NOT actionable. `class-wgdp-admin.php:857-860` interpolates the table name (from `WGDP_DB::get_backfill_table_name()`, built off `$wpdb->prefix` with no user input). Table/identifier names cannot be bound via `$wpdb->prepare` (it only handles values), so interpolation-with-`phpcs:ignore` — already present — is the standard WP pattern. Nothing to change. Removed from findings 2026-07-02.

- **OAuth callback relies only on the nonce, no capability re-check (formerly HIGH)** — NOT a gap. The callback lives in `render_settings_tab()`, which is only reached via `render_page()` after the `current_user_can_manage_settings()` gate at `class-wgdp-admin.php:93` (requires `manage_options` or `manage_wgdp_settings` — stricter than `manage_woocommerce`). Additionally `render_page():59` downgrades the tab away from `settings` for users without the capability. The nonce is not the only protection. Removed from findings 2026-07-02.

- **Access Manager `orderby`/`order` not allow-listed (formerly HIGH SQL-injection)** — NOT a vulnerability. Although the list-table layer (`class-wgdp-access-manager-table.php:551-553`) only runs `sanitize_text_field`, the actual ORDER BY is built in `get_items_for_list_table()` (`class-wgdp-entitlements.php:690-692`), which validates `orderby` against an explicit `$allowed_orderby` column allow-list and coerces `order` to `ASC`/`DESC` only before interpolation. No injection path exists. Removed from findings.
