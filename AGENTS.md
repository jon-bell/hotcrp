# AGENTS.md

## Cursor Cloud specific instructions

HotCRP is a PHP web application for managing academic conference peer review. It requires PHP 8.x, MySQL/MariaDB, Nginx, and poppler-utils.

### Starting services

MySQL and php-fpm don't auto-start in the container (no systemd). Start them manually:

```
sudo mysqld --user=mysql --daemonize
sudo chmod 755 /var/run/mysqld
sudo php-fpm8.3 --nodaemonize &
sudo nginx
```

If nginx is already running, use `sudo nginx -s reload` instead.

### Running tests

- **PHP tests:** `sh test/check.sh --all` (runs 26k+ tests via PHP CLI; no web server needed)
- **JS lint:** `npx eslint scripts/script.js scripts/settings.js scripts/graph.js scripts/buzzer.js` (the minified `.min.js` files report many false positives; lint only source files)
- Pre-existing `no-unused-vars` ESLint errors are expected in the codebase.

### Test databases

Two MySQL databases are needed for the PHP test suite: `hotcrp_testdb` and `hotcrp_testdb_cdb`. Create them with:

```
sudo lib/createdb.sh -u root -proot -c test/options.php --batch
sudo lib/createdb.sh -u root -proot -c test/cdb-options.php --no-dbuser --batch
```

The MySQL root password is set to `root`. The `/var/run/mysqld/` directory must be world-readable (`chmod 755`) or non-root MySQL clients will fail to connect via the socket.

### Web application

The dev instance runs at `http://localhost/` via Nginx + php-fpm. The Nginx config is at `/etc/nginx/sites-available/hotcrp`. The dev database is `hotcrp_dev` with config in `conf/options.php` (this file is gitignored). Account creation via the web UI requires email delivery; in dev, create users via CLI: `php batch/saveusers.php`.

### Key directories

See `README.md` for full structure. Source code is in `src/`, libraries in `lib/`, tests in `test/`, JS in `scripts/`, CSS in `stylesheets/`.
