**Follow-up (decide later):** adopt the automated release flow (Flow A) from the wp-plugin-updater-guide — add `build_plugin.py` at the repo root and `.github/workflows/release.yml` (publish-on-tag), vs. staying on manual releases (Flow B). Validated 2026-07-02: confirmed no `.github/workflows/` or build script exists yet.

Re-evaluated 2026-07-02 (session 3) after finding releases had drifted (last GitHub Release was v3.4.25 while code had moved to v3.4.43 across ~18 unreleased version bumps): the drift happened because no release had been *requested*, not because manual building is error-prone — owner's actual process is on-demand, human-gated releases (owner asks, states the version, Claude packages the zip and pushes the GitHub Release), not automatic-on-every-commit. Flow A's main value (catching forgotten releases) doesn't apply to that process. Its other two benefits — failing on tag/`Version:` header mismatch, and avoiding a Windows backslash-in-zip corruption risk by building on Linux — can both be handled manually each release (check the version match; build the zip with a tool that forces forward slashes, e.g. Python `zipfile` or PHP `ZipArchive`, instead of a Windows compress command) without standing up CI infrastructure.

Net: not clearly worth building right now given the owner's workflow. Owner wants to decide later rather than close it out — revisit if the release cadence changes (e.g. wanting releases to auto-publish on every merge to main) or if manual releases start causing repeated mistakes.

---

### Follow-ups from session 3 (2026-07-02)

- **Bulk "Retry Grant" / "Resend OTP" (`process_am_bulk_actions()`, `class-wgdp-admin.php:126-193`) read-then-act per row without a lock.** Lower severity than the fixed single-item race (operates row-by-row rather than spanning a create), but a claim-page submission for the same order item during a bulk run can still interleave with `mark_error`/`issue_otp_for_recipient_group`. Worth wrapping each row's action in `with_order_item_lock` for consistency with the now-locked single-item paths, if this becomes a reported issue.

- **`atomic_increment_meta()` (`class-wgdp-release-gate.php:590-621`) can't distinguish "matched but value unchanged" from "row missing".** When decrementing an already-zero counter, the `GREATEST(0, ...)` clause means `$wpdb->query()` returns 0 affected rows even though the row exists and was matched (MySQL reports rows *changed*, not rows *matched*), so the code falls into the `add_post_meta(..., true)` fallback, which then silently no-ops because the key already exists. The numeric outcome happens to stay correct in this specific case, but a genuine write failure would be masked identically. Worth tightening (e.g. check `$wpdb->last_error`) if this function is ever extended.

---

## Code review findings (2026-07-02, session 4)

All items below validated against the actual code (each confirmed by re-reading
the cited lines). Not yet fixed.

### MEDIUM

- **`refresh_access_token` reads `refresh_token` outside the lock** — `class-wgdp-google-auth.php:99, 113-121`. `$accounts` is read at line 99 (no lock), the HTTP refresh runs at 113-121 using that snapshot, and the lock is only taken for the write-back at line 144. If a concurrent refresh in another process caused Google to rotate the refresh token, this process submits the now-superseded token, which Google rejects; the winner's stored update can then be shadowed.

- **`grant_drive_access_for_entitlement` dedup reuses a sibling entitlement's `provider_permission_id` without locking the sibling** — `class-wgdp-claim-page.php:419-456`. The dedup check calls `get_permission` on a *different* entitlement's id (`(int) $existing['id'] !== (int) $entitlement['id']`) and then `mark_granted` on the current entitlement, but `with_entitlement_lock` only covers the current entitlement's id, not the dedup target. A concurrent `revoke_with_drive_delete` on that sibling between `get_permission` and `mark_granted` can mark the current entitlement granted against a permission that was just removed.

- **Self-service `save_token_records` triggers a full `$order->save()` on every token issuance, and tokens are issued on every order-email render** — `class-wgdp-self-service.php:94-97, 126-142` via `build_self_service_url`/`render_email_link`. `render_email_link` → `build_self_service_url` → `issue_self_service_token` → `save_token_records` → `$order->save()` is on the hot path of processing/completed/invoice email renders, which WooCommerce resends on many admin actions. Produces excessive order-meta writes and re-fires order-status-change hooks on every email send.

