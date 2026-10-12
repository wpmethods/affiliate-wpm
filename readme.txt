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

* Configurable Share & Earn product section below the add-to-cart form, with selectable social icons.
* Same-store affiliate link generator for product and page URLs in My Account -> Affiliate Area.
* Compact product sharing icons for Facebook, WhatsApp, Telegram, X and LinkedIn, plus a copy-link icon.
* Dedicated Sharing, Emails and Customization tabs; organized program, commission, referral and payout settings.

* Responsive admin navigation, organized settings cards, persistent save controls and live appearance previews.
* Professional Affiliate Area with earnings cards, withdrawal progress and clear account status.
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
* A paginated affiliate customer list showing first names and server-masked contact details.
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
6. Customers join at My Account → Affiliate Area.

If the account endpoint returns 404, save Settings → Permalinks once.

== Commission rules ==

Percentages use line totals after discounts and exclude shipping and tax. Fixed amounts are per purchased unit, capped at the line total; zero-value items do not earn fixed commission. Rates and eligible item values are captured at checkout. Existing orders are not backfilled. Pending orders keep commissions pending; only completed orders release earnings. Cancelled, failed and refunded orders reject earnings. Product edits and global setting changes do not rewrite existing rates.

Partial line refunds reduce percentage commissions and prorate fixed commissions. Unallocated refunds, including shipping-only refunds, reduce commissions proportionally to the order total. A changed or refunded order rejects any open withdrawal containing it and releases its reserved balance. Refunds after completed payouts create a negative balance adjustment deducted from future withdrawals; the plugin does not retrieve money already sent. Administrators should review outstanding debts before deleting an affiliate user.

Affiliate currency defaults to the saved WooCommerce store currency, independent of a visitor's selected shop currency. Percentage commissions use the actual order line totals after discounts, excluding tax, converted into the affiliate currency. Fixed rates and minimum withdrawals are expressed in affiliate currency. Fixed commissions are capped by the converted line total. Exchange rates, source, source/target currencies and calculation precision are frozen per order; later rate or settings changes do not reprice existing commissions. Refunds use the same saved exchange rate. Affiliate amounts are formatted directly with their currency code to prevent shop currency filters from converting them a second time. Currency changes preserve old balances; select their original currency to withdraw them.

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

New customer/subscriber registrations with a valid referral cookie are attributed once to the affiliate under the current first/last attribution setting. Staff accounts and existing customer accounts do not count as new referred registrations. Affiliates see their own customers' first names, with billing first name as a fallback and “Customer” when both are missing. The list includes masked email (up to four local-part characters plus a hidden domain) and phone (up to three leading digits). Full contact details, surname, username, avatar, profile link and customer ID are not displayed. Counts start with version 1.1.0; previous clicks and registrations cannot be reconstructed. Deleting accounts or using WordPress privacy erasure removes associated registration records and affiliate aggregate counters. New registrations do not create an additional lifetime-order commission rule; order attribution continues to follow the referral cookie.

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

The Customization tab controls accent/buttons, text, secondary text, borders, card backgrounds, highlighted metric backgrounds and card radius (0–24px). Colors apply to both the plugin administration panel and the customer affiliate dashboard. Custom CSS should use .awpm selectors. CSS is loaded on the plugin panel, affiliate account endpoint, and enabled product sharing pages, and HTML is removed from CSS input. Editing custom CSS requires unfiltered_html as well as manage_woocommerce; managers without unfiltered_html can edit colors but retain the existing CSS. Multisite administrators may require the appropriate network capability for custom CSS.

== Developer notes ==

Tables: {prefix}awpm_commissions, {prefix}awpm_payouts, {prefix}awpm_clicks and {prefix}awpm_customers, using InnoDB. Named MySQL/MariaDB locks serialize balance mutations per affiliate. Commission order IDs and referred customer IDs are unique. Failed order updates are retried via WooCommerce Action Scheduler. Ensure the database supports GET_LOCK and transactions. Assets load on the plugin admin page, account endpoint, and enabled product sharing pages.

Release verification: 48 sharing checks and 88 security, payout and panel checks passed against a development WooCommerce store. Disposable fixtures were removed and mail/network delivery was intercepted. The installable ZIP contains production files only.

== Sharing & affiliate links ==

Configure Sharing from WooCommerce -> Affiliate WPM -> Sharing. Enable or disable social sharing, independently hide the product section, edit its heading, and choose individual channels. The section appears after the standard WooCommerce add-to-cart form. Custom product builders must retain woocommerce_after_add_to_cart_form to display it. Guests and inactive affiliates see an invitation to Affiliate Area; only approved, unblocked affiliates receive a personal product link.

