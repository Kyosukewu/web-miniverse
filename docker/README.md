# Docker 部署文件

本目錄包含所有 Docker 容器化部署相關的文件和腳本。

## 📁 文件說明

### 部署文件
- **[DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md)** - 完整部署指南 ⭐ 先看這個
  - 包含：主機需求、Docker 安裝、GitHub Token 設定、GCS 設定、完整部署步驟
- **[UPDATE.md](./UPDATE.md)** - 程式碼更新/更版指南
- **[DATABASE_ACCESS.md](./DATABASE_ACCESS.md)** - MySQL 資料庫存取指南

### 部署腳本
- **[deploy-ec2.sh](./deploy-ec2.sh)** - 自動化部署腳本（使用 Personal Access Token）
- **[update.sh](./update.sh)** - 程式碼更新腳本

### 配置文件
- **[supervisord.conf](./supervisord.conf)** - Supervisord 主配置
- **[supervisord.d/laravel-scheduler.conf](./supervisord.d/laravel-scheduler.conf)** - Laravel 排程任務配置
- **[supervisord.d/php-fpm.conf](./supervisord.d/php-fpm.conf)** - PHP-FPM 配置
- **[nginx.conf](./nginx.conf)** - Nginx 配置（可選）

## 🚀 快速開始

詳細說明請參考 [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md) 和 [UPDATE.md](./UPDATE.md)

### 首次部署

```bash
ssh -i your-key.pem ec2-user@your-ec2-ip
export GITHUB_TOKEN=your_token_here
git clone https://${GITHUB_TOKEN}@github.com/username/web-miniverse.git /tmp/web-miniverse
cp /tmp/web-miniverse/docker/deploy-ec2.sh ./ && chmod +x deploy-ec2.sh
export GITHUB_REPO=https://github.com/username/web-miniverse.git
sudo ./deploy-ec2.sh
```

### 更新程式碼

```bash
cd /var/www/html/web-miniverse
GITHUB_TOKEN=your_token ./docker/update.sh
```

## 📋 部署流程

1. **準備環境**
   - 主機（EC2 或其他）
   - GitHub Personal Access Token（如果需要從 GitHub 部署）
   - 環境變數設定（.env）

2. **執行部署**
   - 使用 `deploy-ec2.sh` 自動化部署
   - 或參考 [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md) 手動部署

3. **驗證部署**
   - 檢查容器狀態
   - 檢查排程任務
   - 測試網站功能

4. **後續更新**
   - 使用 `update.sh` 更新程式碼
   - 參考 [UPDATE.md](./UPDATE.md) 了解詳細流程

## 📚 詳細文件

- **完整部署指南**: [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md) - 包含所有部署步驟、GitHub Token 設定、GCS 設定
- **更新流程**: [UPDATE.md](./UPDATE.md) - 程式碼更新和更版
- **資料庫存取**: [DATABASE_ACCESS.md](./DATABASE_ACCESS.md) - MySQL 資料庫存取指南
- **網址設定**: [../DOMAIN_SETUP.md](../DOMAIN_SETUP.md) - 網址設定指南（miniverse.com.tw）

## 🔧 常用命令

```bash
# 查看容器狀態
docker-compose ps

# 查看日誌
docker-compose logs -f

# 進入容器
docker-compose exec app bash

# 執行 Artisan 命令
docker-compose exec app php artisan [command]

# 檢查排程任務
docker-compose exec app supervisorctl status

# 存取資料庫（phpMyAdmin）
# 訪問: http://your-ec2-ip:8080
# 或使用命令列: docker-compose exec db mysql -u root -p web_miniverse
```

## ⚠️ 注意事項

1. **Token 安全**: 不要將 Token 寫在腳本中，使用環境變數
2. **備份**: 更新前務必備份資料庫
3. **測試**: 建議在測試環境先測試更新
4. **監控**: 更新後持續監控日誌

## 🆘 需要幫助？

- 查看 [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md) 的「常見問題」章節
- 查看 [UPDATE.md](./UPDATE.md) 的「常見問題」章節
