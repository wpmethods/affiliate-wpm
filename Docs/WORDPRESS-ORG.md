# Refmora ? directory submission notes

Refmora is the proposed new display name and directory slug (refmora). Initial public searches did not identify a relevant exact-name plugin or affiliate business. This is not trademark clearance or a slug reservation. Request the new slug in the existing WordPress.org review thread; do not create a second submission. The review team makes the final decision.

## Free and separate Pro source

Submit only the clean refmora folder. Exclude .git, Pro, vendor libraries, credentials and test fixtures. Do not submit refmora-pro to WordPress.org.

All functionality included in Free works without payment, activation, trial periods or tier quotas. Free includes enrollment, referral tracking, commission calculations, waiting periods, product rules, all general settings, sharing, multiple manual payout methods, manager settlement and JSON settings backup/restore/reset. Merchants can choose a single active method or multiple active methods; this is their own configuration, not a tier limit.

Pro implements automatic PayPal/Stripe API payouts, exchange-rate conversion and CSV exports in its own source. Its entitlement checks are in the separate add-on. Free contains only extension bridges and contextual add-on information for these features. Free core updates exclusively use the normal WordPress.org mechanism after directory publication. Pro has its own externally hosted updater and never replaces the core.

## Existing installations

Rebranding preserves AFFM classes, refm_ options/tables, order/user metadata, signed referral cookies and encryption identities. These are compatibility identifiers, not the public plugin slug. Existing custom account slugs remain; new installs default to /my-account/refmora/, and the /my-account/refmint/ alias continues to work. Settings JSON accepts the previous refmint-settings format. All manual methods stay available when Pro is removed or its license expires. Financial history and saved currency snapshots remain intact.

Version remains 1.0.0. No ZIP, git tag or version bump was created.

## Pro service compatibility

The current add-on keeps its configured license API and default metadata URL https://wpmethods.com/updates-refmint/ . Its server-side product/metadata identity remains refmint-pro for existing entitlements. These remote identifiers are deliberately not renamed locally. Before publishing updates, the server must deliver an add-on package rooted at refmora-pro/ and retain valid existing license bindings. AFFM_PRO_UPDATE_URL can override the endpoint. This work does not deploy or verify the remote service. Keep Pro installed while submitted payments/reversals need reconciliation.

## Verification

See PLUGIN-CHECK.md for the latest static checks and regression results. Capabilities and POST/nonces are checked before privileged changes; structured settings are sanitized by dedicated schema cleaners. Product commission saves additionally verify WooCommerce's product nonce. Read-only search and pagination do not require mutation nonces. Public referral URLs use validated signed attribution rather than an expiring guest nonce.

Plugin Check is a tool, not directory approval. Verify ownership/licensing of all assets and the contributor account before submitting. Reply briefly in the existing reviewer thread and explicitly request the refmora slug.

References:
- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/plugins/security/nonces/
- https://developer.wordpress.org/plugins/security/securing-input/
