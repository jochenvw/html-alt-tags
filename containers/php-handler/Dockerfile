# ============================================================================
# Azure Alt-Text Pipeline - PHP Handler Dockerfile
# ============================================================================
# Builds a lightweight PHP 8.3 container for serverless alt text generation.
#
# Usage:
#   docker build -t php-handler:latest containers/php-handler/
#   docker run -p 8080:8080 php-handler:latest

FROM php:8.3-cli-alpine

# Set working directory
WORKDIR /app

# Install system dependencies (curl, git required for Composer and runtime)
RUN apk add --no-cache \
    curl \
    git \
    zip \
    unzip

# Install Composer
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

# Copy function source code
COPY src/functions/AltPipeline.Function/ /app/

# Copy all system prompts (preserving source-specific filenames)
COPY prompts/ /app/config/prompts/

# Copy and make start script executable
COPY containers/php-handler/start.sh /app/start.sh
RUN chmod +x /app/start.sh

# Install PHP dependencies (no dev, optimized)
RUN composer install \
    --no-dev \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

# Create directories for logs and cache
RUN mkdir -p /app/logs /app/cache && chmod 777 /app/logs /app/cache

# Set environment variables
ENV LOG_LEVEL=info
ENV PHP_CLI_SERVER_WORKERS=4

# Expose port 8080 for HTTP
EXPOSE 8080

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/health || exit 1

# Start PHP built-in server
CMD ["./start.sh"]
