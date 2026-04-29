#!/bin/bash

# Azure Linux App Service startup script
# Minimalist version - just ensure directories exist

APP_ROOT="/home/site/wwwroot"

echo "=== Blockchain Attendance System Startup ==="

# Ensure storage directories exist and have correct permissions
echo "Setting up storage directories..."
mkdir -p "$APP_ROOT/storage" "$APP_ROOT/storage/logs" "$APP_ROOT/storage/sessions" /home/data/attendance_sessions 2>/dev/null
chmod 755 "$APP_ROOT/storage" "$APP_ROOT/storage/logs" "$APP_ROOT/storage/sessions" 2>/dev/null || true

echo "✓ Startup complete - app ready"
exit 0

# Note: Nginx and PHP-FPM are managed by Azure App Service automatically.
# The remaining nginx setup was removed to prevent hanging during app startup.
chmod 755 /home/data/attendance_sessions 2>/dev/null || true
echo "✓ Session directory ensured: /home/data/attendance_sessions"

echo "=== Setup Complete ==="

