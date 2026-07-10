## Code review findings (2026-07-02, session 4) — remaining items

Re-validated 2026-07-09 (session 5) against v3.4.49: fixed lock-race and
full-order-save issues (v3.4.51), plus bulk-action locking, dedup sibling
lock, reactivation cutoff, and email dedup (v3.4.50). Also fixed this session
(v3.4.52): the cron retry dead-letter label (access-manager admin table now
shows "Max retries reached" once grant_retries/revocation_retries hits the
50 cap) and the claim-page slug drift (ensure_page_exists() now reads back
the actual assigned post_name and corrects the wgdp_claim_page_slug option
if WordPress appended a collision suffix).

### LOW

- **Updater performs no checksum/signature verification of the downloaded release zip** — `class-wgdp-updater.php:198-207` (`get_asset_url()`, picks the release zip's `browser_download_url` with no hash/signature check), `class-wgdp-updater.php:46-52` (`check_update()` hands that URL straight to WP core's upgrader). Beyond WP's default HTTPS transport, a compromised GitHub release (or forged-cert MITM) would install arbitrary code. Industry-typical for GitHub-updater plugins. Fixing this properly needs release-side signing infrastructure (e.g. GitHub Actions signing the zip) plus verification logic here — out of scope for a small patch, left open.

---

## Session 6 (2026-07-09) — no open bugs, targeted review found nothing actionable

todo.md had no actionable bugs left (only the out-of-scope updater signature item below), so per the standing instruction this session ran fresh code reviews instead of a fix batch.

Dispatched 3 independent review agents, each reading the full target file plus its real callers/callees (not in isolation):
- `class-wgdp-google-drive.php` (Drive API wrapper) — clean. Only note: no 429/rate-limit-specific backoff (`create_permission`/`delete_permission`/`get_permission`/`list_files`/`get_file` all treat 429 like any other error). Not fixed: cron already retries every ~20 min up to 50 times (~16.6 hrs of backoff headroom), which comfortably outlasts any real Google throttling window, so this isn't a practical bug.
- `class-wgdp-order-handler.php` (WooCommerce order/subscription hook dispatch) — clean. Locking, refund-vs-cancellation disambiguation, sales-counter math, and duplicate-grant guards all checked out.
- `class-wgdp-cron.php` (retry cursors, expiry, verification) — clean. Cursor pagination, UTC-consistent expiry comparisons, per-entitlement locking against overlapping runs, and WP_Error handling all checked out.

No code changes made this session. Nothing to release.

---

## Session 7 (2026-07-09) — no open bugs, targeted review found nothing actionable

todo.md again had no actionable bugs (only the out-of-scope updater signature item), so per the standing instruction this session ran another fresh code review instead of a fix batch.

Dispatched 1 review agent targeting `class-wgdp-otp.php` (OTP/claim-token generation and verification) plus its real caller `class-wgdp-claim-page.php` — clean. Checked: brute-force/rate-limiting (5-attempt hard cap, atomically enforced — sufficient given 1-in-a-million code space), timing side channels (`wp_check_password` is bcrypt/`password_verify`-backed, effectively constant-time), OTP/claim-token entropy (`random_int`/`random_bytes`, CSPRNG), expiry math (UTC-consistent `gmdate()` writes vs `+0000`-forced `strtotime()` reads), reuse-after-verify (blocked by `verification_status` check), concurrent resend-vs-verify races (snapshot-and-conditional-update pattern), SQL parameterization, and output escaping. No bugs found.

No code changes made this session. Nothing to release.

---

## Session 8 (2026-07-10) — no open bugs, targeted review found nothing actionable

todo.md again had no actionable bugs (only the out-of-scope updater signature item), so per the standing instruction this session ran another fresh code review instead of a fix batch.

