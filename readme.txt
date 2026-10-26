=== VAT Right – EU VAT & OSS for WooCommerce ===
Contributors: jeangalea
Tags: vat, woocommerce, eu vat, oss, reverse charge
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.5
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Validates EU VAT numbers, applies reverse charge or destination VAT, tracks the OSS threshold and records every VAT decision on the order.

== Description ==

The plugin decides which VAT treatment each WooCommerce order gets, applies it through WooCommerce's own tax calculation, and records the decision with its inputs on the order. It's free, has no Pro version, and shows no branding in your store.

Settings live under WooCommerce > Settings > Tax > VAT Right.

**What it does**

* Adds a VAT number field to the block checkout, the classic checkout, My Account and the admin order screen. Numbers are checked for format first, then against the official registry.
* An optional VAT number field on the My Account registration form (off by default), checked by the same registry pipeline. A registry outage never blocks registration.
* Per-role VAT exemption (no roles by default): orders from customers with a role you choose are exempt, with the role recorded on the order. A role isn't a legal basis for exemption on its own, so correctness is up to you.
* Registries: VIES for EU numbers, HMRC for GB numbers (needs your own free HMRC developer credentials, see the FAQ), the Swiss UID register for CHE numbers and the Brønnøysund register for Norwegian numbers.
* Applies reverse charge to B2B sales with a valid number, with the wording required on the order, emails and invoice text (filterable and translatable). A valid number from your own country never exempts unless you switch that on.
* Charges B2C customers your country's VAT below the EUR 10,000 OSS threshold and the destination country's VAT above it, switching automatically. You can also tell the plugin you're OSS-registered to always charge destination VAT.
* Digital products use location evidence (billing country, shipping country and geolocation). When the evidence conflicts, the customer is asked to confirm their country, and an optional setting blocks checkout until they do (block and classic checkout).
* Imports standard and reduced rates from the EU's TEDB into your WooCommerce tax classes. It shows a diff first and never changes a rate until you apply it. A tax class mapping screen sets which EU rate each class follows, per country.
* Optional fixed gross prices across EU countries for stores that enter prices with tax.
* Records the decision on every order: the number, the registry answer, the VIES consultation number when you've entered your own VAT number, the location evidence and the rule that applied. An EU VAT box on the order screen shows it, with a decision log and a button to re-check the number.
* Re-checks numbers that couldn't be validated at checkout and can email you when one validates after the order was placed.
* A VAT column on the orders list shows each order's VAT number, its validation status and the treatment applied, with a filter for orders pending validation or flagged. Works with HPOS and the legacy order screen.
* Emails the store admin when cross-border EU consumer sales reach a share of the EUR 10,000 OSS threshold (80% by default) and again when they pass it, once a year each. Edit or turn it off under WooCommerce > Settings > Emails.
* Quarterly OSS return and EC Sales List reports with CSV export, in EUR. Orders in other currencies are converted at the ECB reference rate.
* Optional business-only mode: customers must give a valid VAT number to order, everywhere, in the EU or in countries you choose, on the block and classic checkout.
* Monthly INTRASTAT report of physical goods dispatched to other member states, with CN8 code, country of origin and net mass per product, and CSV export. The CSV is the data to file; thresholds and file formats differ per member state.
* REST endpoints for both reports and for the VAT data of a single order, restricted by capability.
* A setup check lists what's missing: your VAT number, tax rates for destination countries, geolocation and HMRC credentials.
* Works with HPOS and with the block and classic checkouts. Integrates with WooCommerce Subscriptions renewal hooks, see the FAQ.
* Registers WordPress personal data exporters and erasers, and a retention setting for the stored data.

== Installation ==

1. Make sure WooCommerce is active, then install and activate VAT Right.
2. Go to WooCommerce > Settings > General and tick "Enable tax rates and calculations". WooCommerce hides the Tax tab until you do.
3. Go to WooCommerce > Settings > Tax > VAT Right and read the setup check.
4. Enter your store VAT number so VIES can return a consultation number for each check.
5. Apply the EU rates in the rates table at the bottom of the screen, or map your tax classes first.

== Frequently Asked Questions ==

= What happens when VIES is down? =

Checkout still works. By default the order is accepted, VAT is charged and the order is flagged as pending validation. The plugin checks the number again later and, if it validates, marks the order and can email you so you can refund the VAT. The setting "When a registry is unavailable" offers two alternatives: accept the order, exempt the VAT and re-check later, or don't accept the order. Registry requests time out after a few seconds. Exempting while a registry is down lets anyone enter a made-up number for that country, so the VAT is yours to recover if the re-check fails.

= How do I validate UK VAT numbers? =

HMRC only answers requests that carry credentials from a registered application, so you need your own. They're free.

