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
- Hotel and flight search offer details, quote requests and gated direct checkout, described below. Live payments are off by default.

Before deployment, run `php -l public/index.php` and `php -l public/nuitee-module.php`. Test missing credentials, sandbox administrator access, public access denial in sandbox, valid hotel and flight searches, supplier errors, price/currency and occupancy display, quote submission, and existing navigation. Deploy public files together. No new database tables are needed; the existing attempts table is used for rate limiting.


## Direct flight checkout (experimental)

Flight results now offer Book Now for signed-in users. Selecting an offer verifies current availability, total CAD price, itinerary and fare conditions. The existing quote flow remains available. Hotel checkout and confirmation emails are also described below.

Production payment is **off by default**. For sandbox end-to-end tests, add the following to the existing `nuitee` config array (keep existing settings):

```php
'direct_booking_enabled' => false,
'stripe_test_publishable_key' => 'pk_test_...FROM_NUITEE_DASHBOARD...',
'stripe_live_publishable_key' => '',
```

Use a fresh sandbox API key, set flights_enabled true, keep public_search false, and sign in as administrator. Use fictional passenger data and only Stripe test cards. The module sends verified passenger/contact details to Nuitée, prebooks with usePaymentSdk=true, renders a Stripe-hosted card field, and submits the server-stored prebook and transaction IDs to the booking endpoint after authorization. The onb_flight_checkouts table is created on first prebook; your DB user needs CREATE permission. No raw payment card data is collected by the application. Booking status and references are stored; only the supplier's CONFIRMED status is presented as confirmed. A pending or ambiguous response is not a ticket.

Before any production enablement, run PHP lint for all modified PHP files and JS syntax check. Test sandbox success, decline, 3DS, price changes, duplicate submissions, lost connection, pending confirmation, account login and access restrictions. Confirm that the provider supports Stripe Card Element on these intents; if not, use its documented Payment Element integration. Verify CSP and Stripe domains on deployed site. Complete durable customer recovery, email receipts, asynchronous booking status reconciliation and cancellations/refunds, obtain applicable travel licensing and confirm merchant terms before public sales. Do not enable direct_booking_enabled until those requirements and sandbox testing are complete. A matching pk_live_ key from Nuitée is required for production. Never commit the private API key.


## Hotel details, checkout and confirmation emails

Hotel search results now include a room-policy expansion, View hotel details (fetches /data/hotel on demand), and Book Now. Details include supplier-supplied photos, facilities, mapped room information, taxes and cancellation rules when available. Flight search results include expandable schedules, connection counts, fare conditions and baggage when provided; checkout still reverifies the fare.

Hotel checkout calls the dedicated booking host, book.liteapi.travel/v3.0, for /rates/prebook and /rates/book. Each room needs a primary guest; the initial implementation supports adult-only stays. The existing Stripe payment component is used for authorization, with final prebook amount and terms shown before payment. Payment and booking IDs remain server-side. The database tables onb_hotel_checkouts and onb_travel_confirmation_mail are created automatically as needed.

Add this setting inside the existing private nuitee configuration:
```php
'hotel_direct_booking_enabled' => false,
```
Sandbox administrator tests use the same stripe_test_publishable_key and sand_ API key described above. Keep both production direct-booking switches false until verified. Production confirmation emails use the existing onbMail SMTP integration and are attempted only once per confirmed supplier booking, not for pending bookings, sandbox tests, or mere payment authorizations. The email records success or failure; ambiguous bookings and failed mail require manual review. Hotel confirmation codes may not yet be available at initial confirmation; the email includes the supplier booking ID regardless. Flight confirmation is not represented as guaranteed ticket issuance.

Deployment: upload all changed public files together (index.php, nuitee-module.php, flight-checkout.php, flight-checkout.js, hotel-checkout.php, travel-details.php, nuitee.css). Run php -l on each PHP file and node --check on flight-checkout.js. Test both detail views, prebook pricing and fees, declined/successful/3DS payments, lost sessions, duplicate submissions, confirmed/pending bookings, actual SMTP delivery, and supplier restricted-rate pricing. Complete persistent booking recovery/reconciliation, receipts and cancellation/refund handling before production payments. Direct production payment and mail delivery have not been validated in this environment. A confirmed booking is not a guarantee of ticket issuance. Never use real payment cards in sandbox.


