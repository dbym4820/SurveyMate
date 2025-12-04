#!/bin/bash

#######################################
# AutoSurvey Setup Script
#
# This script sets up the entire application:
# - PHP/Composer dependencies
# - Node.js/npm dependencies
# - Database migrations
# - Frontend build
#######################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "${BLUE}======================================${NC}"
echo -e "${BLUE}  AutoSurvey Setup Script${NC}"
echo -e "${BLUE}======================================${NC}"
echo ""

#######################################
# Check requirements
#######################################
echo -e "${YELLOW}[1/6] Checking requirements...${NC}"

# Check PHP
if ! command -v php &> /dev/null; then
    echo -e "${RED}Error: PHP is not installed${NC}"
    exit 1
fi
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
echo -e "  PHP version: ${GREEN}${PHP_VERSION}${NC}"

# Check Composer
if ! command -v composer &> /dev/null; then
    echo -e "${RED}Error: Composer is not installed${NC}"
    echo "  Install from: https://getcomposer.org/"
    exit 1
fi
echo -e "  Composer: ${GREEN}OK${NC}"

# Check Node.js
if ! command -v node &> /dev/null; then
    echo -e "${RED}Error: Node.js is not installed${NC}"
    exit 1
fi
NODE_VERSION=$(node -v)
echo -e "  Node.js version: ${GREEN}${NODE_VERSION}${NC}"

# Check npm
if ! command -v npm &> /dev/null; then
    echo -e "${RED}Error: npm is not installed${NC}"
    exit 1
fi
echo -e "  npm: ${GREEN}OK${NC}"

echo ""

#######################################
# Setup PHP/Composer
#######################################
echo -e "${YELLOW}[2/6] Installing PHP dependencies...${NC}"

composer install --no-interaction --prefer-dist --optimize-autoloader

# Create .env if not exists
if [ ! -f .env ]; then
    echo "  Creating .env file..."
    cp .env.example .env
    echo -e "  ${YELLOW}Warning: Please configure .env file with your database settings${NC}"
fi

# Generate app key if not set
if ! grep -q "^APP_KEY=base64:" .env; then
    echo "  Generating application key..."
    php artisan key:generate --force
fi

# Create storage directories
mkdir -p storage/framework/{cache,sessions,views}
mkdir -p storage/logs
mkdir -p bootstrap/cache

# Set permissions
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo -e "  PHP dependencies: ${GREEN}Complete${NC}"
echo ""

#######################################
# Setup Node.js/npm
#######################################
echo -e "${YELLOW}[3/6] Installing Node.js dependencies...${NC}"

npm install

echo -e "  Node.js dependencies: ${GREEN}Complete${NC}"
echo ""

#######################################
# Database Migration
#######################################
echo -e "${YELLOW}[4/6] Running database migrations...${NC}"

# Create database if not exists (MySQL)
DB_DATABASE=$(grep "^DB_DATABASE=" .env | cut -d '=' -f2)
DB_USERNAME=$(grep "^DB_USERNAME=" .env | cut -d '=' -f2)
DB_PASSWORD=$(grep "^DB_PASSWORD=" .env | cut -d '=' -f2)
DB_HOST=$(grep "^DB_HOST=" .env | cut -d '=' -f2)

if [ -n "$DB_DATABASE" ]; then
    echo "  Creating database '${DB_DATABASE}' if not exists..."
    if [ -n "$DB_PASSWORD" ]; then
        mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || true
    else
        mysql -h "$DB_HOST" -u "$DB_USERNAME" -e "CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || true
    fi
fi

# Run migrations
echo "  Running migrations..."
php artisan migrate --force

# Run seeders
echo "  Running database seeders..."
php artisan db:seed --force

echo -e "  Database setup: ${GREEN}Complete${NC}"
echo ""

#######################################
# Build Frontend
#######################################
echo -e "${YELLOW}[5/6] Building frontend assets...${NC}"

npm run build

echo -e "  Frontend build: ${GREEN}Complete${NC}"
echo ""

#######################################
# Cache Laravel configs (production)
#######################################
echo -e "${YELLOW}[6/6] Optimizing for production...${NC}"

# Only cache if APP_ENV is production
if grep -q "APP_ENV=production" .env 2>/dev/null; then
    echo "  Caching configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    echo -e "  Optimization: ${GREEN}Complete${NC}"
else
    echo -e "  Skipping cache (development mode)"
fi

echo ""

#######################################
# Done
#######################################
echo -e "${GREEN}======================================${NC}"
echo -e "${GREEN}  Setup Complete!${NC}"
echo -e "${GREEN}======================================${NC}"
echo ""
echo "Next steps:"
echo ""
echo "1. Configure .env with your database settings:"
echo "   - DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD"
echo "   - CLAUDE_API_KEY or OPENAI_API_KEY"
echo ""
echo "2. Run database migrations (if not done):"
echo "   php artisan migrate"
echo ""
echo "3. Create an admin user:"
echo "   php artisan user:create"
echo ""
echo "4. For Apache, add to your virtual host config:"
echo ""
echo "   Alias /autosurvey ${SCRIPT_DIR}/public"
echo "   <Directory ${SCRIPT_DIR}/public>"
echo "       AllowOverride All"
echo "       Require all granted"
echo "   </Directory>"
echo ""
echo "5. Access the application at:"
echo "   https://your-domain.org/autosurvey/"
echo ""
