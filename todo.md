**Follow-up:** cut a GitHub release for v3.4.13 so the auto-updater ships the latest fixes to installed sites.

------

## 🟡 MEDIUM severity

| #    | File:Line                                   | Issue                                                        |
| :--- | :------------------------------------------ | :----------------------------------------------------------- |
| 6    | `class-wgdp-order-handler.php:258` + `:327` | Order-status-hook path creates entitlements **without** the `with_order_item_lock()` wrapper used by admin/self-service paths. Concurrent triggers (webhook retry + status transition, or `wcpr_order_charge_succeeded` racing `woocommerce_order_status_processing`) can both pass the non-atomic existence check and insert duplicate rows. |
------

## 🟢 LOW severity / hardening

- `class-wgdp-db.php:139` — rate limiter is a sliding window (transient TTL resets on each consume), not fixed. Allows roughly double the intended rate at window edges.
- `class-wgdp-product-meta.php:529` — `save_variation_meta()` doesn't verify `woocommerce_meta_nonce` (relies on upstream WC), unlike `save_product_meta()` which does.
- `class-wgdp-entitlements.php:181-223` — sibling token transfer on revoke runs outside `with_recipient_group_lock()` while the issuing side is locked — concurrent revoke+resend can leave duplicate active tokens.
- `class-wgdp-classic-checkout.php:130-143` — "too many recipients" guard iterates by index; sparse arrays (`recipients[KEY][999]`) bypass the validation message (harmless since `array_slice(0,$qty)` clamps at save).
- `wgdp-admin.js:819,947,...` — `alert('Error: '+response.data)` renders `[object Object]` when `data` is an object.
- `class-wgdp-google-auth.php:572-631` — CBC fallback path has no HMAC (defense-in-depth gap; only triggers when neither libsodium nor AES-GCM available).