#!/bin/bash

# Azure Linux App Service startup script
# Minimalist version - just ensure directories exist

APP_ROOT="/home/site/wwwroot"

echo "=== Blockchain Attendance System Startup ==="

# Ensure storage directories exist and have correct permissions
echo "Setting up storage directories..."
PERSIST_BASE="/home/site/storage/attendance_storage_v2"
mkdir -p \
  "$APP_ROOT/storage" \
  "$APP_ROOT/storage/logs" \
  "$APP_ROOT/storage/sessions" \
  "$PERSIST_BASE" \
  "$PERSIST_BASE/logs" \
  "$PERSIST_BASE/secure_logs" \
  "$PERSIST_BASE/backups" \
  /tmp/attendance_sessions 2>/dev/null
chmod 755 \
  "$APP_ROOT/storage" \
  "$APP_ROOT/storage/logs" \
  "$APP_ROOT/storage/sessions" \
  "$PERSIST_BASE" \
  "$PERSIST_BASE/logs" \
  "$PERSIST_BASE/secure_logs" \
  "$PERSIST_BASE/backups" \
  /tmp/attendance_sessions 2>/dev/null || true

echo "✓ Startup complete - app ready"
exit 0

# Note: Nginx and PHP-FPM are managed by Azure App Service automatically.
# The remaining nginx setup was removed to prevent hanging during app startup.
chmod 755 /tmp/attendance_sessions 2>/dev/null || true
echo "✓ Session directory ensured: /tmp/attendance_sessions"

echo "=== Setup Complete ==="
