# MySQL 資料庫存取指南

本文檔說明如何查看和存取 Docker 容器中的 MySQL 資料庫。

## 🚀 快速開始（使用 phpMyAdmin）

專案已包含 phpMyAdmin，最簡單的方式是使用圖形介面：

1. **啟動容器**（如果還沒啟動）
   ```bash
   docker-compose up -d
   ```

2. **訪問 phpMyAdmin**
   - 網址：`http://your-ec2-ip:8080` 或 `http://localhost:8080`
   - 登入資訊：
     - **伺服器**: `db`（或留空）
     - **用戶名**: `root`（或 `.env` 中的 `DB_USERNAME`）
     - **密碼**: `.env` 檔案中的 `DB_PASSWORD`

3. **開始使用**
   - 選擇資料庫 `web_miniverse`
   - 查看和管理資料表

## 📋 資料庫資訊

根據 `docker-compose.yml` 配置：

- **容器名稱**: `web-miniverse-db`
- **端口映射**: `3306:3306`（主機 3306 → 容器 3306）
- **資料庫名稱**: `web_miniverse`（從 `.env` 的 `DB_DATABASE` 讀取）
- **用戶名**: `root`（從 `.env` 的 `DB_USERNAME` 讀取）
- **密碼**: 從 `.env` 的 `DB_PASSWORD` 讀取
- **資料儲存**: 命名卷 `db_data`（持久化儲存）

## 🔍 方法一：從主機直接連線（推薦）

因為端口已映射到主機，可以直接從主機連線：

### 使用 MySQL 命令列工具

```bash
# 如果主機已安裝 MySQL 客戶端
mysql -h 127.0.0.1 -P 3306 -u root -p

# 或使用 localhost
mysql -h localhost -P 3306 -u root -p

# 直接指定資料庫
mysql -h 127.0.0.1 -P 3306 -u root -p web_miniverse
```

**輸入密碼**：輸入 `.env` 檔案中 `DB_PASSWORD` 的值

### 使用 MySQL 命令（一行）

```bash
# 從 .env 讀取密碼並連線
mysql -h 127.0.0.1 -P 3306 -u root -p$(grep DB_PASSWORD .env | cut -d '=' -f2) web_miniverse
```

## 🔍 方法二：進入容器使用 MySQL 命令

### 進入資料庫容器

```bash
# 進入 MySQL 容器
docker-compose exec db bash

# 或使用容器名稱
docker exec -it web-miniverse-db bash
```

### 在容器內使用 MySQL

```bash
# 連線到 MySQL（密碼從環境變數讀取）
mysql -u root -p

# 或直接指定資料庫
mysql -u root -p web_miniverse

# 如果知道密碼，可以直接連線
mysql -u root -p${MYSQL_ROOT_PASSWORD} web_miniverse
```

## 🔍 方法三：使用 Docker Exec 直接執行 MySQL 命令

### 執行單一 SQL 命令

```bash
# 查看所有資料庫
docker-compose exec db mysql -u root -p -e "SHOW DATABASES;"

# 查看資料表
docker-compose exec db mysql -u root -p web_miniverse -e "SHOW TABLES;"

# 查詢資料
docker-compose exec db mysql -u root -p web_miniverse -e "SELECT * FROM users LIMIT 10;"
```

### 執行 SQL 檔案

```bash
# 執行 SQL 檔案
docker-compose exec -T db mysql -u root -p web_miniverse < your_script.sql

# 或從容器內執行
docker-compose exec db mysql -u root -p web_miniverse < your_script.sql
```

## 🔍 方法四：使用 GUI 工具（圖形介面）

### 使用 MySQL Workbench、phpMyAdmin、DBeaver 等

**連線資訊：**
- **Host**: `127.0.0.1` 或 `localhost`
- **Port**: `3306`
- **Username**: `root`（或 `.env` 中的 `DB_USERNAME`）
- **Password**: `.env` 檔案中的 `DB_PASSWORD`
- **Database**: `web_miniverse`（或 `.env` 中的 `DB_DATABASE`）

### 使用 phpMyAdmin（已包含）

專案已包含 phpMyAdmin 服務，啟動容器後即可使用：

```bash
# 啟動所有服務（包含 phpMyAdmin）
docker-compose up -d

# 訪問 phpMyAdmin
# 網址: http://your-ec2-ip:8080
# 或: http://localhost:8080（如果在本機）
```

**登入資訊：**
- **伺服器**: `db`（或留空，會自動偵測）
- **用戶名**: `root`（或 `.env` 中的 `DB_USERNAME`）
- **密碼**: `.env` 檔案中的 `DB_PASSWORD`

**注意**：如果是在 EC2 上，需要：
1. 確保安全群組開放 8080 端口
2. 訪問 `http://your-ec2-ip:8080`

