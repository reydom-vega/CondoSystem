# InfinityFree hosting compatibility

The full system needs inbound PayMongo webhooks, outbound provider access, private file protection, reliable reminders and a controlled database upgrade. **Do not describe an InfinityFree free-tier upload as a production-ready payments deployment.**

InfinityFree's official documentation says its free-hosting browser security requires JavaScript and cookies and blocks programmatic inbound access, including webhooks and command-line HTTP tools. This affects `webhooks/paymongo_webhook.php`, external reminder schedulers and remote readiness checks. Outbound API requests can work, but they do not prove that payment callbacks will arrive. See [InfinityFree's API-access documentation](https://forum.infinityfree.com/t/why-isnt-api-access-working-on-my-website/115198/1).

For operational use, select a PHP/MySQL host that permits payment-provider callbacks and offers a way to run the explicit migration CLI. Confirm SMTP delivery, task scheduling, upload protection and HTTPS on that host. Follow [DEPLOYMENT.md](DEPLOYMENT.md) for the deployment procedure.

For a browser-only demonstration on InfinityFree:

1. Create the host database and record credentials in private server configuration. Do not put credentials into `config.php` or this guide. The application reads `CONDO_*` environment variables or a protected `.env` file; `.env.example` lists available settings.
2. Prepare a clean demonstration database locally: import `database`, run `php scripts/migrate.php --apply`, and export the complete resulting schema. On a host without CLI, import that prepared schema with phpMyAdmin. Do not manually add the schema-version marker to an incomplete installation.
3. Upload application code and Composer dependencies, including all `.htaccess` files. Exclude repository metadata, real resident documents, debug tools, setup archives, test scripts and database exports from the public upload.
4. Use the exact hosting database hostname and prefixed account/database names. Set the canonical HTTPS `CONDO_APP_URL` to the hosted address. Use separate sandbox provider credentials and a new pass-signing key.
5. Verify forbidden files in a real browser and inspect the actual responses; the host's security page can hide failed route access from automated HTTP clients. Check resident and staff journeys, login, document ownership and role denial.
6. Keep live online payments disabled until the callback and provider-reconciliation path has been exercised successfully on a compatible host. A browser success redirect is not payment proof.

The prior version of this guide contained historical database credentials. Those credentials and any other secrets previously stored in source or archives must be revoked and replaced before deployment; deleting text does not remove repository history or copies.
