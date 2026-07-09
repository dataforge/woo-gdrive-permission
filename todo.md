**Follow-up (decide later):** adopt the automated release flow (Flow A) from the wp-plugin-updater-guide — add `build_plugin.py` at the repo root and `.github/workflows/release.yml` (publish-on-tag), vs. staying on manual releases (Flow B). Validated 2026-07-02: confirmed no `.github/workflows/` or build script exists yet.

Re-evaluated 2026-07-02 (session 3) after finding releases had drifted (last GitHub Release was v3.4.25 while code had moved to v3.4.43 across ~18 unreleased version bumps): the drift happened because no release had been *requested*, not because manual building is error-prone — owner's actual process is on-demand, human-gated releases (owner asks, states the version, Claude packages the zip and pushes the GitHub Release), not automatic-on-every-commit. Flow A's main value (catching forgotten releases) doesn't apply to that process. Its other two benefits — failing on tag/`Version:` header mismatch, and avoiding a Windows backslash-in-zip corruption risk by building on Linux — can both be handled manually each release (check the version match; build the zip with a tool that forces forward slashes, e.g. Python `zipfile` or PHP `ZipArchive`, instead of a Windows compress command) without standing up CI infrastructure.

Net: not clearly worth building right now given the owner's workflow. Owner wants to decide later rather than close it out — revisit if the release cadence changes (e.g. wanting releases to auto-publish on every merge to main) or if manual releases start causing repeated mistakes.

---

## Code review findings (2026-07-02, session 4) — remaining items

Re-validated 2026-07-09 (session 5) against v3.4.49. Items below are still open;
the rest of the original list (bulk-action locking, atomic_increment_meta error
masking, claim-page dedup sibling lock, expire_stale reactivation cutoff,
recipient_index consistency, ajax_bulk_revoke email dedup) was fixed in v3.4.50.

### MEDIUM

- **`refresh_access_token` reads `refresh_token` outside the lock** — `class-wgdp-google-auth.php:99, 113-121`. `$accounts` is read at line 99 (no lock), the HTTP refresh runs at 113-121 using that snapshot, and the lock is only taken for the write-back at line 144. If a concurrent refresh in another process caused Google to rotate the refresh token, this process submits the now-superseded token, which Google rejects; the winner's stored update can then be shadowed.

- **Self-service `save_token_records` triggers a full `$order->save()` on every token issuance, and tokens are issued on every order-email render** — `class-wgdp-self-service.php:94-97, 126-142` via `build_self_service_url`/`render_email_link`. `render_email_link` → `build_self_service_url` → `issue_self_service_token` → `save_token_records` → `$order->save()` is on the hot path of processing/completed/invoice email renders, which WooCommerce resends on many admin actions. Produces excessive order-meta writes and re-fires order-status-change hooks on every email send.

- **Cron grant/revocation retry queue has no dead-letter at the retry cap** — `class-wgdp-cron.php:168, 245` + `class-wgdp-entitlements.php:1049, 1066`. Once `grant_retries`/`revocation_retries` reaches the 50 cap, the row falls out of both `get_failed_verified` and `get_failed_revocations` and is neither retried nor flagged as permanently failed — it becomes a silently orphaned entitlement that surfaces nowhere. (v3.4.49 fixed the cursor-pagination starvation issue but not this dead-letter gap.)

### LOW

- **Updater performs no checksum/signature verification of the downloaded release zip** — `class-wgdp-updater.php:169-178, 194-200`. Beyond WP's default HTTPS transport, a compromised GitHub release (or forged-cert MITM) would install arbitrary code. Industry-typical for GitHub-updater plugins, but worth noting.

- **Activation page creation doesn't detect slug collision** — `woo-gdrive-permission.php:113-117`, `class-wgdp-self-service.php:274-282`, `class-wgdp-claim-page.php:64-72`. If `wgdp-provide-email` or the claim slug already exists as another post's slug, WP appends `-2` and the configured `wgdp_claim_page_slug` setting silently drifts from the actual page slug.
