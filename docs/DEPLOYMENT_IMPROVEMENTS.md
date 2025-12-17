# 部署流程改進

## 問題說明

原本的 `deploy.sh` 部署腳本存在以下問題：

### ❌ **原有問題**

**開發環境部署流程缺少必要步驟：**
- ❌ 沒有安裝/更新 Composer 套件（`composer install`）
- ❌ 沒有執行資料庫遷移（`php artisan migrate`）
- ❌ 沒有清除應用快取
- ❌ 沒有優化自動載入
- ❌ 導致新增的套件依賴不會被安裝
- ❌ 導致新增的 migration（如 `file_size_mb` 欄位）不會被建立

**生產環境也缺少套件管理：**
- ✅ 生產環境（`--env=production`）有執行 migrate
- ❌ 但也沒有執行 `composer install`
- ❌ 也沒有效能優化快取

## 解決方案

### ✅ **修正後的開發環境部署流程**

現在開發環境部署包含完整的 14 個步驟：

1. 停止現有容器
2. 重建容器（或跳過）
3. 啟動容器
4. 等待容器完全啟動
5. 檢查容器狀態
6. 檢查 Supervisor 狀態
7. 檢查排程配置
8. **🆕 安裝/更新 Composer 套件** ← 新增
9. **🆕 執行資料庫遷移** ← 新增
10. **🆕 清除應用快取** ← 新增
11. **🆕 優化自動載入** ← 新增
12. 列出排程任務
13. 手動執行排程測試
14. 查看排程日誌

### 📝 **新增的步驟**

#### 步驟 8：安裝/更新 Composer 套件（開發環境）
```bash
docker compose exec app composer install --optimize-autoloader
```
- 安裝 `composer.json` 中定義的所有套件
- 包含 dev 依賴（用於開發和測試）
- 自動優化 autoloader

**生產環境版本**：
```bash
docker compose exec app composer install --no-dev --optimize-autoloader --no-interaction
```
- 不安裝 dev 依賴
- 無互動模式
- 優化 autoloader

#### 步驟 9：執行資料庫遷移
```bash
docker compose exec app php artisan migrate --force
```
- 自動執行所有待執行的 migration
- 使用 `--force` 避免生產環境提示

#### 步驟 10：清除應用快取
```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan view:clear
```
- 清除配置快取
- 清除路由快取
- 清除視圖快取

#### 步驟 11：優化自動載入（開發環境）
```bash
docker compose exec app composer dump-autoload --optimize
```
- 重新生成優化的 autoloader
- 提升類別載入效能

**生產環境額外優化**：
```bash
# 快取配置、路由、視圖
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache

# 生成權威類別映射（最高優化）
docker compose exec app composer dump-autoload --optimize --classmap-authoritative
```

## 使用方式

### 開發環境部署（現在會執行 migrate）

```bash
# 基本部署（包含 migrate）
./deploy.sh

# 或明確指定開發環境
./deploy.sh --env=development

# 只重建容器（包含 migrate）
./deploy.sh --rebuild

# 跳過 Docker 映像重建，只更新代碼（包含 migrate）
./deploy.sh --skip-build
```

### 生產環境部署（原本就有 migrate）

```bash
export GITHUB_TOKEN=your_token
export GITHUB_REPO=https://github.com/username/web-miniverse.git
./deploy.sh --env=production
```

### 透過 update-and-deploy.sh

```bash
# 更新代碼並部署（開發環境，現在會執行 migrate）
./scripts/deployment/update-and-deploy.sh

# 更新代碼並部署到生產環境
./scripts/deployment/update-and-deploy.sh --env=production

# 只更新代碼，不重建映像（但會執行 migrate）
./scripts/deployment/update-and-deploy.sh --skip-build
```

## 影響範圍

### ✅ **受益的場景**

1. **新增或更新套件依賴**
   - 例如：安裝新的 Laravel 套件
   - `composer.json` 變更後會自動安裝
   - 套件更新會自動下載

