**Follow-up:** adopt the automated release flow (Flow A) from the wp-plugin-updater-guide — add `build_plugin.py` at the repo root and `.github/workflows/release.yml` (publish-on-tag). This repo currently releases manually (Flow B); the CI flow builds the zip with Python on Linux (no Windows backslash-zip risk) and fails the release if the `vX.Y.Z` tag doesn't match the `Version:` header, preventing the "perpetual update available" loop. Validated 2026-07-02: confirmed no `.github/workflows/` or build script exists yet — still accurate, still open, genuinely larger effort (new CI infrastructure).

~~**Follow-up:** the "Browse GDrive" modal paste-URL box has the same `drive.file`-scope 404 limitation as the product-editor paste field removed in v3.4.27.~~ Fixed 2026-07-02 (v3.4.33): validated the claim (confirmed via `WGDP_Google_Drive::get_file()` doc comment and the identical AJAX call shape to the already-removed product-editor field), then removed the `.wgdp-drive-browser__paste` form and its submit handler from `admin/js/wgdp-admin.js`, the now-fully-unused `ajax_get_file_info` handler and its `wp_ajax_wgdp_get_file_info` registration from `includes/class-wgdp-product-meta.php`, and the associated CSS rules from `admin/css/wgdp-admin.css`. Browse GDrive and Google Picker remain the only entry points for linking files, matching the v3.4.27 precedent.

**Follow-up:** investigate a fix for the unindexed refund-lookup subquery added in v3.4.29 — `get_unassigned_order_items()` (`class-wgdp-entitlements.php`, `refund_totals` derived table) scans `wc_order_itemmeta` filtered by `meta_key = '_refunded_item_id'`, which WooCommerce's schema doesn't index (only `order_item_id` is indexed). Fine for typical stores, but on a store with a very large itemmeta table and heavy refund volume this could add noticeable query time to the Access Manager screen. Only worth chasing if that page is reported slow; possible directions: cache the refund totals, scope the scan by joining through `oi.order_item_id` ranges first, or maintain a summary table updated on refund events.

---

## Code review findings (2026-07-02, session 2)

All six items below were validated against the actual code (each confirmed by
an independent re-read quoting exact lines) and fixed same-session; commits
pushed to `main` as v3.4.34 through v3.4.39. Kept here only as a pointer —
see the commit log for full descriptions of each fix, not this file.

- ~~`assign_recipient_to_order_item()` slot-capacity check rejects re-assigning an already-assigned email~~ Fixed v3.4.34 (`class-wgdp-entitlements.php`).
- ~~`refresh_access_token()` treats a `null` lock result (account deleted mid-refresh) as success~~ Fixed v3.4.35 (`class-wgdp-google-auth.php`).
- ~~Drive API 401 retry re-serves the same rejected token instead of forcing a real refresh~~ Fixed v3.4.36 (`class-wgdp-google-auth.php` + `class-wgdp-google-drive.php`, added `force_refresh_access_token()`).
- ~~Bulk "Resend OTP" invalidates a just-sent code when 2+ selected rows share a recipient group~~ Fixed v3.4.37 (`class-wgdp-admin.php`, dedup by `order_item_id`+`recipient_email`).
- ~~Bulk "Retry Grant" retries against a stale/detached `cloud_asset_id` instead of reprovisioning like the single-item handler~~ Fixed v3.4.38 (`class-wgdp-admin.php`, skip + point admin at single-item Retry Grant).
- ~~`update_sales_counter()` advances the release-gate counter for items whose account is disconnected, even though `create_entitlements()` skips creating a row for them~~ Fixed v3.4.39 (`class-wgdp-order-handler.php`, mirrors the connectivity check).

### Follow-ups from this session (not fixed — lower confidence / needs product decision)

- **`handle_partial_refund()` reads recipient counts without the per-order-item lock** — `class-wgdp-order-handler.php:582-625`. `create_entitlements()` explicitly holds `with_order_item_lock()` around its recipient loop specifically to prevent a concurrent trigger from acting on a stale count (see comment at line ~252); `handle_partial_refund()` only holds the order-level `with_sales_counter_lock` while it calls `count_active_recipients_for_item()` and then revokes based on that snapshot. A partial refund landing at nearly the same moment as an admin "Add Recipient" action for the same order item could compute `excess`/revocation candidates from a stale count. Narrow window, not confirmed to be reachable in practice — worth a closer look if refund/recipient-add races are ever reported.
- **Variation with `_wgdp_counts_toward_product_threshold = no` but `_wgdp_threshold_scope = entire_product` can never auto-release via `min_sales_qty`** — `class-wgdp-release-gate.php:270-302`. If those two settings are left in this (arguably contradictory) combination, the variation's own release check compares against the product-level counter, which by design never includes that variation's sales. May be an intentional admin-configuration trap rather than a bug; consider either validating against this combination in the admin UI or documenting it. Not fixed — needs a product decision on whether to guard against it.

---

## Code review findings (2026-07-02)

### MEDIUM

- ~~**`issue_otp_for_entitlement` treats `$wpdb->update` returning 0 as failure** — `class-wgdp-otp.php:77`.~~ NOT actionable (re-validated 2026-07-02): every issue writes a fresh random `otp_hash`/`claim_token_hash`, so an existing row is *always* changed and returns ≥1. A `0` return therefore only happens when the id doesn't exist, where returning a `WP_Error` is the correct behavior. Removing the `0 ===` branch would *introduce* a bug (silent success on a bad id). No change.

