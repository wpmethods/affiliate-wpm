# Affiliate WPM — directory submission notes

This source has been prepared for the WordPress.org Free directory. Approval remains a manual decision by the Plugins team. No submission, ZIP file or git tag was created.

## Two separate plugins

- `affiliate-wpm` is the complete Free core. All included functionality works without license activation. Submit only this folder to WordPress.org.
- `affiliate-wpm-pro` is a separately distributed premium add-on. Never include it or its update-checker library in the Free package or directory SVN.
- Free provides enrollment, tracking, commissions, general settings, sharing, one manual payout method and JSON settings backup/restore/reset.
- Pro implements automatic PayPal/Stripe payouts, exchange sources/conversion, CSV exports and up to 20 manual methods. Free contains only contextual previews and extension hooks for these features.
- Both plugin headers, runtime version constants and readme stable tags match the currently installed version (1.0.0 at this checker review). Version metadata was not changed during the checker cleanup. Set release metadata consistently when publishing a future release.

This separation follows guidelines 5 and 8: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/

## Submission source

Use the clean `affiliate-wpm` source folder supplied separately. It includes PHP, readable JavaScript/CSS, GPL license text, readme and offline documentation. It excludes `.git`, Pro code, the external update checker, credentials, test fixtures and temporary reports.

Verify `wpmethods` is your actual WordPress.org contributor account and that your contact email is current. Check ownership/licensing of the logo and all bundled assets. Submit with your account at https://wordpress.org/plugins/developers/add/ when you are ready. Respond to the review team's questions before publication. Once the directory slug is approved, keep the folder, text domain and native-update slug consistent with it.

## Updates and Pro server deployment

Free registers no update-transient manipulation filters and makes no license or external update requests. Native WordPress.org updates become available after the Free plugin is approved and its releases are published there. Installing the separately hosted Pro add-on does not replace Free updates.

Pro retains the existing license API at `https://wpmethods.com/LicenseValidationAffiliateWPM/api.php`. Its default metadata endpoint is `https://wpmethods.com/updates-affiliate-wpm/`; `AWPM_PRO_UPDATE_URL` can override it.

Before distributing Pro updates, configure that server to respond to `slug=affiliate-wpm-pro`, return metadata with `slug` set to `affiliate-wpm-pro`, and provide a Pro archive with the `affiliate-wpm-pro` top-level folder. It must not deliver the old all-in-one Free archive. Version must be higher than the installed version for an update notice. This server change was not performed or verified by the local source work. Publish accurate WP Methods service privacy/terms information for licensing and downloads. Pro's readme describes provider communications and data sent.

## Verification and remaining review warnings

Official Plugin Check 2.1.0, `plugin_repo` category: **0 errors and 3 naming warnings** on the clean Free source after review. Regression checks passed: 66 admin query/filter checks, 43 Free/security checks, 45 commission checks and 238 Pro payment/financial checks. HTTP and mail were intercepted; disposable fixtures were removed. See [PLUGIN-CHECK.md](PLUGIN-CHECK.md) for the code fixes, reviewed exceptions and remaining naming warnings.

The remaining naming warnings require review. The other boundaries below now have per-statement review annotations and must be reconsidered if their code changes:

- The trademark check flags the `wp` substring in “WPM” and `affiliate-wpm`. Its source explicitly describes `wp` as allowed with a warning. Keep the existing brand for review; follow the review team's naming decision and update slug/text domain if they require a different name.
- Custom ledger/withdrawal/click tables use direct SQL. Only four internal table suffixes are accepted. Identifiers use %i placeholders; user-supplied values and list-search conditions use prepared parameters. Balance/reservation and transaction reads intentionally remain fresh instead of relying on stale cached financial data. Search and repeat-purchase SQL now use complete prepared templates. Review placeholders and concurrency boundaries if changing these queries.
- Mutating admin forms call `AWPM_Security::admin_request()` to enforce POST, capability and nonce. The static scanner does not follow this helper. Affiliate actions and Quick Checkout validate their own nonces. Read-only pagination/search GET requests do not change store data.
- Array input reaches dedicated cleaners in settings/sharing/email/design; JSON uploads are checked with `is_uploaded_file`, size limits and schema validation. Review those cleaners rather than assuming raw array input is safe.
- Compatibility constants and hooks such as `DONOTCACHEPAGE`, `DONOTCACHEDB` and LiteSpeed hooks intentionally use the external integration's names.

This was a local static directory review, not a complete audit of every host, payment provider or cache configuration. Run Plugin Check again on the exact submission package and test a fresh WordPress/WooCommerce installation before public submission. Provider testing used mocked responses; no real payout was sent.

## Existing stores and data

Free and Pro share the existing accounts and financial tables. Downgrading does not erase license state, credentials, extra manual-method configuration, rate preferences or provider jobs. Free exposes one manual method and uses the WooCommerce base currency. Existing saved currency snapshots still reconcile historical records.

Keep Pro installed until outstanding automatic payments and reversals are reconciled. After a license expires, no new automatic transfer is sent; a previously saved remote payment can still be confirmed. JSON settings backup is configuration backup, not a substitute for a database backup. No secrets are included in the submission source.
