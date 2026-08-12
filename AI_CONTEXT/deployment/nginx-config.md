# Nginx Configuration

> **Module:** Deployment (Nginx reverse proxy + static asset server)
> **Audience:** DevOps engineers, system administrators, AI assistants
> **Status:** Canonical
> **Last reviewed:** Phase 19 (initial)
> **Source of truth:** this file, grounded in `docker/nginx/default.conf` (the Docker dev
> config), `docs/migration/nginx.conf.example` (the VPS production reference), and
> `docker-setup.md` §7 (container topology).

---

## 1. What is it?

Nginx is the **only public-facing HTTP server** in the RC_ERP_v2 stack. It accepts browser
requests on port 80 (HTTP) and 443 (HTTPS), serves static files (CSS, JS, images, fonts)
directly from disk, and proxies all dynamic PHP requests to PHP-FPM over the FastCGI
protocol. It is the single point of TLS termination, the single point of access control
(blocking `.env`, `.git`, `vendor/`), and the single point of SSE-specific tuning (disabling
proxy buffering for `/sse/*`).

There are **two distinct Nginx configurations** in the repo, used in two different
deployment contexts:

| Config file | Deployment context | Root | PHP-FPM upstream |
|---|---|---|---|
| `docker/nginx/default.conf` | Docker dev stack (`docker compose up`) | `/var/www/laravel/public` (bind mount) | `rcerp_app:9000` (container DNS name) |
| `docs/migration/nginx.conf.example` | VPS bare-metal production | `/var/www/rcerp_v2/{laravel,legacy}/public` (dual root) | `unix:/run/php/php8.4-fpm.sock` (Unix socket) |

This file documents **both** configs side-by-side: their shared design (FastCGI proxying,
security blocks, gzip, error pages) and their divergences (single-root vs dual-root,
container DNS vs Unix socket, SSE handling, TLS).

### 1.1 Why two configs?

- The **Docker config** is for local development. Single-app (Laravel only), no TLS,
  single root, container DNS for the PHP-FPM upstream. Optimized for `docker compose up`
  in <30 seconds.
- The **VPS config** is for production. Dual-app (Laravel + legacy PHP, sharing the same
  server block during the transition window), TLS via Let's Encrypt, Unix socket for
  PHP-FPM (faster than TCP), and a more conservative security posture.

The two configs share ~70% of their directives. This file documents the shared design
once (§6 business rules, §7 directive catalogue) and the per-config specifics separately
(§8 Docker, §9 VPS).

---

## 2. Why does it exist?

- **TLS termination.** PHP-FPM cannot serve HTTPS. Nginx holds the TLS certificates,
  decrypts incoming HTTPS, and proxies plain HTTP to PHP-FPM. This is the standard
  Laravel deployment pattern.
- **Static file serving.** Nginx is dramatically faster than PHP-FPM at serving static
  files (CSS, JS, images). Nginx serves them directly from disk; PHP-FPM is only invoked
  for `.php` files. Without Nginx, every CSS request would boot the Laravel framework.
- **Security boundary.** Nginx blocks access to `.env`, `.git/`, `vendor/`, `node_modules/`,
  `database/`, `storage/` — preventing source code + secret leakage. PHP-FPM alone has no
  such mechanism.
- **SSE (Server-Sent Events) tuning.** The realtime notification stream (`/sse/events`)
  requires disabling proxy buffering, disabling gzip, and extending the read timeout to 5
  minutes. Nginx is the only place these can be configured.
- **Request buffering + size limits.** `client_max_body_size 50M` allows large file
  uploads (product images, CSV imports). `fastcgi_buffers 16 16k` allows large responses
  (reports, dashboards) without disk spillover.
- **Gzip compression.** Nginx compresses text responses (HTML, CSS, JS, JSON) before
  sending to the browser, reducing bandwidth ~70%.
- **Dual-root routing (VPS only).** During the transition window, the legacy PHP app runs
  alongside Laravel. Nginx routes `/admin/*` and `/api/*` to Laravel, everything else to
  legacy PHP. This is only possible at the Nginx layer.
