## Open items

### LOW

- **Updater performs no checksum/signature verification of the downloaded release zip** — `class-wgdp-updater.php:198-207` (`get_asset_url()`, picks the release zip's `browser_download_url` with no hash/signature check), `class-wgdp-updater.php:46-52` (`check_update()` hands that URL straight to WP core's upgrader). Beyond WP's default HTTPS transport, a compromised GitHub release (or forged-cert MITM) would install arbitrary code. Industry-typical for GitHub-updater plugins. Fixing this properly needs release-side signing infrastructure (e.g. GitHub Actions signing the zip) plus verification logic here — out of scope for a small patch, left open.

- **No 429/rate-limit-specific backoff in Drive API wrapper** — `class-wgdp-google-drive.php` (`create_permission`/`delete_permission`/`get_permission`/`list_files`/`get_file`) all treat HTTP 429 like any other error, with no dedicated backoff. Not currently a practical bug: cron already retries every ~20 min up to 50 times (~16.6 hrs of backoff headroom), which comfortably outlasts any real Google throttling window. Left open as a low-priority hardening item.

- **`recalculate_sales_counter()` / `recalculate_variation_sales_counter()` update the paid-qty counter with no lock** — `class-wgdp-release-gate.php`, unlike every other counter-mutating path in this codebase, uses a plain `update_post_meta()` with no lock. If an admin's "Recalculate sales" action runs its order scan in the narrow window between `WGDP_Order_Handler::update_sales_counter()` incrementing the counter and persisting `_wgdp_qty_counted_items` for that same order, it could compute a stale total and silently clobber the just-incremented counter. Requires an admin action to coincide with an in-flight order-status transition; no automated exploit path. Left open as a documented risk.
</content>
