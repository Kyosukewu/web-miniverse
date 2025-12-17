# Laravel 12 應用容器
FROM php:8.4-fpm

# 設定工作目錄
WORKDIR /var/www/html/web-miniverse

# 安裝系統依賴
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    supervisor \
    cron \
    python3 \
    python3-pip \
    && rm -rf /var/lib/apt/lists/*

# 安裝 yt-dlp（用於 YouTube 字幕下載）
RUN pip3 install --break-system-packages yt-dlp

# 配置 GD 擴展（需要先配置，指定庫路徑以支援 PHP 8.4）
RUN docker-php-ext-configure gd --with-freetype=/usr --with-jpeg=/usr

# 安裝 PHP 擴展（移除 pdo_pgsql，只使用 MySQL）
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 複製應用程式碼
COPY . /var/www/html/web-miniverse

# 設定權限
RUN chown -R www-data:www-data /var/www/html/web-miniverse \
    && chmod -R 755 /var/www/html/web-miniverse/storage \
    && chmod -R 755 /var/www/html/web-miniverse/bootstrap/cache

# 複製 Supervisord 配置
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/supervisord.d/*.conf /etc/supervisor/conf.d/

# 創建必要的目錄
RUN mkdir -p /var/log/supervisor /var/run && \
    chown -R www-data:www-data /var/log/supervisor && \
    chmod -R 755 /var/log/supervisor

# 複製並設置啟動腳本
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# 複製 PHP 配置（如果需要）
# COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# 安裝應用依賴（如果需要在構建時安裝）
# RUN composer install --no-dev --optimize-autoloader

# 暴露端口
EXPOSE 9000

# 使用 entrypoint 腳本啟動
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

