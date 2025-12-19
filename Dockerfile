# Laravel 12 應用容器
FROM php:8.4-fpm

# 設定工作目錄
WORKDIR /var/www/html/web-miniverse

# 安裝系統依賴
# 注意：分步安裝以提高可靠性，並在最後清理以節省空間
RUN apt-get update && \
    apt-get install -y \
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
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean \
    && apt-get autoremove -y

# 安裝 yt-dlp（用於 YouTube 字幕下載）
RUN pip3 install --break-system-packages --no-cache-dir yt-dlp

# 安裝 PHP 擴展
# 注意：GD 擴展需要顯式配置 freetype 和 jpeg 支持
# 分步安裝以便於調試和錯誤定位

# 安裝基本擴展（不需要特殊配置）
RUN docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    zip \
    intl

# 安裝 GD 擴展（需要顯式配置）
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# 清理 PHP 源碼以節省空間
RUN docker-php-source delete

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 複製應用程式碼
COPY . /var/www/html/web-miniverse

# 設定權限
RUN chown -R www-data:www-data /var/www/html/web-miniverse \
    && chmod -R 775 /var/www/html/web-miniverse/storage \
    && chmod -R 775 /var/www/html/web-miniverse/bootstrap/cache

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