- **Container DNS resolution (Docker only).** Nginx resolves the `rcerp_app` container
  name per-request via Docker's embedded DNS (`127.0.0.11`), so it doesn't crash at
  startup if the app container isn't ready yet.

---

## 3. When is it used?

- **Docker dev stack** — `docker/nginx/default.conf` is bind-mounted into the
  `rcerp_nginx` container at `/etc/nginx/conf.d/default.conf:ro`. Edit the file + `docker
  compose restart rcerp_nginx` to apply changes.
- **VPS production** — `docs/migration/nginx.conf.example` is the reference. Copy it to
  `/etc/nginx/sites-available/rcerp`, edit the `server_name` + paths, symlink to
  `sites-enabled/`, and `sudo nginx -t && sudo systemctl reload nginx`.
- **TLS certificate provisioning** — `certbot --nginx -d erp.example.com` modifies the
  Nginx config in-place to add the `ssl_certificate` directives + the HTTP→HTTPS redirect.
- **Debugging 502s / 504s** — PHP-FPM connection issues manifest at the Nginx layer. The
  `fastcgi_connect_timeout` + `fastcgi_read_timeout` directives control how long Nginx
  waits before returning these errors.
- **Adding a new route prefix** — e.g. when the AI Sidecar (Phase 13 future) is added,
  a new `location /ai-sidecar/ { proxy_pass http://127.0.0.1:8001; }` block will be added
  here.

---

## 4. Who uses it?

- **DevOps engineer** — primary audience. Edits + reloads the config.
- **System administrator** — manages TLS certificates, monitors Nginx logs.
- **Backend engineers** — consult when adding SSE endpoints, large-file uploads, or new
  route prefixes.
- **AI assistants** — MUST consult this file before suggesting any Nginx directive. Never
  suggest Apache configs or `.htaccess` files (the ERP uses Nginx exclusively).

---

## 5. Related modules

- `docker-setup.md` §7 — the `rcerp_nginx` container in the Docker topology.
- `vps-bdix-deployment.md` §8.5 — the VPS Nginx installation + certbot sequence.
- `environment.md` §7.1 — `APP_URL` must match the Nginx `server_name`.
- `../architecture/realtime-events.md` §5 — the SSE endpoint architecture that drives the
  `/sse/` location block.
- `../api/api-overview.md` §7 — the `/api/*` route prefix.
- `../security/api-security.md` — API endpoints are public (no Nginx-level auth); auth is
  handled by the `ApiAuth` middleware.

---

## 6. Business rules

- **R-1 — Nginx is the ONLY public-facing HTTP server.** No Apache, no Caddy, no
  direct-PHP-FPM exposure. PHP-FPM listens on `127.0.0.1:9000` (Docker) or a Unix socket
  (VPS) — never on a public interface.
- **R-2 — TLS is mandatory in production.** Port 80 (HTTP) redirects to port 443 (HTTPS)
  via `return 301 https://$host$request_uri;`. The Let's Encrypt certificates live at
  `/etc/letsencrypt/live/erp.example.com/`.
- **R-3 — Static files are served by Nginx, NOT PHP-FPM.** The `location ~*
  \.(css|js|png|jpg|...)$` block intercepts these requests and serves them directly. PHP-FPM
  is only invoked for `.php` files.
- **R-4 — Sensitive files are blocked at the Nginx layer.** `.env`, `.git/`, `vendor/`,
  `node_modules/`, `database/`, `storage/`, `*.md`, `*.lock`, `*.sql` are all denied.
  This is defense-in-depth — Laravel also blocks these, but Nginx is the first line.
- **R-5 — SSE requires special Nginx config.** The `/sse/` location disables
  `fastcgi_buffering`, disables `gzip`, sets `fastcgi_read_timeout 300s`, and adds the
  `X-Accel-Buffering: no` header. Without these, SSE events buffer in Nginx and never
  reach the browser.
- **R-6 — `client_max_body_size 50M` matches PHP's `upload_max_filesize`.** If you raise
  one, raise the other. A mismatch causes confusing "file too large" errors that appear
  to come from PHP but actually originate at Nginx.
