# Tenant configuration and unit ownership

This release distinguishes a **unit owner** from a **tenant or authorized occupant** while retaining `role=resident` for all resident accounts. The signup selection is stored as `account_type`; selecting **Tenant** does not grant ownership or access before management approval. Daily services belong to the individual resident. Unit charges belong to the unit owner; parking requested by an approved tenant is billed to and paid only by that tenant.

## Recommended policy

| Service | Approved unit owner | Approved linked tenant or occupant |
| --- | --- | --- |
| Dashboard, announcements and own profile | Available | Available |
| Bills | View and pay own bills and eligible linked occupants' existing bills | Read-only unit bills; request and pay own parking bills |
| Checkout, payment returns, receipts and gateway details | Own unit bills; tenant personal parking bills excluded | Own personal parking bills only |
| Amenities, maintenance, messages and visitors | Available | Available by default; own requests and messages only |
| Temporary visitor parking | Available | Available by default; own visitors and requests only |
| Vehicle registration | Register own vehicle | Register own vehicle by default |
| Paid parking stickers | Order for approved own or linked occupants' vehicles; owner receives the bill | Tenant orders for own approved vehicles and pays own parking bill |
| Permit requests | Available | Enabled in the recommended configuration; management review required |
| Violations | Own records and disputes | Own records and disputes |

**Family/Relative of the Owner** and **Friend of Owner** use the same restricted policy as tenants. They are displayed as authorized occupants. Staff roles retain their separate capability rules.

Tenant billing exposes unit bill identifiers, amounts, status, due/billing dates and line items. Payment controls, checkout links, provider identifiers and receipts are available only for the tenant's own personal parking bills. The owner may view the statement but cannot pay or download receipts for tenant-requested parking. A tenant does not gain access to another occupant's bills, requests, messages or private documents. An owner sponsoring a tenant vehicle can select its plate, model and registration status without gaining access to the tenant's OR/CR or vehicle photo.

This is an account-and-unit ownership policy. It does not calculate contractual rent, transfer financial liability or filter bills by a lease start/end date. Review historical invoices with management before changing occupancy.

## Configuration

The recommended defaults in `.env.example` are:

```text
CONDO_TENANT_AMENITIES=1
CONDO_TENANT_VISITORS=1
CONDO_TENANT_PARKING=1
CONDO_TENANT_VEHICLES=1
CONDO_TENANT_MAINTENANCE=1
CONDO_TENANT_MESSAGES=1
CONDO_TENANT_PERMITS=1
```

Use `1` to enable a listed tenant service and `0` to disable it. These settings apply to tenants and authorized occupants, and do not remove owner permissions. Change the deployment's private environment configuration; process environment values take precedence over `.env`. Restart persistent PHP workers when needed for environment changes to take effect.

The recommended configuration enables tenant permit requests. Set CONDO_TENANT_PERMITS=0 to disable them. Permits require management review. Shared unit payment authority remains owner-only. CONDO_TENANT_PARKING also controls whether an approved tenant can place personal sticker orders; tenants can still settle their existing personal parking bills if new parking requests are disabled. Authorized occupants retain the owner-managed sticker policy. Disabling a daily service removes its navigation/actions and denies direct page/API/workflow access; hiding buttons alone is not the permission boundary.

## Signup and approval

1. The applicant selects **Tenant**, a real unit and their personal account details during signup. The account starts pending. Profile edits and forged signup fields cannot change an account into an approved owner or staff account.
2. A superadmin reviews the account type and verifies ownership or authorized tenancy. Approve the unit owner before approving its tenants or other occupants.
3. Approval links the tenant using `users.unit_owner_id` to the one active, verified, approved owner of the selected unit. Both unit numbers must match after normalization. A unit can have one owner and multiple approved occupants.
4. Resident permissions read the current stored relationship. A tenant loses service and billing access when the account, owner, link or unit is no longer valid. An account cannot obtain access merely by copying another resident's unit number.

Tenants use the resident dashboard, which displays **Tenant**, **Unit Bills** and the read-only billing explanation. With the recommended configuration, their menu includes permits and allows personal parking sticker orders when parking is enabled. Their permits, visitors, vehicle documents, maintenance requests and conversations remain scoped to their own account.

## Vehicle and sticker flow

The tenant registers their vehicle and supplies the OR/CR. Management reviews it in **Registered Vehicles**. The tenant selects their own approved vehicle in **Parking & Stickers** and requests a sticker. The bill is recorded on the tenant account with `billing_scope=personal_parking`; only that tenant can pay it. Management verifies payment and issues the sticker with an automatic slot assignment from inventory. Owners can continue to sponsor vehicles by placing an order through their own account; those owner-requested orders remain owner bills.

Selection and issuance recheck the current approved owner/occupant relationship. An unrelated resident, an unlinked occupant or a resident in another unit cannot be sponsored. Management may link existing paid tenant sticker orders to that tenant's approved vehicles; their original bill owner and amounts remain unchanged.

## Ending or changing occupancy

Admin/superadmin staff use **Residents → End occupancy** to remove a selected tenant or authorized occupant's access. This resets their unit/link and approval, invalidates sessions and remembered/recovery tokens, and cancels unopened requests and future active bookings covered by the occupancy workflow. Other occupants retain their own access.

Use the **Units** vacancy/removal workflow for the owner. It also revokes linked occupants, so tenants cannot retain access through an owner who has left the unit. Ending occupancy is an access operation; historical financial records remain unchanged.

Before ending occupancy or reassigning a unit, reconcile outstanding legacy bills with management. The current approved owner may pay eligible bills of currently linked occupants. The system does not automatically move an old invoice to a new owner, erase debt or settle liability between owner and tenant.

## Existing installations and verification

Release `2026.10.08.2` adds the indexed owner link through `php scripts/migrate.php --apply`. Explicit CLI migration backfills existing approved tenants/occupants only where their normalized unit has exactly one active, verified, approved owner. Production requests do not guess or create these links.

Review every existing account type and unit assignment after migration. Blank historical account types retain owner compatibility; verify that those accounts represent real owners before approving occupants. Ambiguous or unlinked tenants remain denied. For an existing approved tenant needing a corrected link, end occupancy, review the unit/account type, then reapprove against its confirmed owner. Do not fix access by changing the tenant into an owner or manually marking the schema version.

Run the provider-free suites against a test database server:

```text
php scripts/test_tenant_policy.php
php scripts/test_tenant_billing.php
php scripts/test_resident_workflows.php
```

The tenant suites cover approval/link isolation, configured service denial, rendered controls, owner-only unit payment authority, tenant-only personal parking payments and vehicle sponsorship. They use disposable fixture databases and do not verify live payments, delivery providers, camera hardware or browser appearance. Follow [DEPLOYMENT.md](DEPLOYMENT.md) for backups, migration and operational smoke checks; [VERIFICATION.md](VERIFICATION.md) records the completed local checks.

Personal parking billing requires schema version `2026.10.09.1`. Apply `php scripts/migrate.php --apply` before deploying with automatic migrations disabled. Existing financial rows retain `billing_scope=unit` and their original amounts; this change does not transfer historical liabilities.
