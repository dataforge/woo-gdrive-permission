# Woo GDrive Permission

Per-recipient entitlement system with OTP verification for granting Google Drive viewer access on WooCommerce purchases.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce 7.0+
- Google Cloud project with Drive API enabled

## Features

- **Google Drive Access Control** — Automatically grants read-only Google Drive access (files or folders) when customers purchase digital products.
- **OTP Verification** — 6-digit one-time password sent to the recipient's email to verify ownership before granting access.
- **Self-Service Email Collection** — Customers provide their Google email via a link in their order confirmation email. If they enter the wrong address, they can simply go back and re-enter it.
- **Multi-Account Support** — Connect multiple Google accounts and assign different Drive resources per product or variation.
- **Variable Product Support** — Assign different Drive resources to different variations (e.g., DVD vs Digital).
- **Release Gates** — Control when digital content becomes available:
  - **Immediate** — Access granted on verification (default).
  - **Minimum Sales Qty** — Access granted once a sales threshold is reached.
  - **Manual Release** — Admin releases content manually.
- **Automatic Refund Handling** — Revokes Google Drive permissions when orders are refunded or cancelled. Partial refunds revoke excess entitlements.
- **Auto-Retry** — Failed Drive grants are retried automatically every 20 minutes.
- **Block Checkout Support** — Full integration with WooCommerce Blocks checkout.
- **HPOS Compatible** — Supports WooCommerce High-Performance Order Storage.
- **Dashboard Widget** — At-a-glance entitlement counts and recent failures on the WordPress dashboard.
- **Access Manager** — Admin interface to view, search, and manage all entitlements with bulk actions.

## Setup

### 1. Google Cloud Configuration

1. Create a project in [Google Cloud Console](https://console.cloud.google.com/).
2. Enable the **Google Drive API**.
3. Create **OAuth 2.0 credentials** (Web application type).
4. Add your site's callback URL as an authorized redirect URI:
   ```
   https://yoursite.com/wp-admin/admin.php?page=wgdp
   ```

### 2. Plugin Configuration

1. Install and activate the plugin.
2. Go to **WooCommerce > Woo GDrive Permission**.
3. Enter your OAuth Client ID and Client Secret.
4. Click **Add Google Account** and authorize access.
5. Optionally set a root folder scope to limit browsing.

### 3. Product Configuration

1. Edit a WooCommerce product (simple or variable).
2. In the product data panel, find the **GDrive Permission** tab.
3. Select a connected Google account.
4. Browse and select a Drive file or folder to grant access to.
5. Choose a release mode (Immediate, Minimum Sales, or Manual).

## Customer Flow

1. Customer places an order for a product with digital access.
2. Order confirmation email includes a **"Provide Google Email"** link.
3. Customer enters their Google account email on the self-service page.
4. A verification email is sent with a 6-digit OTP code and a claim link.
5. Customer clicks the claim link and enters the code.
6. Google Drive viewer access is granted and the customer receives an access confirmation email with a link to the content.

## Admin Tools

### Access Manager

Located at **WooCommerce > Woo GDrive Permission > Access Manager**:

- Summary cards showing counts by status (Granted, Pending, Error, Revoked, etc.).
- Searchable/filterable entitlement table.
- Inline actions: assign email, resend OTP, change recipient, verify permission, revoke access.

### Dashboard Widget

The **GDrive Entitlements** widget on the WordPress dashboard shows:

- Entitlement counts by status.
- Recent grant failures with error details.

## Email Notifications

The plugin sends three types of emails:

| Email | When | Contains |
|-------|------|----------|
| Verification | After email submitted | 6-digit OTP + claim link |
| Access Granted | After OTP verified | Google Drive link |
| Access Revoked | On refund or admin action | Revocation notice |

## License

GPL-2.0-or-later