- **R-7 — The Docker config uses a variable for the PHP-FPM upstream.** `set $app_upstream
  rcerp_app;` + `fastcgi_pass $app_upstream:9000;` defers DNS resolution to per-request
  time, preventing Nginx from crashing at startup if the `rcerp_app` container isn't
  ready yet.
- **R-8 — The VPS config uses a Unix socket for PHP-FPM.** `fastcgi_pass
  unix:/run/php/php8.4-fpm.sock;` is faster than TCP (no network stack overhead) and
  simpler to secure (file permissions, not firewall).
- **R-9 — The VPS config has a dual root (Laravel + legacy PHP).** During the transition
  window, `location /admin { alias /var/www/rcerp_v2/laravel/public; }` and `location /
  { alias /var/www/rcerp_v2/legacy/public/; }` route to different apps. See §9 for the
  full dual-root config.
- **R-10 — Gzip is enabled for text responses only.** `gzip_types` lists
  `text/plain text/css application/json application/javascript text/xml ...`. Binary
  files (images, fonts) are not gzipped (already compressed).
- **R-11 — Error pages are handled by Nginx, not Laravel.** `error_page 404 /index.php;`
  routes 404s to Laravel's exception handler. `error_page 500 502 503 504 /50x.html;`
  shows a static error page (Laravel is broken, so it can't render the error).
- **R-12 — The Docker config listens on port 80 (mapped to 8080 on the host).** The VPS
  config listens on 80 (HTTP, redirects to 443) + 443 (HTTPS). The Docker dev stack has
  no TLS.

---

## 7. Directive catalogue (shared design)

### 7.1 The `server` block

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name erp.example.com www.erp.example.com;

    root /var/www/laravel/public;       # Docker: /var/www/laravel/public
                                        # VPS:    /var/www/rcerp_v2/laravel/public
    index index.php index.html;

    client_max_body_size 50M;
}
```

| Directive | Purpose |
|---|---|
| `listen 80` | Listen on IPv4 port 80 |
| `listen [::]:80` | Listen on IPv6 port 80 |
| `server_name` | The domain(s) Nginx matches against the `Host` header |
| `root` | The filesystem root for static files + `try_files` fallback |
| `index` | The default file served when the URL ends in `/` |
| `client_max_body_size` | Max request body size (file uploads) |

### 7.2 The Docker DNS resolver (Docker-only)

```nginx
resolver 127.0.0.11 valid=10s ipv6=off;
set $app_upstream rcerp_app;
```

`127.0.0.11` is Docker's embedded DNS server. `valid=10s` caches DNS entries for 10
seconds, so Nginx picks up container restarts quickly. `ipv6=off` avoids IPv6 lookup
delays on Docker networks that don't support IPv6. The `set $app_upstream` variable
defers resolution — see R-7.

### 7.3 The main `location /` block

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

This is the Laravel routing pattern: try the requested file, then the requested directory,
then fall back to `index.php` (which bootstraps Laravel and routes the request). The
`?$query_string` preserves query parameters.

### 7.4 The SSE `location /sse/` block

```nginx
location /sse/ {
    try_files $uri /index.php?$query_string;

    fastcgi_pass $app_upstream:9000;        # or unix:/run/php/php8.4-fpm.sock
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_param QUERY_STRING $query_string;
    # ... (other fastcgi_params)
    include fastcgi_params;

    # SSE-specific: disable all buffering
    fastcgi_buffering off;
    fastcgi_cache off;

    # Long timeout for SSE connections (5 minutes)
    fastcgi_read_timeout 300s;
    fastcgi_connect_timeout 10s;
    fastcgi_send_timeout 300s;

    # SSE headers
    add_header Content-Type text/event-stream always;
    add_header Cache-Control no-cache always;
    add_header Connection keep-alive always;
    add_header X-Accel-Buffering no always;

    # Disable gzip for SSE
    gzip off;
}
```

> See `../architecture/realtime-events.md` §5 for the SSE architecture. Short version:
> PHP-FPM holds the connection open, sending `data: ...` events as PostgreSQL LISTEN
> notifications arrive (relayed via Redis pub/sub). Nginx MUST NOT buffer these — if it
> does, events queue in Nginx's buffer and only reach the browser when the buffer fills
> or the connection closes.

### 7.5 The static assets `location` blocks

```nginx
# Vite build assets
location /build/ {
    try_files $uri =404;
    expires 1y;
    add_header Cache-Control "public, immutable";
    access_log off;
}

