# Browsershot Service - 单阶段Alpine构建
FROM node:22-alpine

# 安装所有依赖：Chromium + PHP + 运行库
RUN apk add --no-cache \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ca-certificates \
    ttf-freefont \
    dbus \
    xvfb \
    php82 \
    php82-cli \
    php82-json \
    php82-openssl \
    php82-mbstring \
    php82-fileinfo \
    bash \
    curl \
    pango \
    libxcomposite \
    libxdamage \
    libxfixes \
    libxrandr \
    mesa-gl

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

# 安装Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# 复制Browsershot源码和服务
COPY src/ /app/src/
COPY bin/ /app/bin/
COPY server/ /app/server/

# 安装PHP依赖
WORKDIR /app/server
RUN composer install --no-dev --optimize-autoloader

WORKDIR /app

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "server", "server/server.php"]