Facebook, WhatsApp, Telegram, X and LinkedIn icons open their sharing pages with the encoded referral link. Channels without direct link sharing, including Messenger and TikTok, are omitted. The product row contains only its heading and icons, without a URL field or description. Non-affiliates see a small Join affiliate button. The copy icon copies the personal referral URL; a manual copy dialog is available if clipboard access fails. There are no external social SDKs or automatic third-party requests. Referral click statistics count incoming referral visits, not presses on the sharing icons.

The link generator appears immediately after the main referral link in Affiliate Area, independent of the product sharing switch. It requires an active affiliate account and a valid session nonce. It accepts HTTP/HTTPS URLs for this store's host and port, preserves query parameters and fragments, and replaces any previous awpm_ref with the current affiliate's token. External URLs and URLs containing credentials or control characters are rejected. Account, checkout, cart, administrative and action URLs, private or password-protected content, and URLs carrying session/security keys are also rejected. Product links use the parent product URL. Personalized product responses send no-cache headers; exclude logged-in customers from full-page caching.

== Currency conversion ==

WooCommerce -> Affiliate WPM -> Settings -> Commission rules includes Exchange rate source and Manual exchange rates.

Automatic uses the active FOX/WOOCS global object's get_currencies() table or CURCY's get_list_currencies() API (including configured rate fees). Rates are target-rate / source-rate relative to the provider's base. Historical CURCY wmc_order_info tables and FOX _woocs_order_rate/_woocs_order_base_currency metadata are used when they contain the complete conversion pair. Only currencies actually configured in the provider are accepted; free edition currency limits still apply. If two providers supply conflicting rates, select FOX or CURCY explicitly. Changing the customer's displayed currency does not change an existing order's stored currency or affiliate amount.

Without a switcher, enter manual pairs: FROM TO RATE, one per line. RATE means 1 FROM equals RATE TO. Example only: USD BDT 120 defines 1 USD = 120 BDT and derives BDT -> USD by dividing by 120. This is not a live market rate; enter the rate your store actually uses. Automatic falls back to manual pairs when no provider supplies the pair; Manual rates only uses the pairs exclusively. Same currency only restores the original matching-currency behavior. The plugin never fetches external rates, guesses 1:1 for different currencies or changes provider settings.

Missing/invalid rates leave the original referral and commission-rate metadata intact, create an internal order note and save _awpm_fx_error; no invented financial record or endless scheduled retries are created. After configuring a rate, use Recover a missing currency commission with the order ID. This requires manage_woocommerce, POST and a nonce; it only handles attributed orders without a commission record. Where historical provider rates are unavailable, recovery freezes the currently configured rate, as explained in the panel. Existing paid/reserved financial records are protected from recovery. New conversions are captured when the order is first processed, after its actual checkout currency is available; classic checkout and Store API hooks use the same calculation. Orders without saved affiliate attribution cannot be recovered from a URL alone.

Currency switchers are not included or activated by Affiliate WPM. This release reviewed FOX free 1.5.4 and CURCY free 2.2.18 and exercised their actual rate API classes in isolated development checks. Site-specific gateway, theme and premium-extension checkout behavior should be verified on the store's own setup.
== Changelog ==

= 1.0.1 =
* Optional waiting period of 1-365 days after order completion, with automatic release. Immediate availability remains the default.
* Waiting periods saved per completed order; refunds and cancellations rechecked before release.
* Terms & Conditions moved to the end of Settings. Manual exchange rates shown only for Automatic or Manual rates only.

= 1.0.0 =
Initial release.

== License and updates ==
Open WooCommerce > Affiliate WPM > License to activate your WP Methods license. An active license is required for Settings, updates and new affiliate activity. Existing attributed order commissions, refunds, payout processing and history remain available. PHP OpenSSL is required for encrypted credential storage. Changing WordPress authentication salts requires entering the license key again to recover the activation. Temporary verification outages allow a seven-day update grace period after the last successful verification. See docs/license-api.md for the server contract.
== Tools ==
Tools offers JSON backup and restore for program, sharing, email and appearance settings. License credentials and product commission overrides are not included in the backup. Reset restores those four settings groups to their defaults; it does not delete license credentials, affiliate accounts, product overrides, orders, commissions, withdrawals, clicks or referred customer records. Type RESET in the form to confirm. Restore and reset require administrator permissions and an active license. CSV downloads are available to store managers and administrators, cover all statuses and preserve original transaction currencies. Referred customer email and phone values are masked. Withdrawal exports contain payment destination details. CSV cells that could execute spreadsheet formulas are prefixed with an apostrophe. Export downloads are generated on request and not saved publicly.
