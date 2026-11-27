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
- The current integration searches supplier inventory and passes selected itinerary details into the existing enquiry flow. It does not create reservations, issue tickets, collect payments, or implement the direct Payment SDK checkout. Direct checkout requires verified offer/prebook, payment confirmation, booking confirmation, cancellation handling and transaction reconciliation.

Before deployment, run `php -l public/index.php` and `php -l public/nuitee-module.php`. Test missing credentials, sandbox administrator access, public access denial in sandbox, valid hotel and flight searches, supplier errors, price/currency and occupancy display, quote submission, and existing navigation. Deploy public files together. No new database tables are needed; the existing attempts table is used for rate limiting.
