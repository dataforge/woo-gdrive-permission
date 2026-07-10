**Follow-up (decide later):** adopt the automated release flow (Flow A) from the wp-plugin-updater-guide — add `build_plugin.py` at the repo root and `.github/workflows/release.yml` (publish-on-tag), vs. staying on manual releases (Flow B). Validated 2026-07-02: confirmed no `.github/workflows/` or build script exists yet.

Re-evaluated 2026-07-02 (session 3) after finding releases had drifted (last GitHub Release was v3.4.25 while code had moved to v3.4.43 across ~18 unreleased version bumps): the drift happened because no release had been *requested*, not because manual building is error-prone — owner's actual process is on-demand, human-gated releases (owner asks, states the version, Claude packages the zip and pushes the GitHub Release), not automatic-on-every-commit. Flow A's main value (catching forgotten releases) doesn't apply to that process. Its other two benefits — failing on tag/`Version:` header mismatch, and avoiding a Windows backslash-in-zip corruption risk by building on Linux — can both be handled manually each release (check the version match; build the zip with a tool that forces forward slashes, e.g. Python `zipfile` or PHP `ZipArchive`, instead of a Windows compress command) without standing up CI infrastructure.

Net: not clearly worth building right now given the owner's workflow. Owner wants to decide later rather than close it out — revisit if the release cadence changes (e.g. wanting releases to auto-publish on every merge to main) or if manual releases start causing repeated mistakes.

---

## Code review findings (2026-07-02, session 4) — remaining items

Re-validated 2026-07-09 (session 5) against v3.4.49. Fixed in v3.4.51: the
`refresh_access_token` lock-race and the `save_token_records` full-order-save
issue (see below). The rest of the original list (bulk-action locking,
atomic_increment_meta error masking, claim-page dedup sibling lock,
expire_stale reactivation cutoff, recipient_index consistency,
ajax_bulk_revoke email dedup) was fixed in v3.4.50.

### MEDIUM

- **Cron grant/revocation retry queue has no dead-letter at the retry cap** — `class-wgdp-cron.php:168, 245` + `class-wgdp-entitlements.php:1049, 1066`. Once `grant_retries`/`revocation_retries` reaches the 50 cap, the row falls out of both `get_failed_verified` and `get_failed_revocations` and is no longer auto-retried. Re-validated 2026-07-09: the row is not fully invisible — it still shows up in the dashboard widget's `grant_status = 'error'` count and in the access-manager admin table (which queries by status, not retry count), and admins can manually resend from there. So it's not a silent orphan, but the admin UI doesn't distinguish "still auto-retrying" from "gave up after 50 tries," which could read as a live retry when it's actually stalled. Worth a small UI/label fix later, not a data-loss bug.

### LOW

- **Updater performs no checksum/signature verification of the downloaded release zip** — `class-wgdp-updater.php:169-178, 194-200`. Beyond WP's default HTTPS transport, a compromised GitHub release (or forged-cert MITM) would install arbitrary code. Industry-typical for GitHub-updater plugins, but worth noting.

- **Activation page creation doesn't detect slug collision** — `woo-gdrive-permission.php:113-117`, `class-wgdp-self-service.php:274-282`, `class-wgdp-claim-page.php:64-72`. If `wgdp-provide-email` or the claim slug already exists as another post's slug, WP appends `-2` and the configured `wgdp_claim_page_slug` setting silently drifts from the actual page slug.
