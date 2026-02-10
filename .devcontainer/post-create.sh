#!/bin/bash

# ============================================================================
# Azure Alt-Text Pipeline - Devcontainer Post-Create Hook
# ============================================================================
# This script runs after the devcontainer is created.
# Sets up Composer, environment, and optional Azure Storage Emulator.

set -e

echo "📦 Installing PHP extensions..."
apt-get update && apt-get install -y \
  curl \
  git \
  zip \
  unzip \
  libssl-dev \
  && rm -rf /var/lib/apt/lists/*

echo "🎼 Installing Composer..."
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

echo "📁 Installing PHP dependencies..."
cd /workspace/src/functions/AltPipeline.Function
composer install --no-interaction --prefer-dist

echo "📝 Setting up .env file..."
if [ ! -f /workspace/.env ]; then
  cp /workspace/.env.sample /workspace/.env
  echo "✅ Created .env from .env.sample (edit with your Azure credentials)"
else
  echo "✅ .env file already exists"
fi

echo "📊 Installing Azure CLI (optional, for deployment)..."
# Use bookworm (Debian 12) repo since trixie (testing) isn't supported yet
apt-get update && apt-get install -y \
  apt-transport-https \
  ca-certificates \
  curl \
  gnupg \
  lsb-release \
  && curl -sL https://packages.microsoft.com/keys/microsoft.asc | \
  gpg --dearmor | tee /etc/apt/trusted.gpg.d/microsoft.gpg > /dev/null \
  && echo "deb [arch=amd64 signed-by=/etc/apt/trusted.gpg.d/microsoft.gpg] https://packages.microsoft.com/repos/azure-cli/ bookworm main" | \
  tee /etc/apt/sources.list.d/azure-cli.list \
  && apt-get update && apt-get install -y azure-cli \
  && rm -rf /var/lib/apt/lists/*

echo ""
echo "✅ Devcontainer setup complete!"
echo ""
echo "🚀 Next steps:"
echo "   1. Edit /workspace/.env with your Azure credentials"
echo "   2. Run: php -S localhost:8080 -t /workspace/src/functions/AltPipeline.Function"
echo "   3. Test: curl -X GET http://localhost:8080/health"
echo "   4. Run tests: ./vendor/bin/phpunit"
echo ""
