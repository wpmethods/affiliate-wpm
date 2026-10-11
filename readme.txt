=== Affiliate WPM ===
Contributors: wpmethods
Tags: woocommerce, affiliate, commission, referral, payout
Requires at least: 6.5
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight WooCommerce affiliate commissions and manual withdrawal management by WP Methods.

== Description ==

* Global percentage or fixed commission per purchased unit.
* Product overrides and product-level commission exclusion; variations inherit the parent.
* Configurable once-per-customer or multiple-purchase commission eligibility.
* Signed, HTTP-only referral cookie, adjustable duration, first/last attribution.
* WooCommerce My Account enrollment, referral link, balance, commissions, and withdrawal history.
* Minimum withdrawal and affiliate currency settings.
* Manual payout method repeater, one or multiple active methods, and payout pause control.
* Pending → approved → completed withdrawals, with rejection and visible manager notes.
* Refund adjustments, duplicate order protection, serialized balance updates and transactional reservations.
* WooCommerce order CRUD for HPOS and classic storage; classic and Checkout Block hooks.
* No remote analytics, payment SDKs, tracking scripts, recurring polling, or frontend framework.
* Referral click counters and registered-customer totals on admin and affiliate dashboards.
* A paginated affiliate customer list revealing only first names.
* Optional administrator approval or rejection for new affiliates; automatic joining by default.
* Overview lists showing the latest five affiliates and withdrawal requests, with full-list links.
* Editable plain-text email templates for admin and affiliate notifications.
* Dashboard color settings, card radius and a custom CSS editor.

== Installation ==

1. Place AffiliateWPM in wp-content/plugins, or upload the provided ZIP.
2. Activate WooCommerce, then activate Affiliate WPM.
3. Open WooCommerce → Affiliate WPM → Settings.
4. Set commissions, affiliate currency, cookie duration and minimum withdrawal.
5. Select Manual payout; add payout methods and check the ones you want active.
6. Customers join at My Account → Affiliate dashboard.

If the account endpoint returns 404, save Settings → Permalinks once.

== Commission rules ==

Percentages use line totals after discounts and exclude shipping and tax. Fixed amounts are per purchased unit, capped at the line total; zero-value items do not earn fixed commission. Rates and eligible item values are captured at checkout. Existing orders are not backfilled. Pending orders keep commissions pending; only completed orders release earnings. Cancelled, failed and refunded orders reject earnings. Product edits and global setting changes do not rewrite existing rates.

Partial line refunds reduce percentage commissions and prorate fixed commissions. Unallocated refunds, including shipping-only refunds, reduce commissions proportionally to the order total. A changed or refunded order rejects any open withdrawal containing it and releases its reserved balance. Refunds after completed payouts create a negative balance adjustment deducted from future withdrawals; the plugin does not retrieve money already sent. Administrators should review outstanding debts before deleting an affiliate user.

Affiliate currency defaults to the WooCommerce store currency. Only matching-currency orders earn commission; this plugin does not provide currency conversion or multi-currency gateway integration. Currency changes preserve old records and balances; switch back to that currency to withdraw its balance. Financial tables use decimal amounts; calculations round to WooCommerce price precision.

== Repeat purchase commissions ==

WooCommerce → Affiliate WPM → Settings → Repeat purchase commissions offers:

* Every eligible referred purchase (default): the same customer can earn affiliate commission on subsequent referred orders, including different products and new referral links.
* Once per customer: only one eligible commission-earning order per customer across all affiliates, links and products. Pending eligible orders reserve the allowance so simultaneous checkouts cannot both earn commission. Guest orders are matched by normalized billing email using a keyed hash; registered orders are also matched by customer account ID. Different accounts and different email addresses cannot always be recognized as the same person.

Both choices still require a valid referral cookie and the usual product, currency and self-referral checks. A non-referred purchase does not use the once allowance. Zero-commission products do not consume it. Cancelled or fully refunded unpaid commissions release the allowance for future orders; previously excluded orders are not automatically promoted. An already paid commission continues to count, with refunds deducted from the affiliate balance as usual.

The choice is captured at checkout and applies only to new orders. Existing orders keep their saved choice; orders created before this setting behave as multiple-purchase orders. When once mode is used, previous commission-earning orders count toward the allowance. Historical customer identities are indexed in batches of 100 through WooCommerce order CRUD; large stores may need Action Scheduler retries before a new once-mode order's commission appears. Identities cannot be recovered for old commissions whose original orders were already deleted before upgrading. Do not remove the awpm_identity_key option while retaining commission records.

== Manual payouts ==

Customers request the entire available balance, after debt adjustments. Only one open withdrawal is allowed per affiliate. The plugin reserves that balance until completion or rejection. Approve the request, send payment yourself using the provided destination, and only then mark it paid. No automatic bank, PayPal, Stripe or mobile-wallet transfer is performed. Changing or removing a payout method does not change an existing request's saved method and details.

In single-method mode only the first checked method remains active. Multiple-method mode allows customers to select one of the active methods for each withdrawal. Blocking an affiliate stops new referral tracking and withdrawals; historical commissions still settle, and existing withdrawal requests remain available for manager review.

== Referral statistics ==

Valid referral link visits increment daily aggregate click counters. Consecutive clicks on the same affiliate link in the same browser count once within 30 minutes using a separate signed HTTP-only cookie. These are referral clicks, not unique visitors or a fraud-proof analytics metric. Self-clicks, inactive affiliates, and tracking vetoed by the consent filter do not count.

