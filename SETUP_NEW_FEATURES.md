# Setup Guide — New Features

This covers everything added: email notifications, SMS (Twilio/Semaphore) +
phone verification, persistent login, PayMongo payments + webhook, parking
management, and the audit log.

Every new integration follows the same pattern already used for email in
`config.php`: a constant with a placeholder default, readable via
`appSetting('ENV_NAME', 'local default')`. If your host lets you set real
environment variables, set them there. If (like most InfinityFree free
plans) it doesn't give you that option, just edit the constant directly in
the file mentioned below — that's exactly how `DB_HOST` / `DB_USER` /
`SMTP_PASSWORD` already work in `config.php`.

## 1. Database

New tables (`remember_tokens`, `audit_logs`, `parking_slots`,
`parking_requests`) and new columns on `users` / `payments` are created
automatically the first time each feature is used (same `ensureXTable()`
pattern already used for `payments`, `bookings`, etc.). You don't have to
run anything manually.

If you'd rather set everything up up front (e.g. a fresh database via
phpMyAdmin), the `database` file has been updated with all of it — import
it as before.

## 2. Email for announcements & due dates

No setup needed — this reuses the SMTP settings already in `config.php`.
- Posting an announcement now has an **"Email this to all residents"**
  checkbox (checked by default).
- Due-date reminders go out by email automatically (see section 5).

## 3. SMS (Twilio or Semaphore) + phone verification

Edit `includes/sms.php`:

```php
const SMS_PROVIDER_DEFAULT = 'semaphore'; // or 'twilio'
```

**Semaphore** (local PH, simplest to start with):
1. Sign up at semaphore.co, get your API key and an approved sender name.
2. Set `SEMAPHORE_API_KEY` and `SEMAPHORE_SENDER_NAME` in `includes/sms.php`
   (or the env vars `CONDO_SEMAPHORE_API_KEY` / `CONDO_SEMAPHORE_SENDER_NAME`).

**Twilio** (international + local):
1. Get your Account SID, Auth Token, and a From number from console.twilio.com.
2. Set `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER` in
   `includes/sms.php` (or `CONDO_TWILIO_SID` / `CONDO_TWILIO_AUTH_TOKEN` /
   `CONDO_TWILIO_FROM`). Twilio trial accounts can only text numbers you've
   verified in the console — fine for testing, not for residents at large.

**Phone verification**: residents verify their number from **Edit
Profile → Phone Verification** (send code → enter code). Changing the
contact number resets verification, so a resident can't inherit someone
else's verified status.

## 4. Persistent login ("stay signed in")

No setup needed. When a resident or admin checks "Keep me signed in" at
login, their session survives closing the browser (30 days, sliding).

Note on what changed: the previous version stored the account's password
in a cookie, base64-encoded (which is not encryption). It's been replaced
with a random token, only a hash of which is stored in the database, and
which rotates on every use — a copied cookie stops working once it's
been used or the account logs out.

## 5. PayMongo (sandbox) + webhook

1. Get your own API keys from the PayMongo Dashboard → Developers → API keys.
   Use the `sk_test_...` key for sandbox testing or `sk_live_...` only when
   ready to accept real payments. Test payments appear under the account's
   Test Mode data and never appear as live transactions.
2. Configure the secret key in the environment available to PHP/Apache as
   `CONDO_PAYMONGO_SECRET_KEY`. Do not put a real secret key in source control.
   The app intentionally has no built-in account key; checkout is disabled
   until this setting is configured.
3. In the PayMongo Dashboard → Developers → Webhooks, add an endpoint:
   ```
   https://bankable-tripod-populace.ngrok-free.dev/CondoSystem3/webhooks/paymongo_webhook.php
   ```
   Subscribe it to at least `checkout_session.payment.paid`. PayMongo will
   show you a webhook **signing secret** — set that as
   `CONDO_PAYMONGO_WEBHOOK_SECRET` in the PHP/Apache environment.
4. Test it: pay a due from the resident Pay Dues page using PayMongo's
   [test card/GCash numbers](https://developers.paymongo.com/docs/testing).
   You should land back on a "Confirming your payment…" page that flips to
   "Payment Confirmed" within a few seconds once the webhook lands. You can
   also send a test event straight from the PayMongo Dashboard's webhook
   page to confirm signature verification is passing, and check
   `superadmin/auditlog.php` for a `webhook_confirm` entry either way.
5. If a payment ever gets stuck (e.g. webhook didn't fire), an admin can
   always mark it paid manually from **Payments** — that's a normal, audited
   action, not a workaround.

The environment key determines which PayMongo account and mode receives the
checkout. Keep the webhook configured in the same mode/account as that key.

## 6. Parking

Nothing to configure — go to **Parking** (admin) and add your building's
actual slots (code, level, resident or visitor). Residents can then
request a standing slot or short-term visitor parking from their own
**Parking** page; approving a request assigns one of your defined slots.

## 7. Audit log

Nothing to configure. **Audit Log** (admin) records every approve/reject/
create/delete/mark-paid action, who did it, and when.

## 8. Due-date reminders

Emails + texts residents whose dues are due within 3 days or overdue,
skipping anyone already reminded in the last 24 hours.

- **On demand**: the "📧 Send Due-Date Reminders Now" button on the
  Payments page.
- **Automatically**: InfinityFree's free tier doesn't offer reliable cron,
  so use a free external scheduler like cron-job.org, pointed once a day at:
  ```
  https://yourdomain.com/cron/send_due_reminders.php?secret=YOUR_SECRET
  ```
  Set `CONDO_CRON_SECRET` (env var) or edit the fallback in
  `cron/send_due_reminders.php` first — anyone with that secret can trigger
  it, so treat it like a password.

## 9. Billing & Payments (itemized statements) + Violations

The old single flat "due" is gone. A bill (a `payments` row) can now carry
multiple line items — Condo Dues, Water, Electricity, Parking, Rent/Lease,
a violation fine, anything — and the resident sees them all as one
Statement of Account with one total and one PayMongo checkout.

- **Admin → Generate Bills**: bulk-generate the standard monthly charges
  for every resident at once (skips anyone who already has an open bill,
  so it's safe to re-run), or create a one-off custom bill for a single
  unit with whatever line items you type in.
- **Admin → Violations**: issue a warning or a fine. A fine is
  automatically added as a line item to the resident's current open bill
  (or opens a new one if they don't have one) — no separate fines payment
  flow to manage. Residents can dispute an unpaid fine from their Billing
  & Payments page; you resolve it there by waiving it or upholding it.
- Paying a bill (online or marked paid manually) also settles any
  violation fines that were part of it — status flips to Paid
  automatically, no manual sync needed.
- **Resident → Billing & Payments** (renamed from "Pay Dues"): shows the
  current Statement of Account with the full breakdown, any violations
  and their dispute option, and a payment history with each past bill's
  itemization available via a "show details" toggle.

Nothing to configure here — it all runs on the same PayMongo/email/SMS
setup from the sections above. One thing worth knowing: a resident with
zero bills ever generated has nothing to pay or be reminded about, so the
first time you use this, run Generate Bills at least once.
