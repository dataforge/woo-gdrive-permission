## Reference

- Build and deployment guide: https://github.com/dataforge/wp-plugin-updater-guide

## Open items

### LOW

- **Updater performs no checksum/signature verification of the downloaded release zip** — left open. Verified accurate (`get_asset_url()` picks the release zip's `browser_download_url` with no hash/signature check; `check_update()` hands that URL to WP core's upgrader). Fixing properly needs release-side signing infrastructure (e.g. GitHub Actions signing the zip) plus verification logic here — out of scope for a small patch.

- **No 429/rate-limit-specific backoff in Drive API wrapper** — left open. Verified accurate (all Drive wrapper methods treat HTTP 429 like any other error with no dedicated backoff). Not a practical bug: cron already retries ~20 min up to 50 times, outlasting any real Google throttling window. Left open as a low-priority hardening item.