- **Cron grant/revocation retry queue has no dead-letter at the retry cap** — `class-wgdp-cron.php:157, 230` + `class-wgdp-entitlements.php:1005, 1022`. Once `grant_retries`/`revocation_retries` reaches the 50 cap, the row falls out of both `get_failed_verified` and `get_failed_revocations` and is neither retried nor flagged as permanently failed — it becomes a silently orphaned entitlement that surfaces nowhere. (Separately, `class-wgdp-cron.php:238-241` drops failed revocation retries with `continue` and no `mark_error`/order note/error_log, making them unobservable between ticks.)

### LOW

- **`expire_stale` uses original `created_at` (not reactivation time) as the cutoff for reactivated rows whose OTP was never issued** — `class-wgdp-entitlements.php:1789-1797`. The fallback `claim_token_expires_at IS NULL AND created_at < (UTC_TIMESTAMP() - INTERVAL %d HOUR)` branch was added as a safety net for rows reactivated as pending without an OTP, but reactivation (`create_entitlements_for_recipient:1409-1424`) sets `claim_token_expires_at => null` without touching `created_at`, so such a row would be compared against its original (potentially weeks-old) creation timestamp and expired immediately on the next `expire_stale` run. Low impact in practice because `issue_otp_for_recipient_group` runs immediately after and sets the expiry.

- **`create_entitlements_for_recipient` can produce inconsistent `recipient_index` values across a recipient's files** — `class-wgdp-entitlements.php:1388-1432`. The `$recipient_index_locked` guard (1404-1407) only adopts a revoked row's seat index *inside* the resource loop, after earlier resources may have already been freshly created with `max_index + 1`. The seat can then end up inconsistent across files for the same recipient — exactly what the comment at 1400-1403 claims to prevent. The lock should be resolved before the resource loop, not within it.

- **`ajax_bulk_revoke` sends one revocation email per `order_item_id|email` group, diverging from the admin-tab bulk revoke** — `class-wgdp-entitlements-list.php:106-126` vs `class-wgdp-admin.php:194-216`. The admin-tab path dedups notifications per recipient; the AJAX path does not, so the same recipient across multiple line items receives multiple revocation emails.

- **Updater performs no checksum/signature verification of the downloaded release zip** — `class-wgdp-updater.php:169-178, 194-200`. Beyond WP's default HTTPS transport, a compromised GitHub release (or forged-cert MITM) would install arbitrary code. Industry-typical for GitHub-updater plugins, but worth noting.

- **Activation page creation doesn't detect slug collision** — `woo-gdrive-permission.php:113-117`, `class-wgdp-self-service.php:274-282`, `class-wgdp-claim-page.php:64-72`. If `wgdp-provide-email` or the claim slug already exists as another post's slug, WP appends `-2` and the configured `wgdp_claim_page_slug` setting silently drifts from the actual page slug.

---

## Code review findings (2026-07-09, session 5)

The tasks below are new findings from a full review of the current `3.4.43`
tree. They are intentionally not duplicates of the session 3/4 items above.

### MEDIUM

- [ ] **Do not let capped cron scans permanently starve later actionable entitlements** -- `includes/class-wgdp-cron.php:121-170`, `includes/class-wgdp-entitlements.php:983-1011`. Each cron run resets `$after_id` to zero and scans at most 200 rows. Pending/error rows whose release gate is still closed remain at the front forever without changing state or retry count; 200 such low-ID rows prevent higher-ID rows for already-released products from ever being reached. Persist a cursor with wraparound, query only currently actionable rows, or use a fair work queue. Test with more than 200 blocked rows preceding a released row.

- [ ] **Reject grants for assets no longer present in the product's active resource set** -- `includes/class-wgdp-claim-page.php:730-737`, `includes/class-wgdp-cron.php:24-31`, `includes/class-wgdp-release-gate.php:427-434`. All three helpers return "not retired" when the asset is absent from product metadata, so a pending claim/retry can grant a detached file after an interrupted product save, direct meta edit/import, or other missed revocation. This conflicts with the admin retry path, which explicitly refuses stale assets. Require positive membership in the effective active-resource list before any grant; mark/reprovision stale rows instead of sharing the old file.

### LOW