# Static file extensions
location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|map)$ {
    try_files $uri =404;
    expires 1y;
    add_header Cache-Control "public, immutable";
    access_log off;
}
```

`expires 1y` + `Cache-Control "public, immutable"` tells the browser to cache these for a
year. Vite uses content-hashed filenames (`/build/assets/app-abc123.js`), so changing the
file changes the URL, busting the cache. `access_log off` reduces log noise.

### 7.6 The PHP `location ~ \.php$` block

```nginx
location ~ \.php$ {
    fastcgi_pass $app_upstream:9000;        # or unix:/run/php/php8.4-fpm.sock
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_param QUERY_STRING $query_string;
    fastcgi_param REQUEST_METHOD $request_method;
    fastcgi_param CONTENT_TYPE $content_type;
    fastcgi_param CONTENT_LENGTH $content_length;
    fastcgi_param SERVER_NAME $server_name;
    fastcgi_param SERVER_PORT $server_port;
    fastcgi_param SERVER_PROTOCOL $server_protocol;
    fastcgi_param HTTPS $https if_not_empty;
    include fastcgi_params;

    # Timeouts
    fastcgi_read_timeout 120;
    fastcgi_connect_timeout 10;
    fastcgi_send_timeout 120;

    # Buffer settings
    fastcgi_buffering on;
    fastcgi_buffers 16 16k;
    fastcgi_buffer_size 32k;
}
```

| Directive | Purpose |
|---|---|
| `fastcgi_pass` | Where PHP-FPM is listening (container:port or Unix socket) |
| `fastcgi_param SCRIPT_FILENAME` | The absolute path to the `.php` file |
| `fastcgi_param QUERY_STRING` | The URL query string |
| `fastcgi_param HTTPS $https if_not_empty` | Tells PHP if the request was HTTPS (for `Request::isSecure()`) |
| `include fastcgi_params` | The standard FastCGI params (REMOTE_ADDR, etc.) |
| `fastcgi_read_timeout 120` | Max seconds to wait for PHP-FPM to respond |
| `fastcgi_buffers 16 16k` | 16 buffers of 16KB each for the response |
| `fastcgi_buffer_size 32k` | The first buffer (for the response header) |

### 7.7 The security `location` blocks

```nginx
# Block dotfiles (.env, .git, .htaccess)
location ~ /\.(?!well-known).* {
    deny all;
}

# Block internal directories
location ~* ^/(storage|vendor|node_modules|database)/ {
    deny all;
}

# Block sensitive file extensions
location ~* \.(env|git|gitignore|gitattributes|md|lock|sql)$ {
    deny all;
}
```

> The `?!well-known` exception allows `/.well-known/acme-challenge/` for Let's Encrypt
> certificate validation.

### 7.8 Error pages + compression

```nginx
error_page 404 /index.php;
error_page 500 502 503 504 /50x.html;

location = /50x.html {
    root /usr/share/nginx/html;
}

gzip on;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;
gzip_min_length 256;
gzip_comp_level 5;
```

---

## 8. The Docker config (`docker/nginx/default.conf`)

### 8.1 Full file structure

The full file is at `docker/nginx/default.conf` (135 lines). Its structure:

1. `server { listen 80; server_name localhost _; root /var/www/laravel/public; }`
2. Docker DNS resolver + `$app_upstream` variable (R-7).
3. `location /` (Laravel routing).
4. `location /sse/` (SSE with buffering disabled — R-5).
5. `location /build/` (Vite assets).
6. `location ~* \.(css|js|...)$` (static files).
7. `location ~ \.php$` (PHP-FPM proxy via `$app_upstream:9000`).
8. Security blocks (dotfiles, internal dirs, sensitive extensions).
9. Error pages.
10. Gzip.

### 8.2 What's NOT in the Docker config

- No TLS (port 443 / `ssl_certificate`).
- No dual-root (legacy PHP is not served by the Docker stack).
- No HTTP→HTTPS redirect.
- No `server_name` (uses `localhost _` to match any host).

### 8.3 Applying changes

```bash
# Edit the file
nano docker/nginx/default.conf

