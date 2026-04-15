# Nginx Configuration for Custom Error Pages

The hosted site is running **nginx**, so `.htaccess` rules are ignored there. If you want the custom PHP error pages to show up on the hosted server, the nginx server block must map them explicitly.

## Quick Start

See the complete server configuration: [nginx-server-config.conf](nginx-server-config.conf)

That file includes:

- Custom error page directives
- PHP-FPM routing
- Security headers
- Protected file/directory rules
- Static asset caching

## Minimal Error Pages Block

If you only need to add error pages to an existing nginx config:

```nginx
# In your server block:
error_page 400 /400.php;
error_page 401 /401.php;
error_page 403 /403.php;
error_page 404 /404.php;
error_page 405 /405.php;
error_page 408 /408.php;
error_page 429 /429.php;
error_page 500 /500.php;
error_page 502 /502.php;
error_page 503 /503.php;
error_page 504 /504.php;

# Prevent direct public access to error PHP files:
location ~ ^/(400|401|403|404|405|408|429|500|502|503|504)\.php$ {
    internal;
}
```

## Deployment Steps

1. **Web host with control panel** (cPanel, Plesk, etc.)
   - Ask your hosting provider to update the nginx config for your domain, or
   - Check if they allow custom `.conf` files in your account settings

2. **Self-hosted or VPS**
   - Edit `/etc/nginx/sites-available/your-domain.conf` (or equivalent)
   - Use the full config from `nginx-server-config.conf` as a template
   - Replace `server_name`, `root`, and socket paths as needed
   - Run `sudo nginx -t` to verify syntax
   - Run `sudo systemctl reload nginx` to apply

3. **Verify it works**
   - Try accessing a non-existent page: `https://your-domain.com/nonexistent` → should show 404.php
   - Try accessing a forbidden file: `https://your-domain.com/.env` → should show 403.php

## Notes

- Keep all `.php` error files at the document root (same level as `index.php`).
- On Apache, `.htaccess` works; on nginx, only the server config applies.
- If your hosting provider manages nginx for you, you may need to request a server-level config update or provide them with the snippet above.
