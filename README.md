# Billions Payments for WHMCS

Official [Billions.works](https://billions.works/) hosted-checkout payment gateway for WHMCS. Developed with LLM assistance and maintained in this repository. Licensed under [MIT](LICENSE).

## Installation

Download the [latest WHMCS module ZIP](https://github.com/billions-works/billions-payments-for-WHMCS/releases/latest/download/billions-payments-for-whmcs.zip) and extract it into the root directory of your WHMCS installation. It adds only these files:

```text
modules/gateways/billions.php
modules/gateways/billions/Client.php
modules/gateways/callback/billions.php
```

Then activate **Billions.works** under WHMCS **System Settings → Payment Gateways** and configure:

- **Public API key:** the merchant API key issued by Billions. It is stored in a masked WHMCS password field and used only server-side.
- **Unique WHMCS instance ID:** 3–30 ASCII letters, digits, underscores or hyphens. Give each WHMCS installation its own ID if they share a merchant account. Do not change it after accepting payments.

Set the WHMCS System URL to its public HTTPS address, including any subdirectory (for example `https://billing.example.com/whmcs/`). Billions sends notifications to `https://billing.example.com/whmcs/modules/gateways/callback/billions.php?invoiceid=<id>`. The callback endpoint must be publicly reachable, and the server must be able to access `https://api.billions.works` over HTTPS. Enable only currencies your Billions merchant account accepts; USD and EUR can be invoice currencies.

The module targets currently supported WHMCS versions with PHP 8.2/8.3 and the cURL extension. Source lint and unit tests run on both PHP versions in CI. A live WHMCS installation is still needed for end-to-end acceptance testing.

## Payment flow

WHMCS displays a **Pay Now** link that creates a Billions invoice through the Public API. The stable `order_no` and `idempotencyKey` prevent a new invoice from being created each time the WHMCS invoice page is opened. If the WHMCS invoice amount changes after the Billions invoice was created, the module refuses to reuse the old payment link; an administrator must reconcile it before accepting payment.

The callback never credits a WHMCS invoice from the notification body alone. It retrieves the Billions invoice using the merchant API key and checks the order number, currency, amount, payment gateway and current WHMCS balance. It then uses WHMCS's callback helpers to check for duplicate transactions, log and apply a verified `completed` payment. Other Billions statuses leave the WHMCS invoice unpaid. No HMAC secret is required for this API-retrieval verification flow.

Subscriptions and automatic refunds are not implemented. Billions currently does not provide a checkout return URL through the Public invoice-creation API, so customers should return to WHMCS manually to view their updated invoice. If a notification cannot be verified, the callback returns a non-success HTTP response for retry; administrators should reconcile missed payments in the Billions dashboard.

Billions [Privacy Policy](https://billions.works/privacy) · [Terms of Service](https://billions.works/terms)

## Development

The PHP gateway code is under `modules/gateways/`. Run `php tests/ClientTest.php` for the dependency-free validation tests. Tagged releases run PHP 8.2/8.3 checks and publish an installable ZIP with the paths above. See [WHMCS's third-party gateway guide](https://developers.whmcs.com/payment-gateways/third-party-gateway/) and [callback guidance](https://developers.whmcs.com/payment-gateways/callbacks/).
