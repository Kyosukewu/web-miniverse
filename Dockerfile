# Laravel 12 應用容器
FROM php:8.4-fpm

# 設定工作目錄
WORKDIR /var/www/html/web-miniverse

# 安裝系統依賴
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    supervisor \
    cron \
    python3 \
    python3-pip \
    && rm -rf /var/lib/apt/lists/*

# 安裝 yt-dlp（用於 YouTube 字幕下載）
RUN pip3 install --break-system-packages yt-dlp

# 安裝 PHP 擴展
RUN docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 複製應用程式碼
COPY . /var/www/html/web-miniverse

# 設定權限
RUN chown -R www-data:www-data /var/www/html/web-miniverse \
    && chmod -R 755 /var/www/html/web-miniverse/storage \
    && chmod -R 755 /var/www/html/web-miniverse/bootstrap/cache

# 複製 Supervisord 配置
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/supervisord.d/ /etc/supervisor/conf.d/

# 複製 PHP 配置（如果需要）
# COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# 安裝應用依賴（如果需要在構建時安裝）
# RUN composer install --no-dev --optimize-autoloader

# 暴露端口（如果需要）
# EXPOSE 9000

# 啟動 Supervisord（管理 PHP-FPM 和 Laravel Scheduler）
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

