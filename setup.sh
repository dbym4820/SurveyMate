#!/bin/bash

#######################################
# AutoSurvey Setup Script
#
# This script sets up the entire application:
# - PHP/Composer dependencies
# - Node.js/npm dependencies
# - Environment configuration
# - Database creation and migrations
# - Database seeding
# - Frontend build (Vite/TypeScript)
# - Laravel optimization
#######################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get script directory (POSIX compatible)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# Try to find PHP if not in PATH
if ! command -v php > /dev/null 2>&1; then
    # Common PHP paths on various systems
    for php_path in /opt/alt/php*/usr/bin/php /opt/cpanel/ea-php*/root/usr/bin/php /usr/local/php*/bin/php /opt/*/php/bin/php /usr/bin/php; do
        if [ -x "$php_path" ] 2>/dev/null; then
            PHP_BIN="$php_path"
            export PATH="$(dirname "$php_path"):$PATH"
            break
        fi
    done
else
    PHP_BIN="php"
fi

# Try to find composer if not in PATH
if ! command -v composer > /dev/null 2>&1; then
    for composer_path in /usr/local/bin/composer /opt/cpanel/composer/bin/composer ~/bin/composer; do
        if [ -x "$composer_path" ] 2>/dev/null; then
            COMPOSER_BIN="$composer_path"
            break
        fi
    done
    # Try composer.phar in current directory
    if [ -z "$COMPOSER_BIN" ] && [ -f "composer.phar" ]; then
        COMPOSER_BIN="$PHP_BIN composer.phar"
    fi
else
    COMPOSER_BIN="composer"
fi

echo "======================================"
echo "  AutoSurvey Setup Script"
echo "======================================"
echo ""

#######################################
# Step 1: Check requirements
#######################################
echo "[1/7] Checking requirements..."

# Check PHP
if [ -z "$PHP_BIN" ] && ! command -v php > /dev/null 2>&1; then
    echo "Error: PHP is not installed or not in PATH"
    echo "Searched paths: /opt/alt/php*/usr/bin/php, /opt/cpanel/ea-php*/root/usr/bin/php, etc."
    exit 1
fi

PHP_BIN="${PHP_BIN:-php}"
PHP_VERSION=$($PHP_BIN -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
echo "  PHP version: $PHP_VERSION (using: $PHP_BIN)"

# Check required PHP version (8.1+)
PHP_MAJOR=$($PHP_BIN -r "echo PHP_MAJOR_VERSION;")
PHP_MINOR=$($PHP_BIN -r "echo PHP_MINOR_VERSION;")
if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 1 ]; }; then
    echo "Error: PHP 8.1 or higher is required (found: $PHP_VERSION)"
    exit 1
fi

# Check Composer
if [ -z "$COMPOSER_BIN" ] && ! command -v composer > /dev/null 2>&1; then
    echo "Error: Composer is not installed"
    echo "  Install from: https://getcomposer.org/"
    exit 1
fi
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
echo "  Composer: OK (using: $COMPOSER_BIN)"

# Check Node.js
if ! command -v node > /dev/null 2>&1; then
    echo "Error: Node.js is not installed"
    exit 1
fi
NODE_VERSION=$(node -v)
echo "  Node.js version: $NODE_VERSION"

# Check npm
if ! command -v npm > /dev/null 2>&1; then
    echo "Error: npm is not installed"
    exit 1
fi
echo "  npm: OK"

# Check MySQL client (optional)
if ! command -v mysql > /dev/null 2>&1; then
    echo "  Warning: MySQL client not found (database creation may fail)"
else
    echo "  MySQL client: OK"
fi

echo ""

#######################################
# Step 2: Setup PHP/Composer
#######################################
echo "[2/7] Installing PHP dependencies..."

$COMPOSER_BIN install --no-interaction --prefer-dist --optimize-autoloader

echo "  PHP dependencies: Complete"
echo ""

#######################################
# Step 3: Environment configuration
#######################################
echo "[3/7] Configuring environment..."

# Create .env if not exists
if [ ! -f .env ]; then
    echo "  Creating .env file from .env.example..."
    cp .env.example .env
    echo "  Note: Please review .env and configure database/API settings"
else
    echo "  .env file already exists"
fi

# Generate app key if not set
if ! grep -q "^APP_KEY=base64:" .env; then
    echo "  Generating application key..."
    $PHP_BIN artisan key:generate --force
else
    echo "  Application key already set"
fi

# Create storage directories
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p bootstrap/cache

# Set permissions
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "  Environment: Complete"
echo ""

#######################################
# Step 4: Setup Node.js/npm
#######################################
echo "[4/7] Installing Node.js dependencies..."

npm install

echo "  Node.js dependencies: Complete"
echo ""

#######################################
# Step 5: Database setup
#######################################
echo "[5/7] Setting up database..."

