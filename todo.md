## Reference

- Build and deployment guide: https://github.com/dataforge/wp-plugin-updater-guide

## Open items

### HIGH

- **Missing-Email list and assignment slot check disagree on `revocation_error` rows** — FIXED. `get_unassigned_order_items()` / `count_unassigned_order_items()` (and the slot-occupancy counts `count_active_recipients_for_item()` / `count_confirmed_recipients_for_item()`) now use `grant_status NOT IN ('revoked', 'revocation_error')`, matching `assign_recipient_to_order_item()`'s `already_has_slot` exclusion. An order item whose only occupant is a recipient stuck in `revocation_error` now shows an open slot in the Missing Email list / unassigned counts, and assignment succeeds.

- **Self-service per-order/IP rate limits are burned on every authorized submission and never refunded on validation failure** — FIXED. `ajax_self_service_email()` now refunds both the `wgdp_ss_order_` and `wgdp_ss_ip_` tokens via the new `release_rate_limit()` helper (mirroring the OTP-resend policy) whenever the submission produced no side effect — no entitlement created and no email delivered (the final "No entitlements were created" branch). Tokens are kept when an entitlement was actually reserved.

### MEDIUM

- **`set_refund_total()` uses the deprecated `VALUES()` upsert alias** — FIXED. `class-wgdp-db.php:180-185` now uses `INSERT ... AS new ON DUPLICATE KEY UPDATE refunded_qty = new.refunded_qty`.

- **Backfill jobs table grows unbounded** — FIXED. Added `prune_backfill_history()` in `class-wgdp-cron.php`, called from the hourly `expire_stale_entitlements()` cron. It deletes `completed`/`failed` rows older than 30 days (filterable via `wgdp_backfill_retention_days`) under the `wgdp_backfill_prune` named lock.

- **Dashboard widget `get_recent_failures()` is uncached and scans the whole entitlements table on every dashboard load** — FIXED. `get_recent_failures()` now caches its result in a `wgdp_recent_failures_<limit>` transient with a 2-minute TTL (short enough that staleness is negligible), mirroring `get_permission_counts()`.

- **IP-based throttling trusts `REMOTE_ADDR` only** — FIXED (self-service side). `get_request_ip()` in `class-wgdp-self-service.php` now honors the `X-Forwarded-For` chain only when the direct peer is listed in the new `wgdp_trusted_proxies` filter (walking right-to-left past configured proxies); for untrusted peers the header is ignored since it is client-spoofable. Note: the claim-page resend bucket (`class-wgdp-claim-page.php:596`) is keyed on `order_item_id + recipient_email`, not IP, so it is not affected by the shared-REMOTE_ADDR problem. The pre-auth `wgdp_ss_auth_` bucket benefits from the same `get_request_ip()` fix.

- **Claim-page inline CSS piggybacks on the `wp-block-library` handle** — FIXED. `class-wgdp-claim-page.php:37-39` now registers/enqueues its own `wgdp-claim-page-inline` handle (with `WGDP_VERSION`), matching self-service, so the OTP input styling no longer depends on Gutenberg's styles being enqueued.

### LOW

- **Partial-refund candidate selection can include recipients already in `revocation_error`** — FIXED. `get_revocation_candidates()` now filters with `grant_status NOT IN ('revoked', 'revocation_error')`, so a recipient whose Drive delete is still retrying is no longer re-selected for the same item's next partial refund.

- **`create_entitlements_for_recipient()` reuses `$siblings[0]['recipient_index']` with no ORDER BY** — FIXED. `get_siblings()` now orders by `id ASC`, making the "reuse the recipient's existing seat" branch deterministic regardless of how a recipient's rows ever diverge.

- **`retry_grant_reprovision()` uses `mark_revoked()` on stale error rows without a Drive-side delete** — FIXED. `class-wgdp-admin.php:1473-1476` now routes rows that carry a `provider_permission_id` through `revoke_with_drive_delete()`, and only uses the cheap `mark_revoked()` path for rows with no Drive permission.

- **Access Manager pagination total ignores injected rows** — FIXED (minimal). `inject_unassigned_seats()` and `inject_missing_email_items()` now return their injected-row counts, and `prepare_items()` folds them into `total_items`/`total_pages` so the displayed row count matches the reported total. The page-1-only missing-email injection (unassigned seats disappearing on later pages) is inherent to the unpaginated injection design and remains a cosmetic limitation — folding unassigned rows into the paginated query is still the eventual fix if the table grows.

- **Updater performs no checksum/signature verification of the downloaded release zip** — left open. Verified accurate (`get_asset_url()` picks the release zip's `browser_download_url` with no hash/signature check; `check_update()` hands that URL to WP core's upgrader). Fixing properly needs release-side signing infrastructure (e.g. GitHub Actions signing the zip) plus verification logic here — out of scope for a small patch.

- **No 429/rate-limit-specific backoff in Drive API wrapper** — left open. Verified accurate (all Drive wrapper methods treat HTTP 429 like any other error with no dedicated backoff). Not a practical bug: cron already retries ~20 min up to 50 times, outlasting any real Google throttling window. Left open as a low-priority hardening item.