# Test the config
docker compose exec rcerp_nginx nginx -t

# Reload
docker compose exec rcerp_nginx nginx -s reload

# Or restart the container (heavier)
docker compose restart rcerp_nginx
```

---

## 9. The VPS config (`docs/migration/nginx.conf.example`)

### 9.1 Full file structure

The full file is at `docs/migration/nginx.conf.example` (139 lines). Its structure:

1. `server { listen 80; server_name YOUR_DOMAIN; root /var/www/rcerp_v2; }` (HTTP, redirects
   to HTTPS after certbot).
2. `location /admin { alias /var/www/rcerp_v2/laravel/public; }` — Laravel at `/admin/*`.
3. `location /api { alias /var/www/rcerp_v2/laravel/public; }` — Laravel API at `/api/*`.
4. `location = /up { rewrite ^ /admin/up last; }` — health check.
5. `location /assets { alias /var/www/rcerp_v2/legacy/public/assets; }` — shared static
   assets (served from legacy dir, referenced by both apps).
6. `location /uploads { alias /var/www/rcerp_v2/legacy/public/uploads; }` — user uploads
   (PHP execution blocked).
7. `location / { alias /var/www/rcerp_v2/legacy/public/; }` — legacy PHP at everything
   else.
8. Security blocks (dotfiles, composer.json, package.json, internal dirs).
9. Error pages.
10. Logging (`access_log` + `error_log` to `/var/log/nginx/rcerp_*.log`).
11. `client_max_body_size 5M` (smaller than Docker's 50M — VPS uploads are typically
    smaller).
12. Commented-out HTTPS server block (uncomment after `certbot --nginx`).

### 9.2 The dual-root routing (VPS only)

> ⚠️ This is the **transition-window** config. After the legacy app is decommissioned
> (see `../archive/legacy-read-only.md` §11.3), the VPS config should be simplified to a
> single-root Laravel-only config (matching the Docker config).

```nginx
# Laravel routes
location /admin {
    alias /var/www/rcerp_v2/laravel/public;
    try_files $uri $uri/ /admin/index.php?$query_string;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $request_filename;
        include fastcgi_params;
    }
}

location /api {
    alias /var/www/rcerp_v2/laravel/public;
    try_files $uri $uri/ /api/index.php?$query_string;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $request_filename;
        include fastcgi_params;
    }
}

# Legacy PHP routes (everything else)
location / {
    alias /var/www/rcerp_v2/legacy/public/;
    try_files $uri $uri/ /index.php?$query_string;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm-legacy.sock;  # different socket for legacy PHP version
        fastcgi_param SCRIPT_FILENAME $request_filename;
        include fastcgi_params;
    }
}
```

> Note: the legacy PHP app may require a different PHP version (5.6 or 7.x). In that case,
> run a second PHP-FPM pool on a different Unix socket (e.g.
> `/run/php/php5.6-fpm-legacy.sock`) and point the legacy `location` at it. See
> `vps-bdix-deployment.md` §12 E-6.

### 9.3 The HTTPS server block (after certbot)

```nginx
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name erp.example.com www.erp.example.com;

    ssl_certificate /etc/letsencrypt/live/erp.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/erp.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # ... (copy all location blocks from the HTTP server above)
}
```

`certbot --nginx` modifies the existing config to add this block + the HTTP→HTTPS
redirect. You don't write it by hand.

### 9.4 Applying changes (VPS)

```bash
# Edit the config
sudo nano /etc/nginx/sites-available/rcerp

# Test the config (MUST pass before reload)
sudo nginx -t

# Reload (zero-downtime)
sudo systemctl reload nginx
```

---

## 10. Known edge cases

- **E-1 — Nginx crashes at startup if `rcerp_app` isn't ready (Docker).** Without the
  `$app_upstream` variable trick (R-7), Nginx tries to resolve `rcerp_app` at config-parse
  time and fails. The variable defers resolution to per-request time. Symptom without the
  trick: `nginx: [emerg] host not found in upstream "rcerp_app"`.
- **E-2 — SSE events buffer in Nginx.** Without `fastcgi_buffering off` + `proxy_buffering
  off` + `X-Accel-Buffering: no`, Nginx queues SSE events in its buffer and only sends
  them when the buffer fills (typically 4KB) or the connection closes. Symptom: realtime
  notifications appear in batches of 5–10 instead of one-by-one.
- **E-3 — `client_max_body_size` vs `upload_max_filesize`.** If Nginx allows 50M but PHP
  allows 2M (default), uploads >2M fail with a confusing PHP error. If Nginx allows 2M
  but PHP allows 50M, uploads >2M fail with a Nginx 413 error. Both must match (R-6).
- **E-4 — `try_files $uri $uri/ /index.php?$query_string;` is the Laravel pattern.** Using
  `try_files $uri /index.php;` (without `$uri/`) breaks directory-style routes. Using
  `try_files $uri $uri/ /index.php;` (without `?$query_string`) drops query parameters on
  fallback.
- **E-5 — The `alias` directive vs `root`.** `alias /var/www/laravel/public;` makes
  `/admin/foo` map to `/var/www/laravel/public/foo`. `root /var/www/laravel/public;` makes
  `/admin/foo` map to `/var/www/laravel/public/admin/foo`. Using `root` instead of `alias`
  for the `/admin` location breaks Laravel routing.
- **E-6 — `fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;` vs
  `$realpath_root$fastcgi_script_name;`.** The `$realpath_root` variant resolves symlinks,
  which matters when deploying via symlink swap (blue-green). The `$document_root` variant
  caches the path at config-load time, breaking symlink swaps. The Docker config uses
  `$realpath_root` for this reason.
- **E-7 — `gzip on;` globally vs per-location.** The Docker config has `gzip on;` at the
  server level, but the `/sse/` location overrides with `gzip off;`. Without the override,
  Nginx tries to gzip SSE events, breaking the stream.
- **E-8 — The `/.well-known/acme-challenge/` exception.** The dotfile block
  `location ~ /\.(?!well-known).*` allows `/.well-known/` through. Without the exception,
  Let's Encrypt certificate renewal fails (certbot can't verify domain ownership).
- **E-9 — `server_name _;` is a catch-all.** The Docker config uses `localhost _` to match
  any host header. This is fine for dev but in production, a catch-all `server_name` can
  cause Nginx to serve the wrong site for typosquatted domains. VPS config uses the real
  domain.
- **E-10 — The VPS config has `client_max_body_size 5M` (smaller than Docker's 50M).**
  This is intentional — VPS uploads are typically product images (small). If CSV imports
  (>5M) are needed, raise this. The mismatch with Docker is fine because they're different
  environments.

---

## 11. Future improvements

- **F-1 — Add a `/healthz` endpoint.** Currently the `/up` route is Laravel's health
  check, but it requires booting the full framework. A Nginx-level `/healthz` that returns
  200 without touching PHP would be faster for load-balancer probes.
- **F-2 — Add HTTP/3 (QUIC).** Nginx 1.25+ supports HTTP/3. Enabling it would reduce
  latency for repeat visitors (0-RTT). Requires `listen 443 quic;` + `http3 on;`.
- **F-3 — Add a CDN for static assets.** `/build/*` and `/assets/*` could be served from
  a BDIX-connected CDN. Nginx would proxy to the CDN instead of serving from disk.
- **F-4 — Add rate limiting at the Nginx layer.** Currently rate limiting is in Laravel
  (`ApiRateLimit` middleware). Nginx-level rate limiting (`limit_req_zone`) would protect
  against DDoS before PHP-FPM is invoked.
- **F-5 — Add a security headers block.** `add_header X-Frame-Options DENY;`,
  `X-Content-Type-Options nosniff;`, `Strict-Transport-Security max-age=31536000;`. These
  are currently not set.
- **F-6 — Add a default server block that rejects unknown hosts.** Currently a request
  with an unknown `Host` header falls through to the first server block. A `default_server`
  that returns 444 would close this.
- **F-7 — Document the `fastcgi_request_buffering` directive.** Currently unset (defaults
  to `on`). For large request bodies (CSV imports), turning it off streams the request to
  PHP-FPM instead of buffering in Nginx.
- **F-8 — Migrate the SSE endpoint to a dedicated subdomain.** `sse.erp.example.com`
  would allow SSE-specific Nginx settings without affecting the main app. Also avoids the
  browser's per-domain connection limit (6 connections per domain in HTTP/1.1).
- **F-9 — Add a `robots.txt` + `favicon.ico` location.** Currently these 404, adding
  noise to the access log.
- **F-10 — Add an access-log-based monitoring feed.** Pipe the access log to a log
  aggregator (Prometheus, Loki, Datadog) for 5xx-rate alerting. Currently logs are only
  viewable via `tail -f`.

---

## 12. Verification commands

```bash
# 1. Test the Nginx config (MUST pass before reload)
sudo nginx -t
# Docker: docker compose exec rcerp_nginx nginx -t

# 2. Confirm Nginx is running
sudo systemctl status nginx
# Docker: docker compose ps rcerp_nginx

# 3. Confirm the site responds
curl -sI https://erp.example.com
# Expected: HTTP/2 200 (or 302 redirect to /login)
# Docker: curl -sI http://localhost:8080

# 4. Confirm HTTP redirects to HTTPS (VPS only)
curl -sI http://erp.example.com
# Expected: HTTP/1.1 301 Moved Permanently, Location: https://erp.example.com/

# 5. Confirm static assets are served with cache headers
curl -sI https://erp.example.com/build/assets/app-abc123.js | grep -i cache-control
# Expected: Cache-Control: public, immutable

# 6. Confirm sensitive files are blocked
curl -sI https://erp.example.com/.env
# Expected: HTTP/2 403 Forbidden
curl -sI https://erp.example.com/.git/config
# Expected: HTTP/2 403 Forbidden

# 7. Confirm SSE endpoint streams (requires auth)
curl -N -H "Accept: text/event-stream" https://erp.example.com/sse/events
# Expected: HTTP/1.1 200, Content-Type: text/event-stream, then "data: ..." lines

# 8. Confirm the upload limit
curl -sI -X POST -F "file=@large_file.bin" https://erp.example.com/admin/upload
# Expected: HTTP/2 413 if file > client_max_body_size

# 9. Confirm gzip is working
curl -s -H "Accept-Encoding: gzip" -I https://erp.example.com/admin/dashboard
# Expected: Content-Encoding: gzip

# 10. Confirm the TLS certificate is valid
curl -vI https://erp.example.com 2>&1 | grep -E '(SSL certificate|subject|issuer|expire)'
# Expected: subject: CN=erp.example.com, issuer: Let's Encrypt, expire date in the future
```

---

## 13. Cross-reference summary

| Topic | Where in this file | Cross-ref to other AI_CONTEXT files |
|---|---|---|
| Docker config | §8 | `docker-setup.md` §7 (rcerp_nginx container) |
| VPS config | §9 | `vps-bdix-deployment.md` §8.5 (Nginx install + certbot) |
| SSE configuration | §7.4, R-5 | `../architecture/realtime-events.md` §5 |
| Dual-root routing | §9.2 | `../archive/legacy-overview.md` §11 (transition window) |
| `APP_URL` vs `server_name` | R-2 | `environment.md` §7.1 (APP_URL) |
| `client_max_body_size` vs `upload_max_filesize` | R-6, E-3 | `docker-setup.md` §8.3 (PHP extensions) |
| API security (no Nginx-level auth) | §5 | `../security/api-security.md`, `../api/api-overview.md` §7 |
| TLS via Let's Encrypt | §9.3 | `vps-bdix-deployment.md` §8.5 |

---

*End of `nginx-config.md`. For the Docker stack that mounts this config, see
`docker-setup.md`. For the VPS installation sequence, see `vps-bdix-deployment.md` §8.5.*
