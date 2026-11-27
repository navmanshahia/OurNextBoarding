# Our Next Boarding
PHP 8.2+ / MySQL travel enquiry platform.
Deployment: create a database and user in cPanel Database Wizard. Upload public/ contents to public_html; keep private configuration outside the document root. Import schema.sql using phpMyAdmin. See configuration instructions in the application files. Never commit credentials. HTTPS required.


## Flight and hotel search (Nuitée)

The Flights and Hotels pages are available at `?page=flights` and `?page=hotels`. By default they offer the existing enquiry flow; no live supplier requests are made.

Requirements: PHP cURL with TLS verification, existing database and session configuration, and an activated Nuitée Connect API key. Add the following entry to the existing returned configuration array in **private/config.php** (outside the document root). Do not replace your database or mail configuration.

```php
'nuitee' => [
    'api_key' => 'YOUR_SANDBOX_KEY',
    'public_search' => false,
    'flights_enabled' => false,
    'hosted_booking_url' => '',
],
```

Alternatively set the server environment variable `NUITEE_API_KEY` and omit the `api_key` entry. Do not publish credentials in GitHub, browser JavaScript or screenshots.

- A sandbox key beginning with `sand_` allows administrator-only test searches. Enable `flights_enabled` after confirming flight API access in your Nuitée account.
- For production, replace the sandbox key privately, validate actual supplier responses and pricing, confirm applicable licensing and supplier terms, and only then set `public_search` to true.
- Optional: configure an HTTPS supplier-hosted booking URL supplied by Nuitée to offer a separate checkout path. Confirm that the portal supports the advertised products and payment model. This is a generic portal link, not a transfer of a specific search offer.
- Hotel search continues to use the enquiry flow. Flight direct checkout is described below and is not enabled for live payments by default.

Before deployment, run `php -l public/index.php` and `php -l public/nuitee-module.php`. Test missing credentials, sandbox administrator access, public access denial in sandbox, valid hotel and flight searches, supplier errors, price/currency and occupancy display, quote submission, and existing navigation. Deploy public files together. No new database tables are needed; the existing attempts table is used for rate limiting.


## Direct flight checkout (experimental)

Flight results now offer Book Now for signed-in users. Selecting an offer verifies current availability, total CAD price, itinerary and fare conditions. The existing quote flow remains available. Hotel checkout is not included in this update.

Production payment is **off by default**. For sandbox end-to-end tests, add the following to the existing `nuitee` config array (keep existing settings):

```php
'direct_booking_enabled' => false,
'stripe_test_publishable_key' => 'pk_test_...FROM_NUITEE_DASHBOARD...',
'stripe_live_publishable_key' => '',
```

Use a fresh sandbox API key, set flights_enabled true, keep public_search false, and sign in as administrator. Use fictional passenger data and only Stripe test cards. The module sends verified passenger/contact details to Nuitée, prebooks with usePaymentSdk=true, renders a Stripe-hosted card field, and submits the server-stored prebook and transaction IDs to the booking endpoint after authorization. The onb_flight_checkouts table is created on first prebook; your DB user needs CREATE permission. No raw payment card data is collected by the application. Booking status and references are stored; only the supplier's CONFIRMED status is presented as confirmed. A pending or ambiguous response is not a ticket.

Before any production enablement, run PHP lint for all modified PHP files and JS syntax check. Test sandbox success, decline, 3DS, price changes, duplicate submissions, lost connection, pending confirmation, account login and access restrictions. Confirm that the provider supports Stripe Card Element on these intents; if not, use its documented Payment Element integration. Verify CSP and Stripe domains on deployed site. Complete durable customer recovery, email receipts, asynchronous booking status reconciliation and cancellations/refunds, obtain applicable travel licensing and confirm merchant terms before public sales. Do not enable direct_booking_enabled until those requirements and sandbox testing are complete. A matching pk_live_ key from Nuitée is required for production. Never commit the private API key.
