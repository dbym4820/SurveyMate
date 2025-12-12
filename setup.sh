#!/bin/bash

#######################################
# SurveyMate Setup Script
#
# This script sets up the entire application:
# - PHP/Composer dependencies
# - Node.js/npm dependencies
# - Environment configuration
# - Database creation and migrations
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
echo "  SurveyMate Setup Script"
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

# Set permissions for Laravel writable directories
echo "  Setting permissions for storage and bootstrap/cache..."

# Detect web server user
WEB_USER=""
for user in www-data apache nginx daemon http _www; do
    if id "$user" >/dev/null 2>&1; then
        WEB_USER="$user"
        break
    fi
done

# Get current user
CURRENT_USER=$(whoami)

# Set ownership and permissions
if [ -n "$WEB_USER" ]; then
    echo "  Detected web server user: $WEB_USER"

    # If running as root or with sudo capability, change ownership
    if [ "$CURRENT_USER" = "root" ] || sudo -n true 2>/dev/null; then
        echo "  Setting ownership to $WEB_USER..."
        sudo chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache 2>/dev/null || \
        chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache 2>/dev/null || true
    else
        echo "  Note: Run with sudo to set proper ownership for web server"
    fi
fi

# Set permissions (777 ensures web server can write regardless of ownership)
chmod -R 777 storage bootstrap/cache 2>/dev/null || \
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Create .gitkeep files
touch storage/logs/.gitkeep 2>/dev/null || true
touch bootstrap/cache/.gitkeep 2>/dev/null || true

echo "  Environment: Complete"
echo ""

#######################################
# Step 4: Setup Node.js/npm
#######################################
echo "[4/7] Installing Node.js dependencies..."

# Clean install to ensure platform-specific binaries are correct
# This handles the case where node_modules was copied from a different OS
if [ -d "node_modules" ]; then
    echo "  Removing existing node_modules (ensures correct platform binaries)..."
    rm -rf node_modules
fi

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
DB_SOCKET=$(grep "^DB_SOCKET=" .env | cut -d '=' -f2)
MYSQL_BIN_ENV=$(grep "^MYSQL_BIN=" .env | cut -d '=' -f2)

# Default values
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-surveymate}"

echo "  Database: $DB_DATABASE"
if [ -n "$DB_SOCKET" ]; then
    echo "  Socket: $DB_SOCKET"
else
    echo "  Host: $DB_HOST:$DB_PORT"
fi

