#!/bin/bash
set -e

# Azure Linux App Service startup script
# Configures nginx to proxy to PHP-FPM Unix socket

APP_ROOT="/home/site/wwwroot"
NGINX_CONF_DIR="/etc/nginx/sites-available"
NGINX_ENABLED_DIR="/etc/nginx/sites-enabled"

echo "=== Starting Blockchain Attendance System Setup ==="

# Copy the nginx configuration
echo "Configuring nginx..."
if [ -f "$APP_ROOT/nginx-azure.conf" ]; then
    cp "$APP_ROOT/nginx-azure.conf" "$NGINX_CONF_DIR/attendance.conf"
    echo "✓ Nginx config copied from nginx-azure.conf"
else
    echo "✗ Error: nginx config not found at $APP_ROOT/nginx-azure.conf"
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
if nginx -t 2>&1; then
    echo "✓ Nginx config is valid"
else
    echo "✗ Nginx config validation failed"
    exit 1
fi

# Ensure storage directories exist and have correct permissions
mkdir -p "$APP_ROOT/storage" "$APP_ROOT/storage/logs" "$APP_ROOT/storage/sessions"
chmod 755 "$APP_ROOT/storage" "$APP_ROOT/storage/logs" "$APP_ROOT/storage/sessions" 2>/dev/null || true

# Ensure shared persistent session directory exists on Azure's /home volume.
# This MUST be writable before PHP-FPM starts, or sessions will fall back to /tmp.
mkdir -p /home/data/attendance_sessions
chmod 755 /home/data/attendance_sessions 2>/dev/null || true
echo "✓ Session directory ensured: /home/data/attendance_sessions"

echo "=== Setup Complete ==="

