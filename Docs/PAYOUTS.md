Automatic PayPal/Stripe payout features described here require the separate Refmora Pro add-on and an active license. Free supports manual payout methods without activation.

# PayPal and Stripe affiliate payouts

Configure providers in WooCommerce > Refmora > Settings > Payout settings > Automatic payouts. Only administrators can configure credentials. Manual bank/mobile-wallet methods remain available, including Bangladesh methods.

## Stripe: one Secret key

1. Enable Stripe Connect and complete your platform profile in Stripe Dashboard.
2. In Refmora select Sandbox / Test or Live. Enter that environment's platform Secret key (`sk_test_...` or `sk_live_...`). Enable automatic payouts and save.
3. The plugin verifies the platform and attempts to create its webhook destinations automatically. You do not need a Publishable key (`pk_...`), OAuth Client ID (`ca_...`) or registered OAuth redirect URI.
4. An affiliate selects their country in the Affiliate Area and clicks **Connect with Stripe**. Stripe-hosted onboarding collects identity and bank details. The plugin creates a Standard connected account and retains its account ID, country, creation time and platform binding. Country and cross-border eligibility are determined by your Stripe platform and Stripe rules; listing a country does not guarantee eligibility.
5. The affiliate can resume incomplete onboarding. Requests verify current transfer capability and bank payout eligibility. A browser redirect alone never proves completion.
6. An administrator approves a withdrawal and explicitly clicks **Send payment** (or **Run Sandbox payout**). Transfers use the merchant's Stripe balance, not the WooCommerce checkout gateway. Bank payout timing follows the connected account's Stripe settings.

Test and Live credentials remain encrypted and separate behind the same field. Blank means keep the saved key. Switching environments never moves a typed Test key into Live. Existing OAuth account connections and their frozen payout jobs are retained; they are not replaced with newly created accounts.

References:
- https://slicewp.com/docs/paying-your-affiliates-stripe/
- https://docs.stripe.com/connect/hosted-onboarding

## Stripe webhooks

The plugin uses the v1 Accounts/Transfers APIs and two snapshot destinations:
- Platform events: `transfer.reversed`.
- Connected-account events: `account.updated`.

It does not use SliceWP's v2 recipient-account API, so it does not require their thin v2 event destination. Signed callbacks validate the raw body, five-minute timestamp tolerance and Test/Live environment. Transfer reversals are also confirmed by an authenticated Stripe API lookup before any financial adjustment. Partial reversals restore source-currency earnings proportionally; cumulative amounts make repeat/older deliveries harmless. Current reservations on later withdrawals are preserved, so a reversal does not release funds being sent elsewhere. Dashboard paid totals reflect net settled commission amounts.

On a public HTTPS site, save settings to register webhook destinations. For a local `.local` site such as cs-dev.local, Stripe cannot reach the site directly. Use Stripe CLI forwarding or a public HTTPS test URL. Under **Advanced webhook setup**, enter the forwarding or manually created destination's `whsec_...` signing secret for each event source. Only signing secrets are accepted; they are never displayed after saving. Re-save after changing the site URL. Connect eligibility and webhook delivery still require verification against your own provider account.

## PayPal: Client ID and Secret

1. Use a verified PayPal Business account with Payouts access and enough funds. Live access must be granted by PayPal.
2. In PayPal Developer Dashboard > Apps & Credentials, select Sandbox or Live and create/open a REST API application.
3. Choose the matching environment in Refmora and enter its Client ID and Secret in the shared fields. Enable payouts and save. The plugin checks authentication without sending money.
4. Affiliates select PayPal and enter their PayPal account email. Sandbox requires a Sandbox recipient account.
5. The administrator approves and explicitly sends the withdrawal. Unclaimed/pending payments stay reserved until PayPal confirms success or failure.

References:
- https://slicewp.com/docs/paying-affiliates-paypal-payouts/
- https://developer.paypal.com/payouts/use-payouts/overview

## Currency, safety and privacy

Commission accounting uses the WooCommerce base currency. PayPal uses the configured supported payout currency; Stripe uses the connected account's default currency. A saved exchange rate from FOX/WOOCS, CURCY or manual rates converts the request when necessary. Amount, source currency and exchange rate are frozen per withdrawal. Provider eligibility and available balance in the transfer currency are still required.

Sandbox/Test success releases the reserved commission balance without marking real earnings paid. Live transfers settle only after provider confirmation. Duplicate sends reuse a durable payment identity. Unknown payments stay reserved and stop being resent after the safe retry window. Refunds during sending retain the original allocation and adjust future earnings. Do not change the original credentials or environment while a payment is processing.

Credentials and webhook signing secrets are AES-GCM encrypted with WordPress salts, excluded from settings backups and never inserted back into forms. Stripe collects identity and banking data; the plugin caches only account ID and payout readiness. Personal-data export includes Stripe connection IDs; erasure removes connection and pending onboarding metadata. Financial withdrawal/provider records remain for reconciliation; erasure does not delete the external Stripe account.

No real payment is sent merely by saving credentials, connecting an affiliate or approving a request. Automatic sends require the explicit administrator payment action. The plugin uses background provider polling to confirm payments; Stripe webhooks additionally reconcile later reversals and invalidate cached account readiness.