# Treasurer dashboard

The Treasurer dashboard is a read-only financial overview using the existing payment records and billing workflows. It keeps the original navy/orange palette, shared sidebar, notifications, profile, theme switch and Digital ID controls.

## Contents and definitions

- **Monthly collections:** completed payments with a recorded `paid_at` in the selected calendar month. The GET month selector changes this total and the six-month collection trend.
- **Outstanding balance:** current invoices with status `pending` or `overdue`, across all billing periods. Rejected and rolled-forward invoices are excluded to avoid counting superseded statements.
- **Past due:** open invoices with a due date before today, including invoices whose stored status is still pending. This calculation does not change invoice statuses.
- **Due within 7 days:** open invoices due today through the following six days. A bill due exactly seven days after today is outside this window.
- **Aging:** not yet past due, 1–30 days, 31–60 days and 61+ days. The buckets reconcile to the current outstanding balance.
- **Recent payments:** the latest six completed records, ordered by payment date, showing the actual payer, unit, channel and existing DUES reference. Undated legacy payments are not assigned an invented payment date.
- **Payment follow-ups:** up to six positive open invoices, oldest due date first, drawn from past-due and upcoming bills. Each opens the existing billing reference search. No automatic reminder or payment confirmation is performed.
- Quick links lead to existing Billing & Payments, Generate Bills and Payment History pages.

Dates use Asia/Manila. Monthly collections exclude undated paid records, with a visible note when any exist; overall completed collections include those records. Monetary totals are aggregated as integer cents from database DECIMAL amounts. Current balances remain current when viewing a historical collection month.

## Implementation

- `treasurer/treasurer_dashboard.php`: existing authenticated shell with the new overview and month/refresh controls.
- `includes/treasurer_dashboard.php`: role/account/session checks and a consistent read-only transaction for the overview. Active, verified Treasurer accounts may retain the default resident-approval status.
- `assets/css/treasurer-dashboard.css`: scoped responsive styles, accessible trend bars and a keyboard-scrollable recent-payments table. No new JavaScript or chart dependency.
- `scripts/test_treasurer_dashboard.php`: disposable financial/date/permission fixture.
- `scripts/test_treasurer_dashboard_browser.cjs`: responsive and navigation checks.
- `scripts/test_ui_redesign.php --treasurer-only`: isolated UI fixture with completed, past-due and upcoming payment examples, removed during cleanup.

The dashboard refreshes through its GET Refresh control and displays an update timestamp. The old generic `live-updates.js` include was removed from this page because it polled the management dashboard endpoint rather than Treasurer financial data. Existing shared notifications continue to function. No billing calculation, payment confirmation, rate, schema or permission map was changed.

## Verification

```powershell
C:/xampp/php/php.exe scripts/test_treasurer_dashboard.php
C:/xampp/php/php.exe scripts/test_ui_redesign.php --preview --treasurer-only
# In another terminal, use the fixture URL printed by the preceding command:
node scripts/test_treasurer_dashboard_browser.cjs http://127.0.0.1:PORT/CondoSystem3/
```

Verified: 38 backend checks for totals, date boundaries, exact cents, aging, missing dates, invalid months, empty records, permissions/session revocation and unchanged billing records; 94 browser checks across 1366/1024/768/390/320px in both themes; 82 route/asset/CSRF checks for the four Treasurer navigation entries. PHP and JavaScript syntax checks passed. Screenshots were reviewed on desktop and mobile. All automated financial fixtures were disposable; no payment or messaging provider was contacted.

Live gateway settlement and real account sign-in were not exercised. The dashboard performs no gateway calls or financial writes. Its sidebar and role restrictions remain the existing application's controls.