1. Create an account at the [HMRC Developer Hub](https://developer.service.hmrc.gov.uk/).
2. Register an application and subscribe it to the VAT Registered Companies API (check a UK VAT number).
3. Copy the client ID and client secret from the application.
4. Paste them into WooCommerce > Settings > Tax > VAT Right > HMRC.

Without credentials, GB numbers are only format-checked and a business customer in the UK is treated as a consumer.

= Does it work with WooCommerce Subscriptions? =

The plugin hooks into the WooCommerce Subscriptions renewal and switch hooks. A renewal is decided as a new sale: the subscription's stored registry answer is reused while it's fresh, otherwise the number is checked again, and the decision is stored on the renewal order. If a renewal can't be decided, VAT is charged. This has been tested against a stand-in for the Subscriptions hooks, not against the WooCommerce Subscriptions plugin itself.

= What personal data does the plugin store, and for how long? =

It stores the customer's VAT number, the registry's name, address and consultation number for it, and the IP country evidence used for digital sales, on the order, in the plugin's ledger table and, for logged-in customers, in their account. After the retention period (10 years by default, counted from the end of the year of the sale, which matches the OSS record-keeping period) the plugin removes the personal data from the decision record and ledger rows and keeps the scheme, rule, amounts and country evidence. Exporters and erasers are registered with the WordPress privacy tools. All plugin data is kept when you delete the plugin, unless you tick "Delete all plugin data when the plugin is deleted".

= Which services does the plugin call? =

See "External services" below. Nothing else is contacted, and the plugin makes no request unless a VAT number, a rate update or a currency conversion needs it.

= Are there known limitations? =

Each of these errs on the side of charging VAT.

* Consulting, legal, advertising and similar services to consumers outside the EU (Article 59 of the VAT Directive) are charged your store's VAT.
* Business customers in the UK are treated as consumers unless you add your own HMRC API credentials. Swiss and Norwegian numbers that can't be checked at checkout are charged VAT until they're re-checked.
* In a cart that's partly exempt because of a domestic exemption, the exempt line is charged VAT.
* On carts in a currency other than EUR, the threshold display updates at checkout, not on every cart recalculation.
* A valid VAT number removes VAT without checking that the buyer is that company, so a published number belonging to someone else would pass.

== External services ==

The plugin contacts the services below. It never sends them an order's contents, amounts or the customer's name.

**VIES (European Commission).** Checks EU VAT numbers. Sent when a customer enters a number: the VAT number and, if you've set one, your store VAT number as requester. Used on the checkout, My Account, the admin order screen and in scheduled re-checks. [VIES](https://ec.europa.eu/taxation_customs/vies/), [legal notice](https://commission.europa.eu/legal-notice_en), [privacy policy](https://commission.europa.eu/privacy-policy-websites-managed-european-commission_en).

**HMRC VAT API (UK).** Checks GB VAT numbers, only when you've entered HMRC credentials. Sent: the VAT number, your HMRC client ID and secret to obtain a token, and your GB VAT number if you've set one. [Terms of use](https://developer.service.hmrc.gov.uk/api-documentation/docs/terms-of-use), [privacy notice](https://www.gov.uk/government/organisations/hm-revenue-customs/about/personal-information-charter).

**Swiss UID register (Swiss Federal Statistical Office).** Checks CHE numbers. Sent: the number. [UID register](https://www.uid.admin.ch/), [terms](https://www.admin.ch/en/terms-and-conditions).

**Brønnøysund Register Centre (Norway).** Checks Norwegian organisation numbers. Sent: the number. [Terms of use](https://www.brreg.no/en/use-of-data-from-the-bronnoysund-register-centre/), [privacy statement](https://www.brreg.no/en/privacy-statement/).

**EU Taxes in Europe Database, TEDB (European Commission).** Source of the standard and reduced VAT rates. Sent when you fetch rates or the scheduled check runs: a rate query with no customer data. [TEDB](https://ec.europa.eu/taxation_customs/tedb/), [legal notice](https://commission.europa.eu/legal-notice_en), [privacy policy](https://commission.europa.eu/privacy-policy-websites-managed-european-commission_en).

**European Central Bank.** Source of the euro reference rates used to convert orders in other currencies for the reports. Sent: a currency code and date. [Disclaimer](https://www.ecb.europa.eu/services/disclaimer/html/index.en.html), [privacy](https://www.ecb.europa.eu/services/data-protection/html/index.en.html).

== Disclaimer ==

VAT Right is a tool, not tax advice. You, the store owner, are responsible for your VAT treatment and your filings. VAT rates and registry results come from third parties and can be wrong or out of date, so check them with an accountant. The plugin is provided without warranty and without liability, under the GPL.

== Screenshots ==

1. Block checkout with a valid VAT number and the reverse charge notice.
2. Classic checkout with the VAT number field and the notice in the order review.
3. The VAT Right section under WooCommerce > Settings > Tax.
4. The EU VAT box on an order, with the decision and its inputs.
5. The EU VAT reports: OSS return and EC Sales List.

== Changelog ==

= 0.1.5 =
* New: option to block checkout until the customer confirms their country when the billing and IP countries disagree, on the block and classic checkouts. Off by default.
* New: business-only mode, requiring a valid VAT number to order, everywhere, in the EU or in chosen countries.
* New: INTRASTAT report with CSV export and REST endpoint, and per-product commodity code, country of origin and net mass.
* New: VAT number field on the registration form, off by default.
* New: VAT-exempt user roles, recorded on every order they exempt.
* New: VAT column and VAT-status filter on the orders list.
* New: email warning when cross-border EU consumer sales near the EUR 10,000 threshold, and again when they pass it.
* Fix: a VAT number typed while creating an account at the classic checkout is now saved to the account.

= 0.1.4 =
* Fix: on hosts where WordPress fetches over cURL with OpenSSL 3, VIES checks failed and business customers were charged VAT. Registry requests now ask for uncompressed answers.

= 0.1.3 =
* Fix: a number VIES rejects (invalid input or blocked) is now treated as invalid, not as the registry being unavailable.
* When VIES turns the store away for too many requests, VAT is charged and the number checked later, whatever the outage setting.
* Uninstall removes the plugin's own options one by one instead of flushing the whole object cache.

= 0.1.2 =
* Fix: with "When a registry is unavailable" set to exempt the VAT, a number that was never checked against a registry is now charged VAT and flagged, and only a registry that was tried and failed leads to the exemption.

= 0.1.1 =
* First release.
