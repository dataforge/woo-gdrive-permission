**Follow-up:** cut a GitHub release for v3.4.13 so the auto-updater ships the latest fixes to installed sites.

------

## 🟡 MEDIUM severity

| #    | File:Line                                   | Issue                                                        |
| :--- | :------------------------------------------ | :----------------------------------------------------------- |
| 6    | `class-wgdp-order-handler.php:258` + `:327` | Order-status-hook path creates entitlements **without** the `with_order_item_lock()` wrapper used by admin/self-service paths. Concurrent triggers (webhook retry + status transition, or `wcpr_order_charge_succeeded` racing `woocommerce_order_status_processing`) can both pass the non-atomic existence check and insert duplicate rows. |
| 7    | `class-wgdp-claim-page.php:497`             | Resend rate limit is keyed per-`entitlement_id` but `issue_otp_for_recipient_group()` re-mails **all siblings**. A holder of N sibling claim tokens gets N independent 3/hr buckets → 3·N notification emails/hr per group. Key the bucket on `order_item_id`+`recipient_email`. |
------

## 🟢 LOW severity / hardening

- `class-wgdp-db.php:139` — rate limiter is a sliding window (transient TTL resets on each consume), not fixed. Allows roughly double the intended rate at window edges.
- `class-wgdp-google-auth.php:148` — caches refresh token for a hard-coded 55 min regardless of actual `expires_in`; shorter-lived tokens served stale. Use `min(55*MINUTE, expires_in - 60)`.
- `class-wgdp-cron.php:404-408` — `unschedule()` clears recurring hooks but not pending `wgdp_process_backfill` single events.
- `class-wgdp-self-service.php:219` — order-key compared with `!==` (timing-early-exit) instead of `hash_equals()`; the newer `sst` path correctly uses `hash_equals`.
- `class-wgdp-product-meta.php:529` — `save_variation_meta()` doesn't verify `woocommerce_meta_nonce` (relies on upstream WC), unlike `save_product_meta()` which does.
- `class-wgdp-entitlements.php:181-223` — sibling token transfer on revoke runs outside `with_recipient_group_lock()` while the issuing side is locked — concurrent revoke+resend can leave duplicate active tokens.
- `class-wgdp-classic-checkout.php:130-143` — "too many recipients" guard iterates by index; sparse arrays (`recipients[KEY][999]`) bypass the validation message (harmless since `array_slice(0,$qty)` clamps at save).
- `wgdp-admin.js:819,947,...` — `alert('Error: '+response.data)` renders `[object Object]` when `data` is an object.
- `class-wgdp-google-auth.php:572-631` — CBC fallback path has no HMAC (defense-in-depth gap; only triggers when neither libsodium nor AES-GCM available).