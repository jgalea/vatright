<h1 align="center">VAT Right – EU VAT &amp; OSS for WooCommerce</h1>

<p align="center">
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/LICENSE-GPL-5C9E31?style=for-the-badge"></a>
  <a href="https://github.com/jgalea"><img alt="Built by" src="https://img.shields.io/badge/BUILT%20BY-JEAN%20GALEA-8A2BE2?style=for-the-badge"></a>
</p>

<p align="center"><b>VAT Right is free EU VAT handling for WooCommerce: number validation, reverse charge, the OSS threshold and a decision record on every order.</b></p>

## What it does

The plugin decides the VAT treatment of each order, applies it through WooCommerce's own tax calculation and stores the decision, with its inputs, on the order.

- VAT number field on the block checkout, classic checkout, My Account and the admin order screen, checked against VIES (EU), HMRC (GB, with your own credentials), the Swiss UID register and the Brønnøysund register (Norway).
- Optional VAT number field on the My Account registration form (off by default), checked by the same registry pipeline. A registry outage never blocks registration.
- Per-role VAT exemption (no roles by default), recorded on the order as its own rule. A role isn't a legal basis for exemption on its own, so correctness is up to the merchant.
- Reverse charge for valid B2B numbers, with the required wording on the order, emails and invoice text.
- Origin VAT below the EUR 10,000 OSS threshold, destination VAT above it, switching automatically.
- Digital goods use location evidence, with a self-certification prompt when it conflicts. An optional setting blocks checkout until the customer confirms their country, on both the block and classic checkout.
- Standard and reduced rates imported from the EU's TEDB, with a diff before anything changes.
- Outage policy: by default an unavailable registry means the order is accepted, VAT is charged and the order is flagged and re-checked later.
- VAT column on the orders list (number, validation status, treatment) with a pending/flagged filter, on HPOS and legacy orders.
- Admin email when cross-border EU consumer sales reach a share of the OSS threshold (80% by default) and again when they pass it.
- OSS return and EC Sales List reports with CSV export, plus REST endpoints.
- Optional business-only mode that requires a valid VAT number at checkout, everywhere, in the EU or in chosen countries.
- Monthly INTRASTAT dispatch report for physical goods, with per-product CN8 code, country of origin and net mass, and CSV export.
- Personal data exporters, erasers and a retention setting.

Settings are under WooCommerce > Settings > Tax > VAT Right. `readme.txt` has the FAQ and the list of external services the plugin calls.

## Requirements

- WordPress 6.6 or later
- WooCommerce
- PHP 7.4 or later

## Install

Download the zip, upload it under Plugins > Add New > Upload Plugin and activate it. Tick "Enable tax rates and calculations" under WooCommerce > Settings > General if it isn't already, since WooCommerce hides the Tax tab until you do. Then open the VAT Right tax section and read the setup check.

## Known limitations

Each of these errs on the side of charging VAT.

- Consulting, legal, advertising and similar services to consumers outside the EU (Article 59 of the VAT Directive) are charged the store's VAT.
- Business customers in the UK are treated as consumers unless the merchant adds their own HMRC API credentials. Swiss and Norwegian numbers that can't be checked at checkout are charged VAT until they're re-checked.
- In a cart that's partly exempt because of a domestic exemption, the exempt line is charged VAT.
- On carts in a currency other than EUR, the threshold display updates at checkout, not on every cart recalculation.

## Development

```
composer install
npm install
npx wp-env start
```

Tests never call live services; registry responses come from recorded fixtures.

```
composer test:unit
composer lint
composer stan
npx wp-env run tests-cli --env-cwd=wp-content/plugins/vatright vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration
npx playwright test
```

`build/build.sh` builds the release zip.

## Disclaimer

VAT Right is a tool, not tax advice. You, the store owner, are responsible for your VAT treatment and your filings. VAT rates and registry results come from third parties and can be wrong or out of date, so check them with an accountant. The plugin is provided without warranty and without liability, under the GPL.