# Read database configuration from .env
DB_CONNECTION=$(grep "^DB_CONNECTION=" .env | cut -d '=' -f2)
DB_HOST=$(grep "^DB_HOST=" .env | cut -d '=' -f2)
DB_PORT=$(grep "^DB_PORT=" .env | cut -d '=' -f2)
DB_DATABASE=$(grep "^DB_DATABASE=" .env | cut -d '=' -f2)
DB_USERNAME=$(grep "^DB_USERNAME=" .env | cut -d '=' -f2)
DB_PASSWORD=$(grep "^DB_PASSWORD=" .env | cut -d '=' -f2)

# Default values
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-autosurvey}"

echo "  Database: $DB_DATABASE"
echo "  Host: $DB_HOST:$DB_PORT"

# Check if MySQL and create database if needed
if [ "$DB_CONNECTION" = "mysql" ] && command -v mysql > /dev/null 2>&1; then
    echo "  Checking if database exists..."

    # Build MySQL command
    MYSQL_CMD="mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME"
    if [ -n "$DB_PASSWORD" ]; then
        MYSQL_CMD="$MYSQL_CMD -p$DB_PASSWORD"
    fi

    # Check if database exists
    DB_EXISTS=$($MYSQL_CMD -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$DB_DATABASE'" 2>/dev/null | grep -c "$DB_DATABASE" || echo "0")

    if [ "$DB_EXISTS" = "0" ]; then
        echo "  Creating database '$DB_DATABASE'..."
        $MYSQL_CMD -e "CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
        if [ $? -eq 0 ]; then
            echo "  Database created: OK"
        else
            echo "  Failed to create database. Please create it manually."
        fi
    else
        echo "  Database already exists: OK"
    fi
fi

# Check migration status
echo "  Checking migration status..."
MIGRATION_STATUS=$($PHP_BIN artisan migrate:status 2>&1 || echo "error")

if echo "$MIGRATION_STATUS" | grep -q "Migration table not found\|error\|could not find driver"; then
    # Fresh installation - run all migrations
    echo "  Running migrations..."
    $PHP_BIN artisan migrate --force

    echo "  Running database seeders..."
    $PHP_BIN artisan db:seed --force

    echo "  Database migration: Complete"
elif echo "$MIGRATION_STATUS" | grep -q "Pending"; then
    # Pending migrations exist
    echo "  Running pending migrations..."
    $PHP_BIN artisan migrate --force
    echo "  Database migration: Complete"
else
    echo "  All migrations already applied: OK"
fi

echo ""

#######################################
# Step 6: Build Frontend (Vite/TypeScript)
#######################################
echo "[6/7] Building frontend assets (Vite/TypeScript)..."

# Clean previous build
if [ -d "public/build" ]; then
    echo "  Cleaning previous build..."
    rm -rf public/build
fi

# Run Vite build
npm run build

if [ -d "public/build" ] && [ -f "public/build/manifest.json" ]; then
    echo "  Frontend build: Complete"
else
    echo "  Frontend build may have failed. Check for errors above."
fi

echo ""

#######################################
# Step 7: Laravel optimization
#######################################
echo "[7/7] Optimizing Laravel..."

# Clear all caches first
$PHP_BIN artisan config:clear 2>/dev/null || true
$PHP_BIN artisan route:clear 2>/dev/null || true
$PHP_BIN artisan view:clear 2>/dev/null || true
$PHP_BIN artisan cache:clear 2>/dev/null || true

# Check if production mode
APP_ENV=$(grep "^APP_ENV=" .env | cut -d '=' -f2)

if [ "$APP_ENV" = "production" ]; then
    echo "  Production mode detected. Caching configuration..."
    $PHP_BIN artisan config:cache
    $PHP_BIN artisan route:cache
    $PHP_BIN artisan view:cache
    echo "  Optimization: Complete"
else
    echo "  Development mode - skipping cache"
    echo "  Optimization: Skipped (dev mode)"
fi

echo ""

#######################################
# Done
#######################################
echo "======================================"
echo "  Setup Complete!"
echo "======================================"
echo ""
echo "Summary:"
echo "  - PHP dependencies installed"
echo "  - Node.js dependencies installed"
echo "  - Environment configured"
echo "  - Database migrated"
echo "  - Frontend built"
echo ""
echo "Next steps:"
echo ""
echo "1. Review .env configuration:"
echo "   - Database: DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD"
echo "   - AI API keys: CLAUDE_API_KEY or OPENAI_API_KEY"
echo ""
echo "2. Create an admin user:"
echo "   $PHP_BIN artisan user:create"
echo ""
echo "3. For Apache, add to your virtual host config:"
echo ""
echo "   Alias /autosurvey $SCRIPT_DIR/public"
echo "   <Directory $SCRIPT_DIR/public>"
echo "       AllowOverride All"
echo "       Require all granted"
echo "   </Directory>"
echo ""
echo "4. Access the application at:"
echo "   https://your-domain.org/autosurvey/"
echo ""
echo "For development server:"
echo "   $PHP_BIN artisan serve"
echo ""