## 📁 查看資料檔案位置

### 查看 Docker Volume 位置

```bash
# 查看 volume 資訊
docker volume inspect web-miniverse_db_data

# 查看 volume 的實際位置
docker volume inspect web-miniverse_db_data | grep Mountpoint
```

**注意**：資料檔案位於 Docker 管理的目錄中，通常類似：
- Linux: `/var/lib/docker/volumes/web-miniverse_db_data/_data`
- macOS/Windows: Docker Desktop 管理的虛擬機中

### 直接存取資料檔案（不推薦）

```bash
# 查看 volume 的實際路徑
docker volume inspect web-miniverse_db_data

# 進入 volume 目錄（需要 root 權限）
sudo ls -la /var/lib/docker/volumes/web-miniverse_db_data/_data
```

## 🔧 常用 MySQL 操作

### 查看資料庫

```bash
# 方法 1：從主機
mysql -h 127.0.0.1 -P 3306 -u root -p -e "SHOW DATABASES;"

# 方法 2：從容器
docker-compose exec db mysql -u root -p -e "SHOW DATABASES;"
```

### 查看資料表

```bash
# 查看所有資料表
docker-compose exec db mysql -u root -p web_miniverse -e "SHOW TABLES;"

# 查看資料表結構
docker-compose exec db mysql -u root -p web_miniverse -e "DESCRIBE videos;"
```

### 查詢資料

```bash
# 查詢資料
docker-compose exec db mysql -u root -p web_miniverse -e "SELECT * FROM videos LIMIT 10;"

# 統計資料
docker-compose exec db mysql -u root -p web_miniverse -e "SELECT COUNT(*) FROM videos;"
```

### 備份資料庫

```bash
# 備份整個資料庫
docker-compose exec db mysqldump -u root -p web_miniverse > backup_$(date +%Y%m%d).sql

# 備份特定資料表
docker-compose exec db mysqldump -u root -p web_miniverse videos > videos_backup.sql
```

### 還原資料庫

```bash
# 還原資料庫
docker-compose exec -T db mysql -u root -p web_miniverse < backup_20240101.sql

# 或從主機還原
mysql -h 127.0.0.1 -P 3306 -u root -p web_miniverse < backup_20240101.sql
```

## 🔐 取得資料庫密碼

### 從 .env 檔案讀取

```bash
# 查看資料庫密碼
grep DB_PASSWORD .env

# 只顯示密碼值
grep DB_PASSWORD .env | cut -d '=' -f2
```

### 從容器環境變數讀取

```bash
# 查看容器的環境變數
docker-compose exec db env | grep MYSQL

# 或
docker inspect web-miniverse-db | grep -A 20 "Env"
```

## 🛠️ 疑難排解

### 無法連線資料庫

```bash
# 1. 檢查容器是否運行
docker-compose ps db

# 2. 檢查端口是否正確映射
docker-compose ps db
# 應該顯示 0.0.0.0:3306->3306/tcp

# 3. 檢查容器日誌
docker-compose logs db

# 4. 測試連線
docker-compose exec db mysqladmin -u root -p ping
```

### 忘記密碼

```bash
# 1. 查看 .env 檔案
cat .env | grep DB_PASSWORD

# 2. 或查看容器環境變數
docker-compose exec db env | grep MYSQL_ROOT_PASSWORD
```

### 資料庫連線被拒絕

```bash
# 檢查 MySQL 是否允許遠端連線
docker-compose exec db mysql -u root -p -e "SELECT user, host FROM mysql.user;"

# 如果需要，允許 root 從任何主機連線（不推薦生產環境）
docker-compose exec db mysql -u root -p -e "GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' IDENTIFIED BY 'your_password';"
```

## 📊 快速參考

### 最常用的命令

```bash
# 1. 進入 MySQL 命令列
docker-compose exec db mysql -u root -p web_miniverse

# 2. 查看所有資料表
docker-compose exec db mysql -u root -p web_miniverse -e "SHOW TABLES;"

# 3. 備份資料庫
docker-compose exec db mysqldump -u root -p web_miniverse > backup.sql

# 4. 從主機連線（如果已安裝 MySQL 客戶端）
mysql -h 127.0.0.1 -P 3306 -u root -p web_miniverse
```

## 🔒 安全建議

1. **不要使用預設密碼**：確保 `DB_PASSWORD` 不是 `root` 或 `password`
2. **限制遠端存取**：生產環境建議移除端口映射，只允許容器內部存取
3. **定期備份**：使用 `mysqldump` 定期備份資料庫
4. **使用強密碼**：資料庫密碼應該足夠複雜

## 📚 相關文件

- [MySQL 官方文件](https://dev.mysql.com/doc/)
- [Docker Volume 文件](https://docs.docker.com/storage/volumes/)