# Check if MySQL and create database if needed
if [ "$DB_CONNECTION" = "mysql" ]; then
    # Find MySQL binary - use MYSQL_BIN from .env if specified
    MYSQL_BIN=""
    if [ -n "$MYSQL_BIN_ENV" ]; then
        if [ -x "$MYSQL_BIN_ENV" ]; then
            MYSQL_BIN="$MYSQL_BIN_ENV"
            echo "  Using MySQL client from .env: $MYSQL_BIN"
        else
            echo "  Warning: MYSQL_BIN=$MYSQL_BIN_ENV not found or not executable"
        fi
    fi
    # Fallback to system mysql
    if [ -z "$MYSQL_BIN" ]; then
        if command -v mysql > /dev/null 2>&1; then
            MYSQL_BIN="mysql"
            echo "  Using system MySQL client"
        fi
    fi

    if [ -n "$MYSQL_BIN" ]; then
        # Build MySQL command
        if [ -n "$DB_SOCKET" ]; then
            MYSQL_CMD="$MYSQL_BIN -S $DB_SOCKET -u $DB_USERNAME"
        else
            MYSQL_CMD="$MYSQL_BIN -h $DB_HOST -P $DB_PORT -u $DB_USERNAME"
        fi
        if [ -n "$DB_PASSWORD" ]; then
            MYSQL_CMD="$MYSQL_CMD -p$DB_PASSWORD"
        fi

        # Check if database exists (use -N to skip column headers)
        DB_EXISTS=$($MYSQL_CMD -N -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$DB_DATABASE'" 2>/dev/null || echo "")

        if [ -n "$DB_EXISTS" ]; then
            echo ""
            echo -e "  ${YELLOW}Warning: Database '$DB_DATABASE' already exists.${NC}"
            echo -e "  ${RED}All existing data will be deleted!${NC}"
            echo ""
            read -p "  Do you want to DROP and recreate the database? [y/N]: " CONFIRM
            echo ""

            if [ "$CONFIRM" = "y" ] || [ "$CONFIRM" = "Y" ]; then
                echo "  Dropping existing database '$DB_DATABASE'..."
                $MYSQL_CMD -e "DROP DATABASE \`$DB_DATABASE\`;" 2>/dev/null
                if [ $? -eq 0 ]; then
                    echo "  Database dropped: OK"
                else
                    echo "  Failed to drop database."
                    exit 1
                fi
                DB_EXISTS=""
            else
                echo "  Keeping existing database."
            fi
        fi

        if [ -z "$DB_EXISTS" ]; then
            echo "  Creating database '$DB_DATABASE'..."
            $MYSQL_CMD -e "CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
            if [ $? -eq 0 ]; then
                echo "  Database created: OK"
            else
                echo "  Failed to create database. Please create it manually."
            fi
        fi
    else
        echo "  Warning: MySQL client not found. Please create database manually."
    fi
fi

# Run migrations
echo "  Running migrations..."
$PHP_BIN artisan migrate --force
echo "  Database migration: Complete"

echo ""

#######################################
# Step 5.5: Generate PWA icons (if missing)
#######################################
if [ ! -f "public/icon-192.png" ] || [ ! -f "public/icon-512.png" ]; then
    echo "[5.5/7] Generating PWA icons..."

    $PHP_BIN -r '
    function createIcon($size, $filename) {
        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        $bg = imagecolorallocate($img, 79, 70, 229);
        imagefill($img, 0, 0, $bg);
        $white = imagecolorallocate($img, 255, 255, 255);
        $padding = (int)($size * 0.1);
        imagesetthickness($img, max(2, (int)($size * 0.02)));
        imagearc($img, (int)($size/2), (int)($size/2), $size - $padding*2, $size - $padding*2, 0, 360, $white);
        imagepng($img, $filename);
    }
    if (!file_exists("public/icon-192.png")) createIcon(192, "public/icon-192.png");
    if (!file_exists("public/icon-512.png")) createIcon(512, "public/icon-512.png");
    ' 2>/dev/null

    if [ -f "public/icon-192.png" ] && [ -f "public/icon-512.png" ]; then
        echo "  PWA icons: Generated"
    else
        echo "  Warning: Could not generate PWA icons (GD extension may be missing)"
    fi
else
    echo "[5.5/7] PWA icons already exist"
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

# Final permission fix (after cache generation)
echo "  Ensuring final permissions..."
chmod -R 777 storage bootstrap/cache 2>/dev/null || \
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

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
echo "1. Ensure .env is configured:"
echo "   - Database: DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD"
echo "   - Admin user: ADMIN_USER_ID, ADMIN_USERNAME, ADMIN_PASSWORD, ADMIN_EMAIL"
echo ""
echo "2. For Apache, add to your virtual host config:"
echo ""
echo "   Alias /surveymate $SCRIPT_DIR/public"
echo "   <Directory $SCRIPT_DIR/public>"
echo "       AllowOverride All"
echo "       Require all granted"
echo "   </Directory>"
echo ""
echo "3. Access the application at:"
echo "   https://your-domain.org/surveymate/"
echo ""
echo "For development server:"
echo "   $PHP_BIN artisan serve"
echo ""
echo "Troubleshooting permissions (if you see 'Permission denied' errors):"
echo "   sudo chown -R www-data:www-data storage bootstrap/cache"
echo "   sudo chmod -R 777 storage bootstrap/cache"
echo "   (Replace 'www-data' with your web server user: apache, nginx, daemon, etc.)"
echo ""
