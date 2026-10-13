# Plugin Check review — Affiliate WPM

Reviewed with official Plugin Check 2.1.0, `plugin_repo` category, on 14 October 2026.

## Result

- Before: 0 errors, 277 warnings.
- After: 0 errors, 3 warnings. All three are `trademarked_term` warnings about the `wp` substring in the existing Affiliate WPM name/readme and `affiliate-wpm` slug.
- The brand and slug were retained. The check's source lists `wp` as allowed with a warning, although its displayed message says restricted. Ask the WordPress.org review team to confirm the name; this report is not directory approval.

## Code changes

- Table identifiers now use the `%i` placeholder, and values use the corresponding `%s`/`%d` placeholders in `wpdb::prepare()`.
- Admin search/status/pagination queries use complete prepared templates instead of assembling already-escaped SQL fragments. The numeric order-ID column is also an identifier placeholder.
- Repeat-purchase matching uses explicit prepared customer hash/account-ID parameters. Guest account ID zero does not match unrelated guests.
- Referral counters use prepared global/per-affiliate queries. Nested pending counters preserve identifier/value argument order.
- Only four known plugin table suffixes are accepted by `AWPM_Store::table()`.
- Request methods, withdrawal details and generated-link URLs are unslashed/sanitized before use. Quick Checkout validates its nonce before accepting its action.
- Program settings are passed through the existing dedicated schema cleaner before being saved.

References: https://developer.wordpress.org/reference/classes/wpdb/prepare/ and https://developer.wordpress.org/plugins/security/nonces/

## Reviewed exceptions

Some warnings were static-analysis limitations or deliberate integration choices. They were documented with narrowly scoped `phpcs:ignore` comments at the relevant statements, not blanket file exclusions:

- The custom ledger, withdrawal and referral tables have no standard WordPress post/meta CRUD API. Their reads/writes use direct SQL. Financial and transaction reads remain fresh so stale cached balances cannot change withdrawal/refund decisions. Current history, privacy exports and counters are also read directly.
- `AWPM_Security::admin_request()` checks POST, capability and action-specific nonce. The checker cannot follow this helper into callers. Some IDs and nonce values must be read only to construct or validate their nonce; no mutation precedes verification.
- Public referral links need to work for guests without expiring WordPress nonces. Token format, affiliate eligibility, self-referral checks and the consent filter are applied. Other GET uses are sanitized read-only routing, search, pagination or feedback.
- Structured settings use dedicated cleaners rather than one generic text sanitizer, preserving allowed terms HTML, CSS and credential formats. Private uploads verify `is_uploaded_file()`, size and JSON schema after the Tools guard.
- The WooCommerce product-save callback runs after WooCommerce's nonce verification and checks `edit_post` capability.
- Cache constants and LiteSpeed hook names intentionally match the external integration's API.

These annotations do not remove runtime validation, disable security protections or add stale financial caches. Future changes to these boundaries must be reviewed again.

## Regression verification

- 66 admin search/filter/counter checks: pagination, combined status/search, affiliate/email/order IDs, escaped wildcards, injection attempts, malformed input and scoped counters.
- 43 Free/security checks: normal settings saves, invalid nonce, insufficient role, forbidden GET mutation, forged premium payload, manual payout and Quick Checkout action/nonce validation.
- 45 commission checks: completion, saved waiting period, scheduled release, cancellation, partial refund, repeat guest email and repeat registered account matching.
- 238 Pro payment/financial checks: existing payout safety, reservations, provider confirmation, reversals and license downgrade behavior.

All external HTTP/mail was intercepted. Temporary ledger fixtures and disposable accounts/orders were removed; existing store settings and financial records remained unchanged. No version bump, ZIP or git tag was created.

## Performance-query CSV follow-up

The additional CSV contained 12 `WordPress.DB.SlowDBQuery` warnings and the same 3 naming warnings. SlowDBQuery flags meta_key/meta_value/meta_query/tax_query statically; it does not measure execution time.

The top-selling query now sorts by WooCommerce's `wc_product_meta_lookup.total_sales` using its scoped popularity adapter. It no longer sorts/casts the `total_sales` postmeta field. It returns five IDs, skips total-row counting and keeps native WordPress query caching enabled. Commission eligibility and product type/visibility filters remain; their required metadata/taxonomy predicates are documented with statement-level performance exceptions. The scoped sorting filter is removed even when querying throws an exception.

Affiliate queries keep required enrollment/approval/block/token predicates. Native user-query caching is explicitly enabled; tracking returns one ID without counting results, Overview fetches five users without a total, and the full list remains paginated at ten users. These predicates were reviewed and annotated rather than removed to silence the checker. WordPress invalidates the user-query cache when metadata changes. No financial balance or payout reservation was moved into these caches.

Verification: 34 bestseller/copy/exclusion/filter-cleanup checks and 68 admin search/filter/native-cache checks passed. Product sales or commission-rule edits invalidate cached bestseller results; approval changes invalidate cached pending counts. Existing store settings and real financial records remained unchanged, and disposable fixtures were removed. Official `performant_wp_query_params` check reports no warnings; `plugin_repo` still reports only the 3 naming warnings.

No large-catalog or high-concurrency benchmark was performed. Metadata predicates and uncached total counts can still become costly on very large user/product datasets; profile real query timings before redesigning storage for that scale.