New customer/subscriber registrations with a valid referral cookie are attributed once to the affiliate under the current first/last attribution setting. Staff accounts and existing customer accounts do not count as new referred registrations. Affiliates see only their own customers' first names, with billing first name as a fallback and “Customer” when both are missing. No surname, email, username, avatar, profile link or customer ID is displayed in this list. Counts start with version 1.1.0; previous clicks and registrations cannot be reconstructed. Deleting accounts or using WordPress privacy erasure removes associated registration records and affiliate aggregate counters. New registrations do not create an additional lifetime-order commission rule; order attribution continues to follow the referral cookie.

== Privacy and security ==

All administration requires manage_woocommerce. Account actions require login and a nonce; users only see their own financial records. Referral cookies are HMAC-signed, SameSite=Lax, HTTP-only and Secure on HTTPS. User-ID and billing-email self-referrals are excluded. Alternate-account abuse still requires store review. Payment details are stored in the site's database, accessible to store managers and database administrators; protect site access and backups. Never request passwords or card security codes.

Set page caching to bypass URLs with awpm_ref and WooCommerce account/checkout pages. Full-page caches may prevent PHP from running to set the referral cookie. The plugin adds suggested privacy policy content in Settings → Privacy. Integrate consent using:

add_filter( 'awpm_tracking_allowed', function ( $allowed, $affiliate_id ) {
    return your_consent_check();
}, 10, 2 );

The consent filter defaults to allowing tracking. Configure it to match your store's consent policy. No IP addresses or visitor click logs are collected. WordPress personal-data exports include affiliate information and approval status. The personal-data eraser removes enrollment, approval and referral metadata but reports financial records as retained for administrator review. Financial records and affiliate metadata are deliberately retained on deactivation and uninstall. Review and remove records according to your store's retention requirements. Notification emails use the WordPress mail service and may be processed by your configured SMTP provider. No remote analytics or automatic payout service is contacted.

== Affiliate approval ==

Settings → Affiliate account approval defaults to Join automatically. Require administrator approval applies to new applications. Applicants see Pending, receive a confirmation email, and cannot track referrals or request withdrawals until approved. Review applications in the Affiliates tab using Approve or Reject. Rejected accounts may be approved on later review. Repeated submissions do not reset reviewed accounts or resend joining emails. Existing affiliates remain approved; enabling review does not revoke them. Disabling review does not automatically approve existing pending applications. Block/Unblock remains separate from application approval.

== Email templates ==

The Emails tab configures the administrator notification address, sender address/name, and nine editable plain-text email templates. Templates have individual enable switches, subjects and messages. Events cover enrollment (admin and affiliate), application approval/rejection (affiliate), withdrawal creation and status changes (admin and affiliate), and newly available commissions (affiliate). Placeholders are listed in the editor; unsupported event-specific values are blank. Withdrawal destination details are never emailed.

Emails use wp_mail after successful data commits. Mail failure does not undo enrollment or payments. Repeated state callbacks do not resend the same transition notification. Delivery depends on your site's mail configuration; use a suitable sender address and your WordPress SMTP/mail service. This plugin does not provide a separate SMTP transport, delivery queue, or delivery tracking.

== Customization ==

The Customization tab controls accent/buttons, text, secondary text, borders, card backgrounds, highlighted metric backgrounds and card radius (0–24px). Colors apply to both the plugin administration panel and the customer affiliate dashboard. Custom CSS should use .awpm selectors. CSS is loaded only on the plugin panel and affiliate account endpoint, and HTML is removed from CSS input. Editing custom CSS requires unfiltered_html as well as manage_woocommerce; managers without unfiltered_html can edit colors but retain the existing CSS. Multisite administrators may require the appropriate network capability for custom CSS.

== Developer notes ==

Tables: {prefix}awpm_commissions, {prefix}awpm_payouts, {prefix}awpm_clicks and {prefix}awpm_customers, using InnoDB. Named MySQL/MariaDB locks serialize balance mutations per affiliate. Commission order IDs and referred customer IDs are unique. Failed order updates are retried via WooCommerce Action Scheduler. Ensure the database supports GET_LOCK and transactions. Assets load only on the plugin admin page and account endpoint.

Development checks: php tests/integration.php --run, php tests/account-actions.php --run and php tests/referral-stats.php --run (set mysqli.default_port for Local if needed). These create disposable test fixtures and clean them up. The integration suite disables email and webhook delivery in the test process. Run only on a development store. The suites cover commission rules, payout lifecycle, referral integrity, capabilities, privacy tools, endpoint recognition, Join and withdrawal POST handlers, account assets, click deduplication, customer registration attribution and first-name-only disclosure. php tests/package.php creates an installable ZIP without test fixtures or development scripts.

== Changelog ==

= 1.3.0 =
Add five-item Overview lists, optional affiliate approval and rejection, editable email notifications, and dashboard appearance settings with custom CSS.

= 1.2.0 =
Add once-per-customer and multiple-purchase commission settings with order snapshots, indexed customer matching, historical commission indexing, and cross-affiliate customer locks. Explain excluded repeat purchases in commission history.

= 1.1.0 =
Add referral click counters, registered-customer counts and a first-name-only customer list. Include registration attribution in privacy exports and remove it through privacy erasure and account deletion.

= 1.0.1 =
Register the My Account endpoint with WooCommerce so Join submissions, withdrawal submissions and dashboard assets work correctly. Display errors for unavailable enrollment and failed saves. Add account POST regression coverage.

= 1.0.0 =
Initial release.
