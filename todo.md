**Follow-up:** adopt the automated release flow (Flow A) from the wp-plugin-updater-guide — add `build_plugin.py` at the repo root and `.github/workflows/release.yml` (publish-on-tag). This repo currently releases manually (Flow B); the CI flow builds the zip with Python on Linux (no Windows backslash-zip risk) and fails the release if the `vX.Y.Z` tag doesn't match the `Version:` header, preventing the "perpetual update available" loop. Validated 2026-07-02: confirmed no `.github/workflows/` or build script exists yet — still accurate, still open, genuinely larger effort (new CI infrastructure).

~~**Follow-up:** the "Browse GDrive" modal paste-URL box has the same `drive.file`-scope 404 limitation as the product-editor paste field removed in v3.4.27.~~ Fixed 2026-07-02 (v3.4.33): validated the claim (confirmed via `WGDP_Google_Drive::get_file()` doc comment and the identical AJAX call shape to the already-removed product-editor field), then removed the `.wgdp-drive-browser__paste` form and its submit handler from `admin/js/wgdp-admin.js`, the now-fully-unused `ajax_get_file_info` handler and its `wp_ajax_wgdp_get_file_info` registration from `includes/class-wgdp-product-meta.php`, and the associated CSS rules from `admin/css/wgdp-admin.css`. Browse GDrive and Google Picker remain the only entry points for linking files, matching the v3.4.27 precedent.

~~**Follow-up:** investigate a fix for the unindexed refund-lookup subquery added in v3.4.29~~ Fixed 2026-07-02 (v3.4.43): replaced the `refund_totals` derived-table scan over `wc_order_itemmeta` in `get_unassigned_order_items()` with a join against a new `wp_wgdp_refund_totals` cache table (`order_item_id` primary key → O(1) lookup instead of a full-table scan). `WGDP_DB::install()` creates the table via `dbDelta` and, the first time it's created, runs a one-time backfill from the existing itemmeta data so pre-existing refunds are reflected immediately. `WGDP_Order_Handler::handle_partial_refund()` (`class-wgdp-order-handler.php`) now calls `WGDP_DB::set_refund_total()` for every refunded item on each refund event, keeping the cache in sync going forward — this is the plugin's own table, so no WooCommerce-core schema is touched. Refund deletion isn't hooked (matches this codebase's existing sales-counter handling, which also doesn't reverse on refund deletion — not a regression introduced here).

---

## Code review findings (2026-07-02, session 3)

- ~~**`ajax_retry_grant` / `retry_grant_reprovision` race: no per-order-item lock, unlike every other entitlement-mutating path**~~ Fixed 2026-07-02 (v3.4.42): `class-wgdp-admin.php`. Validated the race was real — the handler did a check-then-act sequence (read entitlement → check `get_active_drive_resources`/`get_existing_entitlement` → `create()`/`update()`) without `with_order_item_lock`, unlike `ajax_change_email` and the now-fixed `handle_partial_refund`. Two concurrent "Retry Grant" clicks on the same error-state entitlement could both pass the pre-checks and both create/grant Drive access, producing duplicate DB rows, duplicate live Drive permissions, and duplicate emails. Wrapped the full read-check-mutate sequence (including the reprovision path) in `$ent->with_order_item_lock()`, converting `retry_grant_reprovision()` and the inline retry logic to return values/`WP_Error` instead of calling `wp_send_json_*` directly, with the actual JSON response sent once outside the lock.

- **`count_unassigned_order_items` vs `get_unassigned_order_items` digital-qualification logic (raised as a possible MEDIUM bug)** — NOT actionable, false positive. The count query's SQL pre-filter is looser (`meta_value != 'no'`) than the list query's (`NULL`/`''`/`'yes'` only), but `count_unassigned_order_items()` (`class-wgdp-entitlements.php:1276-1292`) applies the real, authoritative filter in PHP per-row via `WGDP_Product_Meta::variation_qualifies_for_digital()` (`class-wgdp-product-meta.php:635-649`, itself `'' === $flag || 'yes' === $flag`) before incrementing the count. The SQL pre-filter is just a broader net for performance; the final count matches the list's stricter rule. No change.

### Follow-ups from this session

- **Bulk "Retry Grant" / "Resend OTP" (`process_am_bulk_actions()`, `class-wgdp-admin.php:126-193`) read-then-act per row without a lock.** Lower severity than the fixed single-item race (operates row-by-row rather than spanning a create), but a claim-page submission for the same order item during a bulk run can still interleave with `mark_error`/`issue_otp_for_recipient_group`. Worth wrapping each row's action in `with_order_item_lock` for consistency with the now-locked single-item paths, if this becomes a reported issue.

- **`atomic_increment_meta()` (`class-wgdp-release-gate.php:590-621`) can't distinguish "matched but value unchanged" from "row missing".** When decrementing an already-zero counter, the `GREATEST(0, ...)` clause means `$wpdb->query()` returns 0 affected rows even though the row exists and was matched (MySQL reports rows *changed*, not rows *matched*), so the code falls into the `add_post_meta(..., true)` fallback, which then silently no-ops because the key already exists. The numeric outcome happens to stay correct in this specific case, but a genuine write failure would be masked identically. Worth tightening (e.g. check `$wpdb->last_error`) if this function is ever extended.

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

### Follow-ups from this session

- ~~**`handle_partial_refund()` reads recipient counts without the per-order-item lock**~~ Fixed 2026-07-02 (v3.4.40): `class-wgdp-order-handler.php` — validated the race was real (the order-level `with_sales_counter_lock` doesn't protect the per-item count-then-revoke sequence the way `with_order_item_lock` does for `create_entitlements()`). Wrapped the `count_active_recipients_for_item()` → `get_revocation_candidates()` → revoke sequence in `$ent->with_order_item_lock( $order_item_id, ... )`, mirroring the existing pattern, with an order note logged if the lock can't be acquired.

- ~~**Variation with `_wgdp_counts_toward_product_threshold = no` but `_wgdp_threshold_scope = entire_product` can never auto-release via `min_sales_qty`**~~ Fixed 2026-07-02 (v3.4.41): validated the dead-end was real (confirmed via `class-wgdp-release-gate.php:86-93/249` that the product-level counter never includes a variation's own sales when `counts_toward_product_threshold = no`, so an `entire_product`-scoped gate for that variation could never move) and that no admin-UI guard prevented the combination from being saved (`class-wgdp-product-meta.php:376-473, 609-625`, `admin/js/wgdp-admin.js:1139-1155` only toggle field visibility). Fixed at the read site instead of save time so it self-heals stores with existing misconfigured data: `get_effective_threshold_scope()` (`class-wgdp-release-gate.php:68-84`) now falls back to `this_variation_only` whenever the resolved scope is `entire_product` but the variation is excluded from the product threshold.

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
