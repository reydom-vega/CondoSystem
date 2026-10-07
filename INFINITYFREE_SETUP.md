# InfinityFree Deployment

## 1. Create the database

In the InfinityFree control panel, create a MySQL database. Record these four values:

- MySQL hostname
- Database name
- Database username
- Database password

Import the contents of `database` in phpMyAdmin. The schema includes the `reset_token` and `reset_expires` columns required for password recovery.

## 2. Configure the application

Before uploading, edit `config.php` and replace these constants with the values shown by InfinityFree:

```php
const DB_HOST = 'sql313.infinityfree.com';
const DB_USER = 'if0_42766988';
const DB_PASS = 'sWPUeIHorqr5MU2';
const DB_NAME = 'if0_42766988_Condo_System';
```

The application also accepts these server environment variables if your hosting configuration provides them:

- `CONDO_DB_HOST`
- `CONDO_DB_USER`
- `CONDO_DB_PASS`
- `CONDO_DB_NAME`
- `CONDO_SMTP_HOST`
- `CONDO_SMTP_USER`
- `CONDO_SMTP_PASS`
- `CONDO_MAIL_FROM`

## 3. Upload the files

Upload the project contents to the domain's `htdocs` directory. Keep the `vendor` directory and `vendor/autoload.php`; Composer is not required on the server when those files are uploaded.

Do not upload local database credentials or real SMTP passwords. The `.htaccess` file disables directory listing and blocks direct access to `database` and `config.php`.

## 4. Configure email

Set `SMTP_USER`, `SMTP_PASS`, and `MAIL_FROM` in `config.php`, or use the SMTP environment variables above. Use a Gmail app password or another SMTP provider credential, never your normal mailbox password. The current code has SMTP debug output disabled for production.

## 5. Verify the site

Open the domain and test:

1. Sign up and email verification.
2. Login and logout.
3. Password reset.
4. Resident booking, maintenance, messages, and payments.
5. Admin dashboard and status updates.

If the site reports a database connection error, recheck the exact InfinityFree hostname and the database name prefix. Do not use `127.0.0.1` or `localhost` for the InfinityFree database.
