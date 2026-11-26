 # Automated installation

1. In cPanel Database Wizard create an empty MySQL database and user, grant all privileges on that database.
2. Copy public/ contents into public_html. Copy private/ and schema.sql into the parent of public_html (normally your home directory). Never put private/ or schema.sql in public_html.
3. Create private/install-key.txt containing a random secret of at least 32 characters using File Manager. Ensure private/ is writable by your PHP account and inaccessible via HTTP.
4. Enable HTTPS and visit https://YOUR-DOMAIN/install.php. Enter the key, database credentials and your chosen administrator email/password.
5. Sign in and visit index.php?page=admin. Review and publish the proposed trips.
6. Remove install.php after success. Back up the database and private/config.php securely.

No SQL import or CLI admin creation required. Database creation itself requires cPanel privileges and is not performed by this installer. If setup fails after creating some tables, use a fresh empty database. Test on staging before production. Email verification and recovery are not yet implemented.