- ~~**`get_unassigned_order_items` decrements `$total` after pagination**~~ Fully fixed 2026-07-02 (v3.4.29): `class-wgdp-entitlements.php` now applies both the `_wgdp_includes_digital` qualification check and refund-adjusted quantity directly in the SQL (`digital_flag` join + `refund_totals` derived-table join summing `_refunded_item_id`/`_qty` from `wc_order_itemmeta`), instead of filtering post-fetch in PHP. The `COUNT()` query and the paged items query now agree exactly, so `total`/`total_pages` are exact and no page can become unreachable in the Access Manager pager. (v3.4.28 had only clamped the total to zero as an interim step.)

### LOW

- ~~**`consume_rate_limit` lock may run against a read replica**~~ Fixed 2026-07-02 (v3.4.30): `class-wgdp-db.php` now calls `$wpdb->send_reads_to_masters()` (guarded with `method_exists`, so it's a no-op on vanilla WordPress) before acquiring the `GET_LOCK`, forcing subsequent reads on HyperDB-based setups (e.g. WP VIP) onto the primary connection so the lock and the transient read/write it protects can't be split across a replica.

- ~~**`ajax_bulk_resend_otp` doesn't clear counts transient**~~ Fixed 2026-07-02 (v3.4.31): the earlier "no-op" validation was wrong — `issue_otp_for_entitlement()` (`class-wgdp-otp.php:74`) unconditionally resets `verification_status` to `'pending'`, and `count_by_status()` (`class-wgdp-entitlements.php:543-587`) buckets `'expired'` separately from `'pending_verification'`. Since both resend handlers only exclude `verified`/`revoked` rows, resending to an `expired` row (the most common reason to resend) moves it from the `expired` bucket to `pending_verification`, staling the 5-minute `wgdp_permission_counts` transient. Added `delete_transient('wgdp_permission_counts')` on success to both `ajax_bulk_resend_otp` (`class-wgdp-entitlements-list.php`) and the single-item `ajax_resend_otp` (`class-wgdp-order-handler.php:1285-1327`), which had the same gap and wasn't previously flagged.

- ~~**`process_am_bulk_actions` only gated by `manage_woocommerce`**~~ Resolved 2026-07-02: confirmed as intentional. Owner decided Shop Managers should be able to revoke all Drive access, same as the rest of the Access Manager tab — no stricter capability needed for `revoke`. No change.

- ~~**`$_GET['page']` / `$_GET['update_check']` compared without sanitization** — `class-wgdp-admin.php:888, 613`.~~ Fixed 2026-07-02 (v3.4.17): line 888 now runs `sanitize_key( wp_unslash( ... ) )`. Line 613 is only an `isset()` existence check (no value read), so nothing to sanitize there.

---

## Validated as false positives / already mitigated (2026-07-02)

- **`release-gate` cursor pagination assumes strict id-ascending order (formerly MEDIUM)** — NOT actionable. Both underlying queries already sort correctly: `get_pending_release_for_product` and `get_pending_release_for_variation` (`class-wgdp-entitlements.php:348, 365`) both end in `... AND id > %d ORDER BY id ASC LIMIT %d`. With guaranteed ascending order, `max($after_id, (int)$row['id'])` is exactly equal to the last id seen, so no row can be skipped. Validated 2026-07-02.

- **Order-impact `file_count` shows old row count (formerly MEDIUM)** — NOT actionable. `class-wgdp-admin.php:1142` always sets `file_count` from `$replacement['file_count']`, and `create_entitlements_for_recipient` always returns `file_count` (`class-wgdp-entitlements.php:1483`). The `?? count($result['all_rows'])` fallback at line 1154 is therefore dead code — the actual new count is always reported. Removed from findings 2026-07-02.

- **Direct interpolated query in `maybe_show_backfill_error_notice` (formerly MEDIUM)** — NOT actionable. `class-wgdp-admin.php:857-860` interpolates the table name (from `WGDP_DB::get_backfill_table_name()`, built off `$wpdb->prefix` with no user input). Table/identifier names cannot be bound via `$wpdb->prepare` (it only handles values), so interpolation-with-`phpcs:ignore` — already present — is the standard WP pattern. Nothing to change. Removed from findings 2026-07-02.

- **OAuth callback relies only on the nonce, no capability re-check (formerly HIGH)** — NOT a gap. The callback lives in `render_settings_tab()`, which is only reached via `render_page()` after the `current_user_can_manage_settings()` gate at `class-wgdp-admin.php:93` (requires `manage_options` or `manage_wgdp_settings` — stricter than `manage_woocommerce`). Additionally `render_page():59` downgrades the tab away from `settings` for users without the capability. The nonce is not the only protection. Removed from findings 2026-07-02.

- **Access Manager `orderby`/`order` not allow-listed (formerly HIGH SQL-injection)** — NOT a vulnerability. Although the list-table layer (`class-wgdp-access-manager-table.php:551-553`) only runs `sanitize_text_field`, the actual ORDER BY is built in `get_items_for_list_table()` (`class-wgdp-entitlements.php:690-692`), which validates `orderby` against an explicit `$allowed_orderby` column allow-list and coerces `order` to `ASC`/`DESC` only before interpolation. No injection path exists. Removed from findings.

- **Store API cart-key queue relies on `WC()->cart` at checkout (formerly HIGH)** — NOT actionable. Verified 2026-07-02 against WooCommerce Blocks' `Checkout::process_order()` (via `CheckoutTrait::update_order_from_request()`): the draft order is created *from* the current cart, and `update_order_from_request()` (which fires the `woocommerce_store_api_checkout_update_order_from_request` hook this plugin hooks) runs before payment processing and well before the cart is emptied on success. `WC()->cart` is populated for the whole synchronous request. The existing code comment in `get_cart_key_queue_by_product()` already documents this. No change.
