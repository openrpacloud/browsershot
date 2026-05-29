# Browsershot Service - 单阶段Alpine构建
FROM node:22-alpine

# 添加 community 仓库以获取 PHP
RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories && \
    echo "https://mirrors.aliyun.com/alpine/v3.20/community" >> /etc/apk/repositories

# 安装依赖：Chromium + PHP（headless模式精简）
RUN apk add --no-cache \
    curl \
    chromium \
    nss \
    freetype \
    harfbuzz \
    pango \
    ca-certificates \
    ttf-freefont \
    php82 \
    php82-cli \
    php82-json \
    php82-openssl \
    php82-mbstring \
    php82-fileinfo \
    php82-phar \
    php82-curl

# 设置Puppeteer使用系统Chromium
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser \
    NODE_PATH=/usr/local/lib/node_modules \
    MAX_CONCURRENCY=10 \
    DEFAULT_TIMEOUT=30 \
    DEFAULT_WIDTH=1920 \
    DEFAULT_HEIGHT=1080

# 安装Puppeteer
RUN npm install -g puppeteer@23.0.2

RUN ln -s /usr/bin/php82 /usr/bin/php && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

COPY src/ /app/src/
COPY bin/ /app/bin/
COPY server/composer.json /app/server/composer.json

WORKDIR /app/server
RUN composer update --no-dev --optimize-autoloader --with-all-dependencies && \
    echo '<?php // platform check disabled' > vendor/composer/platform_check.php

WORKDIR /app

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "server", "server/server.php"]