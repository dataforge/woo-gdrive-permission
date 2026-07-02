**Follow-up:** cut a GitHub release for v3.4.13 so the auto-updater ships the latest fixes to installed sites.

------

## 🟢 LOW severity / hardening

- `class-wgdp-product-meta.php:529` — `save_variation_meta()` doesn't verify `woocommerce_meta_nonce` (relies on upstream WC), unlike `save_product_meta()` which does.
- `class-wgdp-entitlements.php:181-223` — sibling token transfer on revoke runs outside `with_recipient_group_lock()` while the issuing side is locked — concurrent revoke+resend can leave duplicate active tokens.
- `class-wgdp-classic-checkout.php:130-143` — "too many recipients" guard iterates by index; sparse arrays (`recipients[KEY][999]`) bypass the validation message (harmless since `array_slice(0,$qty)` clamps at save).
- `class-wgdp-google-auth.php:572-631` — CBC fallback path has no HMAC (defense-in-depth gap; only triggers when neither libsodium nor AES-GCM available).