2. **新增資料庫欄位**
   - 例如：`file_size_mb` 欄位
   - 開發環境部署後會自動建立

3. **修改資料庫結構**
   - 任何新的 migration 都會自動執行

4. **配置變更**
   - 快取清除確保新配置生效

5. **效能優化（生產環境）**
   - 自動快取配置、路由、視圖
   - 生成權威類別映射
   - 提升應用執行效能

### 🔄 **部署流程對比**

#### 修正前

```
開發環境：停止 → 重建 → 啟動 → 檢查 → 測試
           [缺少套件安裝、migrate、快取處理]

生產環境：停止 → 重建 → 啟動 → Migrate ✓ → 清除快取 ✓ → 檢查
           [缺少套件安裝、效能優化]
```

#### 修正後

```
開發環境：停止 → 重建 → 啟動 → Composer Install ✓ → Migrate ✓ 
          → 清除快取 ✓ → 優化載入 ✓ → 檢查 → 測試

生產環境：停止 → 重建 → 啟動 → Composer Install ✓ → Migrate ✓ 
          → 清除快取 ✓ → 快取優化 ✓ → Autoload 優化 ✓ → 檢查
```

## 完整部署步驟一覽

### 方案 1：完整更新並部署（推薦）

```bash
# 1. 從 GitHub 拉取最新代碼
# 2. 執行完整部署流程（包含 migrate）
./scripts/deployment/update-and-deploy.sh
```

### 方案 2：只部署本地代碼

```bash
# 適用於本地開發後要部署的情況
./deploy.sh
```

### 方案 3：快速更新（不重建映像）

```bash
# 只更新代碼和資料庫，不重建 Docker 映像
./scripts/deployment/update-and-deploy.sh --skip-build
```

## 檢查部署結果

### 驗證 Migration 是否執行

```bash
# 進入容器檢查資料庫
docker compose exec app php artisan migrate:status

# 查看 videos 表結構
docker compose exec app php artisan tinker
>>> Schema::hasColumn('videos', 'file_size_mb');
// 應該返回 true
```

### 查看最近執行的 Migration

```bash
docker compose exec app bash
mysql -u root -p web_miniverse
SHOW COLUMNS FROM videos LIKE 'file_size_mb';
```

## 注意事項

### ⚠️ **生產環境**

- `migrate --force` 會直接執行，不會詢問確認
- 建議先在開發環境測試 migration
- 確保有資料庫備份

### 💡 **開發環境**

- 每次部署都會執行 migrate
- 如果沒有新的 migration，不會有任何影響
- 快取清除確保代碼變更立即生效

## 相關檔案

- `deploy.sh`：主要部署腳本
- `scripts/deployment/update-and-deploy.sh`：自動更新並部署腳本
- `database/migrations/2025_12_17_120000_add_file_size_mb_to_videos_table.php`：範例 migration

## 套件管理注意事項

### Composer 套件安裝選項

**開發環境**：
```bash
composer install --optimize-autoloader
```
- 安裝所有套件（包含 dev 依賴）
- 適合開發和測試

**生產環境**：
```bash
composer install --no-dev --optimize-autoloader --no-interaction
```
- `--no-dev`：不安裝開發依賴（如 PHPUnit、Faker）
- `--no-interaction`：無互動模式
- `--optimize-autoloader`：優化類別載入

**權威優化（生產環境）**：
```bash
composer dump-autoload --optimize --classmap-authoritative
```
- `--classmap-authoritative`：只從 classmap 載入類別
- 不會搜尋檔案系統
- 最高效能，但需要確保所有類別都在 classmap 中

### 檢查套件狀態

```bash
# 查看已安裝的套件
docker compose exec app composer show

# 查看過期的套件
docker compose exec app composer outdated

# 檢查套件安全性
docker compose exec app composer audit
```

## 版本歷史

- **2025-12-17**: 修正開發環境部署流程，新增 migrate 和快取清除步驟
- **2025-12-17**: 新增 Composer 套件管理和效能優化步驟

