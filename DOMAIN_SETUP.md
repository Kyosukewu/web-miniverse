# 網址設定指南

本指南說明如何設定網址 `miniverse.com.tw` 讓使用者可以訪問網站。

## 🚀 快速設定步驟

### 1. 更新 .env 檔案

```bash
# 編輯 .env 檔案
nano .env
```

設定以下變數：
```env
APP_URL=https://miniverse.com.tw
APP_NAME="Miniverse AI Video Insight"
```

**注意**：
- 如果 SRE 團隊已設定 HTTPS，使用 `https://`
- 如果暫時只有 HTTP，使用 `http://`（之後再改為 HTTPS）

### 2. 更新 Nginx 配置

Nginx 配置已更新為 `miniverse.com.tw`，無需額外修改。

如果使用 HTTPS，需要：
1. 將 SSL 憑證放到 `docker/ssl/` 目錄
2. 更新 `docker-compose.yml` 使用 `nginx-ssl.conf`

### 3. 重新啟動容器

```bash
# 重新啟動容器
docker-compose restart

# 清除 Laravel 快取
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear
```

### 4. 驗證設定

```bash
# 檢查 Nginx 配置
docker-compose exec nginx nginx -t

# 測試網站
curl -I http://miniverse.com.tw
# 或
curl -I https://miniverse.com.tw
```

## 📋 與 SRE 團隊協調事項

### 需要 SRE 團隊處理：

1. **DNS 設定**
   ```
   A 記錄：miniverse.com.tw → 主機 IP
   A 記錄：www.miniverse.com.tw → 主機 IP（可選）
   ```

2. **防火牆設定**
   - 開放端口 80 (HTTP)
   - 開放端口 443 (HTTPS，如果使用)

3. **SSL 憑證**（如果使用 HTTPS）
   - 提供憑證檔案：`miniverse.com.tw.crt`
   - 提供私鑰檔案：`miniverse.com.tw.key`
   - 或協助設定 Let's Encrypt

### 您需要提供給 SRE 團隊：

1. **主機 IP 地址**
2. **需要開放的端口**：80, 443
3. **是否需要 SSL 憑證**

---

## 📋 詳細設定步驟

### 1. 更新環境變數

在 `.env` 檔案中設定：

```env
APP_URL=https://miniverse.com.tw
APP_NAME="Miniverse AI Video Insight"
```

**注意**：
- 如果使用 HTTPS，請使用 `https://`
- 如果暫時使用 HTTP，請使用 `http://`（不建議生產環境）

### 2. 更新 Nginx 配置

#### 方案 A：僅 HTTP（暫時，不建議生產環境）

已更新 `docker/nginx.conf`，設定 `server_name` 為 `miniverse.com.tw`。

#### 方案 B：HTTPS（推薦，生產環境）

使用 `docker/nginx-ssl.conf` 配置：

1. 將 SSL 憑證檔案放到主機：
```bash
# 在主機上建立 SSL 目錄
sudo mkdir -p /var/www/web-miniverse/docker/ssl

# 將憑證檔案複製到此目錄
# miniverse.com.tw.crt (憑證)
# miniverse.com.tw.key (私鑰)
```

2. 更新 `docker-compose.yml`，掛載 SSL 憑證：
```yaml
nginx:
  volumes:
    - ./:/var/www/html
    - ./docker/nginx-ssl.conf:/etc/nginx/conf.d/default.conf
    - ./docker/ssl:/etc/nginx/ssl:ro  # SSL 憑證目錄
```

### 3. 更新 docker-compose.yml

確保 Nginx 容器正確配置：

```yaml
nginx:
  image: nginx:alpine
  container_name: web-miniverse-nginx
  restart: unless-stopped
  ports:
    - "80:80"      # HTTP
    - "443:443"    # HTTPS（如果使用 SSL）
  volumes:
    - ./:/var/www/html
    - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf
    # 如果使用 HTTPS，改為：
    # - ./docker/nginx-ssl.conf:/etc/nginx/conf.d/default.conf
    # - ./docker/ssl:/etc/nginx/ssl:ro
  depends_on:
    - app
  networks:
    - web-miniverse-network
```

### 4. DNS 設定（由 SRE 團隊處理）

確保 DNS 記錄指向您的主機 IP：

```
A 記錄：miniverse.com.tw → 主機 IP
A 記錄：www.miniverse.com.tw → 主機 IP（可選）
```

