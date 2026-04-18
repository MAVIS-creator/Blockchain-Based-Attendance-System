#!/bin/bash
set -e

# Azure Linux App Service startup script
# This script sets up nginx with custom error pages

APP_ROOT="/home/site/wwwroot"
NGINX_CONF_DIR="/etc/nginx/sites-available"
NGINX_ENABLED_DIR="/etc/nginx/sites-enabled"

echo "=== Starting Blockchain Attendance System Setup ==="

# Ensure nginx is installed
if ! command -v nginx &> /dev/null; then
    echo "Installing nginx..."
    apt-get update
    apt-get install -y nginx php-fpm
fi

# Ensure PHP-FPM is running
echo "Starting PHP-FPM..."
service php-fpm start || service php7.4-fpm start || service php8.0-fpm start || service php8.1-fpm start || true

# Copy the nginx configuration (try multiple locations)
echo "Configuring nginx..."
if [ -f "$APP_ROOT/nginx-azure.conf" ]; then
    cp "$APP_ROOT/nginx-azure.conf" "$NGINX_CONF_DIR/attendance.conf"
    echo "✓ Nginx config copied from nginx-azure.conf"
elif [ -f "$APP_ROOT/docs/references/nginx-server-config.conf" ]; then
    cp "$APP_ROOT/docs/references/nginx-server-config.conf" "$NGINX_CONF_DIR/attendance.conf"
    echo "✓ Nginx config copied from docs/references"
else
    echo "✗ Error: nginx config not found"
    exit 1
fi

# Create the sites-enabled symlink if it doesn't exist
if [ ! -L "$NGINX_ENABLED_DIR/attendance.conf" ]; then
    ln -sf "$NGINX_CONF_DIR/attendance.conf" "$NGINX_ENABLED_DIR/attendance.conf"
    echo "✓ Nginx enabled link created"
fi

# Remove default nginx config if present
if [ -L "$NGINX_ENABLED_DIR/default" ]; then
    rm "$NGINX_ENABLED_DIR/default"
    echo "✓ Removed default nginx config"
fi

# Test nginx configuration
echo "Validating nginx configuration..."
if nginx -t; then
    echo "✓ Nginx config is valid"
else
    echo "✗ Nginx config validation failed"
    exit 1
fi

# Start/reload nginx
echo "Starting/reloading nginx..."
service nginx restart || service nginx start

# Verify nginx is running
if service nginx status > /dev/null 2>&1; then
    echo "✓ Nginx is running"
else
    echo "✗ Nginx failed to start"
    exit 1
fi

# Keep startup fast: avoid recursive chmod across the whole app tree,
# which can exceed App Service warm-up timeout on large deployments.
# Only ensure known writable runtime locations exist.
mkdir -p "$APP_ROOT/storage" "$APP_ROOT/storage/logs" "$APP_ROOT/storage/sessions"
chmod 755 "$APP_ROOT/storage" "$APP_ROOT/storage/logs" "$APP_ROOT/storage/sessions" || true

echo "=== Setup Complete ==="
echo "App Root: $APP_ROOT"
echo "Nginx Config: $NGINX_CONF_DIR/attendance.conf"
echo "Error pages will be automatically served on HTTP errors"
