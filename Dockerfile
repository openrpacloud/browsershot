FROM node:22-alpine

RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories && \
    echo "https://mirrors.aliyun.com/alpine/v3.20/community" >> /etc/apk/repositories

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
    php82-curl && \
    ln -s /usr/bin/php82 /usr/bin/php

ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser \
    NODE_PATH=/usr/local/lib/node_modules \
    MAX_CONCURRENCY=10 \
    DEFAULT_TIMEOUT=30 \
    DEFAULT_WIDTH=1920 \
    DEFAULT_HEIGHT=1080

RUN npm install -g puppeteer@23.0.2

WORKDIR /app

COPY src/ /app/src/
COPY bin/ /app/bin/
COPY server/vendor/ /app/server/vendor/
COPY server/server.php /app/server/server.php

RUN echo '<?php // platform check disabled' > /app/server/vendor/composer/platform_check.php

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "server", "server/server.php"]