## Premium flight and hotel redesign

The redesigned pages are scoped to Flights, Hotels, Hotel Details and both checkout flows. No homepage, navigation, group-tour, account dashboard or referral redesign is included.

Deploy the updated public files together: index.php, nuitee-module.php, travel-premium.php, travel-premium.css, travel-premium.js, flight-checkout.php, flight-checkout.js, hotel-checkout.php, hotel-payment.js and travel-details.php. Keep private/config.php and your existing database. The search proxy creates onb_travel_lookup_limits for autocomplete quotas; the DB account must have CREATE/INSERT/UPDATE/DELETE permissions. New CSS/JS are only loaded on the travel routes.

Hotel autocomplete uses Nuitée's paid Places endpoint. Defaults are 300 remote autocomplete calls per UTC day across the site and 30 per IP per UTC hour, shared across the flight and hotel products; session caching and debounce reduce repeated calls. Optional private config keys: lookup_daily_limit and lookup_ip_hourly_limit. Review supplier pricing before changing them.

Payment: hotels now use Nuitée's hosted Payment SDK wrapper (live/sandbox chosen from the private API key); no manual Stripe publishable key is required for hotels. Flight prebooking prefers the publishableKey returned by Nuitée, falling back to the matching Nuitée dashboard publishable key if needed. The flight UI uses Stripe Payment Element with Nuitée's payment intent. No independent Stripe account is required. Return URLs are HTTPS on ournextboarding.com and booking finalization uses server-stored references.

Keep public production payment disabled until the release checklist is satisfied. A new independent guard is required in the existing private nuitee array:
```php
'checkout_verified' => false,
'direct_booking_enabled' => false,
'hotel_direct_booking_enabled' => false,
```
Do not set checkout_verified true until real end-to-end sandbox verification, payment recovery, supplier pricing rules, confirmations and applicable travel licensing are completed. Admin sandbox checkout still works with a fresh sandbox key. No private keys were committed. Do not use real personal documents or cards during tests.

Known limitations: adult-only checkout and search; one-way and round-trip flights; even room distribution; autocomplete and documented provider payloads are not live-tested; hotel rate eligibility is conservative and may exclude restricted offers; local results are limited to 100 eligible flight fares and 30 hotels with up to 10 room offers each. Advanced filters, family/multi-city search, persistent lost-session recovery/reconciliation, downloadable receipts/vouchers and branded HTML/lifecycle emails remain incomplete. Existing confirmation email sender is retained and sends only after confirmed supplier status. No live transaction or email test was run. Review external hero image licensing/availability before publication.

Validation workflow: Travel booking syntax checks (PHP lint and node --check). Syntax checks do not validate actual supplier responses, payment SDK integration, UI rendering or booking delivery. Test in staging on mobile and desktop, including autocomplete, no results, search filters, expired offers, changed rates, Stripe 3DS, declines, interrupted payments, duplicate booking, status recovery, confirmation email and unaffected site navigation. Do not deploy an untested payment flow to public customers.


## Checkout layout refinement

Checkout pages now render the selected itinerary/property and expandable fare or room terms in the main column, with a compact price summary and three-step progress indicator. When payment is not enabled, a complete review card and assistance options remain visible, rather than an empty traveller column. Logged-in administrators can expand a configuration status panel; it displays boolean flags only, never API keys or payment secrets.

To test direct payments, use the sandbox API key, sign in as administrator, search a fresh offer, and use fictional traveller data and test cards. The production switches remain in private/config.php outside the public document root. After successful sandbox tests and production readiness review, the existing nuitee array must contain direct_booking_enabled=true (flights), hotel_direct_booking_enabled=true (hotels), and checkout_verified=true (explicit release gate). The flags must be PHP booleans, not strings. Do not claim verification or enable public real-money checkout until the integration and recovery flows are tested. No change to supplier transaction handling was made in this refinement. No credentials or private hosting configuration are included.

Upload the changed public/index.php, public/travel-premium.php, public/travel-premium.css, public/flight-checkout.php, and public/hotel-checkout.php together. Search pages retain the same appearance.
