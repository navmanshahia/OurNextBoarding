# Business command centre deployment

## Before deploying
- Back up the existing MySQL database and site files. Deploy the complete current repository, not selected PHP files. Keep the existing private/config.php, SMTP credentials, uploaded files and private encryption keys unchanged.
- The web document root must be the public directory. Do not expose private, vendor or the repository metadata through any domain or alias.
- Use PHP 8.1+ with PDO MySQL, valid HTTPS, configured email transport and the existing schema. The application creates additional tables with CREATE TABLE IF NOT EXISTS; the database user must have the corresponding permissions.
- Open the admin portal and confirm that the Business Command Centre loads. Review the GitHub Actions syntax-check result before deployment.

## Scheduled jobs
In cPanel > Cron Jobs, schedule the following hourly (replace paths with the real hosting paths and confirm the PHP CLI version with the host):

    15 * * * * /usr/local/bin/php /home/CPANEL_USER/public_html/ournextboarding/private/business-cron.php

The CLI script is not a public HTTP endpoint. It schedules new CRM follow-ups two days after enquiry creation, sends at most one daily internal digest, sends one explicitly requested reminder per open enquiry or approved uncompleted checkout, and sends written confirmation for newly confirmed bookings. Email submission states are recorded in the command centre. Submitted does not guarantee delivery. Failed or interrupted messages are not automatically retried to avoid duplicate sends; review manually. Existing reminders from other scheduled jobs should be reviewed for overlap. No historical bookings are automatically emailed.

## Smoke tests on a staging database
1. Admin and traveller sign-in; unauthenticated and traveller access to the admin dashboard is denied.
2. Enquire using a URL with utm_source and utm_campaign; verify attribution and consent. Run the cron against staging mail addresses; verify the scheduled CRM date and digest, then repeat to confirm deduplication.
3. Create a dated departure with published trip, valid capacity and written terms. Try standard and flexible single-traveller quotes, group and early-bird prices, optional activities and invalid discount codes. Verify totals before accepting.
4. Approve one quote, accept final terms in customer checkout, verify the unique booking reference and policy details. Record the deposit in the booking ledger and confirm; run the cron and verify confirmation email. Do not use real money.
5. Attempt a duplicate request, over-capacity reservation and checkout from another account; verify rejection. Test waitlist promotion manually when space opens.
6. Update visa checklist and status, verify the traveller's view, then submit/approve a cancellation and verify linked reservation seat release.
7. Complete a fully paid test booking, submit and moderate a review, verify the public journey displays only approved reviews from completed travellers.
8. Compare dashboard totals to database booking ledger and recorded expenses. Payments in the separate e-transfer invoice system are not automatically reconciled. Confirm email logs and campaign conversion counts.

## Limitations
- Group enquiries and prices work; customer checkout is limited to approved single-traveller reservations. Group conversion and agent/referral attribution into checkout require a separate verified workflow.
- Waitlist promotion is manual, not automatic. Payment transfer verification and refunds are manual. No automatic invoice creation or downloadable receipt PDFs are added by this release.
- Campaign reporting tracks first captured UTM tags and linked requests, not ad cost or anonymous visitor events. Historical records are unattributed.
- Licensing, customer fund handling, written terms, privacy and insurance compliance must be reviewed before enabling real payments. No live database, browser or mail test has been performed by the author.