### 5. 防火牆設定

確保主機開放必要端口：

```bash
# HTTP
sudo ufw allow 80/tcp

# HTTPS（如果使用）
sudo ufw allow 443/tcp
```

### 6. 重新啟動容器

```bash
# 重新構建並啟動
docker-compose down
docker-compose up -d --build

# 清除 Laravel 快取
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan route:clear
docker-compose exec app php artisan view:clear
```

### 7. 驗證設定

```bash
# 檢查 Nginx 配置
docker-compose exec nginx nginx -t

# 檢查容器狀態
docker-compose ps

# 測試網站連線
curl -I http://miniverse.com.tw
# 或
curl -I https://miniverse.com.tw
```

## 🔒 SSL 憑證設定（HTTPS）

### 選項 1：使用 SRE 團隊提供的憑證

1. 取得憑證檔案：
   - `miniverse.com.tw.crt` (憑證)
   - `miniverse.com.tw.key` (私鑰)

2. 放置憑證：
```bash
mkdir -p docker/ssl
cp /path/to/miniverse.com.tw.crt docker/ssl/
cp /path/to/miniverse.com.tw.key docker/ssl/
chmod 600 docker/ssl/*.key
```

3. 使用 SSL 配置：
```bash
# 更新 docker-compose.yml 使用 nginx-ssl.conf
# 然後重啟容器
docker-compose restart nginx
```

### 選項 2：使用 Let's Encrypt（免費 SSL）

如果需要自行設定 Let's Encrypt：

```bash
# 安裝 certbot
sudo apt-get install certbot

# 取得憑證（需要在主機上執行，不是容器內）
sudo certbot certonly --standalone -d miniverse.com.tw -d www.miniverse.com.tw

# 憑證會存放在 /etc/letsencrypt/live/miniverse.com.tw/
# 複製到專案目錄
sudo cp /etc/letsencrypt/live/miniverse.com.tw/fullchain.pem docker/ssl/miniverse.com.tw.crt
sudo cp /etc/letsencrypt/live/miniverse.com.tw/privkey.pem docker/ssl/miniverse.com.tw.key
sudo chmod 600 docker/ssl/*.key
```

## 📝 完整設定範例

### .env 檔案設定

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://miniverse.com.tw
APP_NAME="Miniverse AI Video Insight"

# 其他設定...
```

### docker-compose.yml 更新（HTTPS 版本）

```yaml
nginx:
  image: nginx:alpine
  container_name: web-miniverse-nginx
  restart: unless-stopped
  ports:
    - "80:80"
    - "443:443"
  volumes:
    - ./:/var/www/html
    - ./docker/nginx-ssl.conf:/etc/nginx/conf.d/default.conf
    - ./docker/ssl:/etc/nginx/ssl:ro
  depends_on:
    - app
  networks:
    - web-miniverse-network
```

## 🔍 疑難排解

### 問題 1: 無法訪問網站

**檢查項目**：
1. DNS 是否正確指向主機 IP
2. 防火牆是否開放 80/443 端口
3. Nginx 容器是否運行：`docker-compose ps nginx`
4. Nginx 配置是否正確：`docker-compose exec nginx nginx -t`

### 問題 2: SSL 憑證錯誤

**檢查項目**：
1. 憑證檔案是否存在：`ls -la docker/ssl/`
2. 憑證檔案權限：`chmod 600 docker/ssl/*.key`
3. Nginx 配置中的憑證路徑是否正確

### 問題 3: 網站顯示 Laravel 錯誤

**解決方案**：
```bash
# 清除所有快取
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan route:clear
docker-compose exec app php artisan view:clear

# 重新產生配置快取
docker-compose exec app php artisan config:cache
docker-compose exec app php artisan route:cache
```

## 📊 檢查清單

部署前：
- [ ] `.env` 中 `APP_URL` 已設定為正確網址
- [ ] Nginx 配置中的 `server_name` 已更新
- [ ] DNS 記錄已設定（由 SRE 團隊處理）
- [ ] 防火牆已開放 80/443 端口
- [ ] SSL 憑證已準備（如果使用 HTTPS）

部署後：
- [ ] 網站可以正常訪問
- [ ] HTTPS 連線正常（如果使用）
- [ ] Dashboard 可以正常顯示
- [ ] 所有功能正常運作

