**Follow-up (decide later):** adopt the automated release flow (Flow A) from the wp-plugin-updater-guide — add `build_plugin.py` at the repo root and `.github/workflows/release.yml` (publish-on-tag), vs. staying on manual releases (Flow B). Validated 2026-07-02: confirmed no `.github/workflows/` or build script exists yet.

Re-evaluated 2026-07-02 (session 3) after finding releases had drifted (last GitHub Release was v3.4.25 while code had moved to v3.4.43 across ~18 unreleased version bumps): the drift happened because no release had been *requested*, not because manual building is error-prone — owner's actual process is on-demand, human-gated releases (owner asks, states the version, Claude packages the zip and pushes the GitHub Release), not automatic-on-every-commit. Flow A's main value (catching forgotten releases) doesn't apply to that process. Its other two benefits — failing on tag/`Version:` header mismatch, and avoiding a Windows backslash-in-zip corruption risk by building on Linux — can both be handled manually each release (check the version match; build the zip with a tool that forces forward slashes, e.g. Python `zipfile` or PHP `ZipArchive`, instead of a Windows compress command) without standing up CI infrastructure.

Net: not clearly worth building right now given the owner's workflow. Owner wants to decide later rather than close it out — revisit if the release cadence changes (e.g. wanting releases to auto-publish on every merge to main) or if manual releases start causing repeated mistakes.

---

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