Dispatched 1 review agent targeting `class-wgdp-entitlements.php` (core entitlement create/reactivate/revoke logic) plus its real callers in `class-wgdp-admin.php`, `class-wgdp-order-handler.php`, `class-wgdp-claim-page.php`, `class-wgdp-release-gate.php`, `class-wgdp-cron.php`, `class-wgdp-otp.php`, and the schema/lock helpers in `class-wgdp-db.php` — clean. Checked: status-transition guards (`mark_granted`/`mark_error` can't resurrect a revoked row), lock-ordering across the four named-lock scopes (no cycle/deadlock potential), the `order_item_asset_email` unique-key reuse pattern (reactivate-or-lookup before insert, no constraint-violation risk), SQL parameterization, UTC-consistent timezone math, rollback correctness in `create_entitlements_for_recipient()`, and the email-reassignment flow in `ajax_change_email` (no double-revoke or Drive-permission leak). No bugs found. (One non-bug stylistic quirk noted in `backfill_new_resources()` — a harmless extra empty batch pass on exact-limit pages — not worth fixing.)

No code changes made this session. Nothing to release.

---

## Session 9 (2026-07-10) — fixed self-service link expiry and duplicate re-verification bugs (v3.4.53)

todo.md again had no actionable bugs, so this session ran a fresh code review targeting `class-wgdp-self-service.php` (customer-facing self-service email/claim flow) plus its callers/callees in `class-wgdp-shortcodes.php`, `class-wgdp-otp.php`, `class-wgdp-entitlements.php`, and `class-wgdp-google-drive.php`. Found and fixed two real bugs:

- **Token-issued self-service links were rejected as "expired" using the wrong anchor** — `filter_page_content()` and `ajax_self_service_email()` both called `is_link_expired($order)` unconditionally, which computes expiry from `_wgdp_self_service_link_resent_at` (only set by the admin "Resend order email" action) or falls back to the order's *creation* date — never the token's own 30-day issuance-based expiry that `validate_self_service_token()` already enforces. Any legitimate freshly-issued token for an order older than 30 days (e.g. WooCommerce's native "Resend order emails," or an order that naturally transitions to `completed` late) was rejected even though the token itself was valid. Fixed by only applying the order-date-based `is_link_expired()` check to legacy order-key links (`auth_type === 'legacy'`), since token-based links already carry their own correct expiry via `validate_self_service_token()`.
- **Submitting the self-service form re-triggered verification for untouched pending-email slots** — the client-side submit handler collected values from every `input[type=email]` on the page, including hidden, unmodified inputs pre-filled with an already-pending email. This caused `assign_recipient_to_order_item(clear_unverified: true)` to fire for slots the customer never touched, revoking and reissuing a new OTP/claim token and sending a redundant verification email each time the form was submitted for an unrelated slot. Fixed by skipping still-hidden (`display: none`) email inputs in the submit handler — only slots where the customer clicked "Use a different email" (revealing the input) are now submitted.

Both fixes are in `includes/class-wgdp-self-service.php`. Released as v3.4.53.

---

## Session 10 (2026-07-10) — fixed checkout recipient-slot bugs (v3.4.54)

todo.md again had no actionable bugs, so this session ran a fresh code review targeting the checkout-time recipient collection paths: `class-wgdp-classic-checkout.php` and `class-wgdp-blocks-integration.php`, plus their callers/callees in `class-wgdp-product-meta.php`, `class-wgdp-entitlements.php`, and `class-wgdp-order-handler.php`. No verification-bypass was found (checkout-collected emails always land in `pending`/`pending` state and must pass OTP verification before any Drive grant). Found and fixed two real bugs:

- **Recipient email fields render blank on a non-AJAX checkout validation-failure redisplay** — `render_recipient_fields()` in `class-wgdp-classic-checkout.php` called `woocommerce_form_field()` without an explicit `value`, so WooCommerce's default value lookup (`WC()->checkout()->get_value( $key )`) did a raw `$_POST[ $key ]` read using the literal bracketed field name (e.g. `wgdp_recipients[abc123][0]`), which never matches PHP's parsed nested `$_POST['wgdp_recipients']['abc123'][0]` array. On WooCommerce's non-AJAX checkout fallback (JS-disabled clients, or any theme/plugin that forces a full-page redisplay on validation error), a correctly-filled recipient field would be wiped along with the invalid one that triggered the error, forcing the customer to retype it or silently losing that recipient to the post-purchase self-service fallback. Fixed by explicitly reading back `$_POST['wgdp_recipients'][cart_key][i]` and passing it as `value`.
- **Skipping an earlier recipient slot at checkout shifts `recipient_index` for later recipients** — both `save_recipient_meta()` (classic) and `save_recipients_from_store_api()` (blocks) built the saved `_wgdp_recipients` JSON by dropping blank slots and appending only filled ones, compacting array positions (e.g. filling only "Recipient 2" and "Recipient 3" produced `[email2, email3]` at indexes 0/1, not 1/2). `WGDP_Order_Handler::create_entitlements()` assigns `recipient_index` purely from array position, and `recipient_index_within_effective_quantity()` (used by `backfill_new_resources()` and the "highest recipient_index revoked first" partial-refund prioritization) trusts that index as the recipient's true purchased seat number. Compaction could let a recipient who should lose access after a partial refund keep it (or vice versa), because their stored index no longer matched the seat they actually filled at checkout. Fixed by preserving each email at its original slot position (padding skipped slots with empty placeholders) instead of compacting — the existing `is_email()`-gated consumer loop in `create_entitlements()` already tolerates and skips the placeholder positions correctly, so no downstream change was needed.

Both fixes are in `includes/class-wgdp-classic-checkout.php` and `includes/class-wgdp-blocks-integration.php`. Released as v3.4.54.

---

## Session 11 (2026-07-10) — fixed duplicate access-granted emails from release-batch/cron overlap (v3.4.55)

todo.md again had no actionable bugs, so this session ran a fresh code review targeting `class-wgdp-release-gate.php` (sales-threshold product release logic) plus its callers/callees in `class-wgdp-order-handler.php`, `class-wgdp-entitlements.php`, `class-wgdp-db.php`, `class-wgdp-product-meta.php`, `class-wgdp-cron.php`, and `class-wgdp-admin.php`. Found and fixed one real bug:

- **Duplicate "your files are ready" emails when a release-batch grant overlaps a cron retry pass** — `WGDP_Claim_Page::grant_drive_access_for_entitlement()` returned plain `true` both when it performed a fresh Drive grant *and* when it found the entitlement already `granted` (a no-op). `WGDP_Release_Gate::batch_grant_pending_release()` / `batch_grant_pending_release_for_variation()` and `WGDP_Cron::retry_failed_grants()` both call this function for rows still in `pending_release` state and, on any non-error result, unconditionally queue that recipient into their own summary-email batch. Since a large `batch_grant_pending_release()` run (up to 5000 rows) can take long enough to overlap a `wgdp_retry_failed_grants` cron tick — which explicitly re-sweeps the same still-`pending_release` rows via `get_stale_pending_release()` — one caller could win the per-entitlement grant lock while the other observed the row as already-granted and still treated it as a fresh grant for email purposes, sending the recipient (and CC'd billing email) two duplicate access-granted emails. Fixed by having `grant_drive_access_for_entitlement()` return `null` (instead of `true`) for the already-granted no-op case, and updating all four batch/cron call sites to only queue a summary email (or, for cron's failed-verified path, add an order note) when the result is `true` — a fresh grant — not `null`.

Not fixed (lower confidence, narrower window, admin-triggered only): `recalculate_sales_counter()` / `recalculate_variation_sales_counter()` (`class-wgdp-release-gate.php`) overwrite the paid-qty counter via a plain `update_post_meta()` with no lock, unlike every other counter-mutating path in this codebase. If an admin's "Recalculate sales" action runs its order scan in the narrow window between `WGDP_Order_Handler::update_sales_counter()` incrementing the counter and persisting `_wgdp_qty_counted_items` for that same order, it could compute a stale total and silently clobber the just-incremented counter. Left open as a documented risk rather than fixed blind, since it requires an admin action to coincide with an in-flight order-status transition and has no automated exploit path.

Fix is in `includes/class-wgdp-claim-page.php`, `includes/class-wgdp-release-gate.php`, and `includes/class-wgdp-cron.php`. Released as v3.4.55.

---

## Session 12 (2026-07-10) — no open bugs, targeted review found nothing actionable

todo.md again had no actionable bugs (only the out-of-scope updater signature item), so per the standing instruction this session ran another fresh code review instead of a fix batch.

Dispatched 1 review agent targeting `class-wgdp-notification-email.php` (access-granted/revoked/OTP email composition and sending) plus its real callers in `class-wgdp-cron.php`, `class-wgdp-release-gate.php`, `class-wgdp-claim-page.php`, `class-wgdp-order-handler.php`, `class-wgdp-entitlements.php`, `class-wgdp-entitlements-list.php`, `class-wgdp-self-service.php`, `class-wgdp-product-meta.php`, and `class-wgdp-admin.php` — clean. Checked: HTML escaping of all dynamic email content, `wp_mail_content_type` filter cleanup (try/finally), `wp_mail()` failure handling, the v3.4.55 duplicate-email batching key consistency, case-insensitive billing-email-dedup normalization, and item-type guards in OTP variation-label building. No bugs found.

No code changes made this session. Nothing to release.

---

## Session 13 (2026-07-10) — fixed disconnect deadlock and stale token-error notice (v3.4.56)

todo.md again had no actionable bugs, so this session ran a fresh code review targeting `class-wgdp-google-auth.php` (Google OAuth2 token acquisition/refresh/storage) plus its callers/callees in `class-wgdp-admin.php`, `class-wgdp-entitlements.php`, `class-wgdp-cron.php`, `class-wgdp-entitlements-list.php`, and `class-wgdp-access-manager-table.php`. Found and fixed two real bugs:

- **Dead Google account could never be disconnected once it had any non-revoked entitlement, bricking the plugin's own documented remediation** — `disconnect()` refused to remove an account while `WGDP_Entitlements::count_active_by_account()` (rows with `grant_status != 'revoked'`, including `'revocation_error'`) was greater than zero. But the *only* way to move a `'revocation_error'` row to `'revoked'` is `revoke_with_drive_delete()`, which calls the Drive API through the very same account being disconnected — if that account's refresh token is permanently dead, this always fails, the row stays stuck (dead-lettered after 50 cron retries per the existing "Max retries reached" UI), and `count_active_by_account()` never drops to zero. The plugin's own admin notice (shown when a refresh confirms the token is dead — routine for OAuth apps left in Google's "Testing" mode, whose refresh tokens expire after 7 days) tells the admin to "disconnect and reconnect the account," but that path was unreachable for any store with outstanding entitlements — i.e. any live store. Reconnecting also always mints a fresh random `account_id`, so the old stuck rows could never be salvaged by reconnecting anyway. Fixed by allowing `disconnect()` to bypass the active-entitlement check specifically when `wgdp_token_error_<account_id>` is already set — i.e. only once a prior refresh attempt has itself confirmed the token is permanently dead, so no live account's safety check is weakened.
- **"Authorization has expired or been revoked" admin notice never cleared after a successful refresh** — `refresh_access_token()` set the `wgdp_token_error_<account_id>` transient (24h TTL) on an expired/revoked-looking failure but never cleared it on a subsequent successful refresh (only `disconnect()` cleared it). A transient failure that happened to match "expired"/"revoked" wording, followed by a normal successful refresh, left the false "Drive access grants will fail" banner showing for up to 24 hours even though the account was working fine. Fixed by clearing the transient on every successful refresh.

Both fixes are in `includes/class-wgdp-google-auth.php`. Released as v3.4.56.

---

## Session 14 (2026-07-10) — fixed variation Drive-resource inheritance freeze (v3.4.57)

todo.md again had no actionable bugs, so this session ran a fresh code review targeting `class-wgdp-product-meta.php` (per-product/variation Drive resource, quantity, and release-gate meta) plus its callers/callees in `class-wgdp-classic-checkout.php`, `class-wgdp-blocks-integration.php`, `class-wgdp-order-handler.php`, `class-wgdp-entitlements.php`, `class-wgdp-release-gate.php`, `class-wgdp-admin.php`, `class-wgdp-cron.php`, `class-wgdp-claim-page.php`, and `class-wgdp-access-manager-table.php`. Found and fixed one real bug:

- **Saving a variation for any reason silently froze an inherited copy of the parent's Drive resources, breaking future inheritance** — `render_variation_fields()` calls `get_drive_resources( $product_id, $variation_id )`, which falls back to the parent's resources when the variation has none of its own. Those inherited resources are rendered as hidden fields (with `_wgdp_drive_resources_submitted=1`) and get resubmitted on every variation save, regardless of whether the admin touched them. `save_variation_meta()` unconditionally wrote whatever was submitted into the variation's own `_wgdp_drive_resources` meta — so editing any unrelated variation field (price, SKU, etc.) permanently converted an inherited resource list into a variation-owned, no-longer-inherited copy. This broke future inheritance of newly added parent-level resources, and more seriously caused `revoke_removed_assets()` / `removed_asset_row_applies()` to skip revoking that variation's buyers when a resource was later removed at the product level, since the variation now appeared to "own" an independent (stale) copy. Fixed by only persisting a variation-level copy when the variation already owned its own resources, or when the submitted set actually differs from the inherited set (via a new `resources_match()` helper that normalizes the default 'active' status before comparing).

Fix is in `includes/class-wgdp-product-meta.php`. Released as v3.4.57.
