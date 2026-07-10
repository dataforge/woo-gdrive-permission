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
