# 📋 項目重組總結

**日期**: 2025-12-17  
**版本**: v2.0.0

---

## 🎯 重組目標

1. ✅ 整理散落的腳本和文檔
2. ✅ 創建清晰的目錄結構
3. ✅ 刪除重複和過時的文件
4. ✅ 提供完整的文檔索引
5. ✅ 統一命名和組織規範

---

## 📂 新的目錄結構

### 之前（混亂）

```
web-miniverse/
├── *.sh （多個腳本散落在根目錄）
├── *.md （多個文檔散落在根目錄）
├── docker/
│   ├── *.sh （重複的部署腳本）
│   └── *.md （重複的文檔）
└── ...
```

### 之後（清晰）

```
web-miniverse/
├── deploy.sh                    # 主部署腳本
├── README.md                    # 項目主文檔
├── docs/                        # 📚 所有文檔
│   ├── README.md               # 文檔索引
│   ├── CNN_FLOW.md
│   ├── DATABASE_ACCESS.md
│   ├── DEPLOYMENT_CHECKLIST.md
│   └── GRACEFUL_SHUTDOWN.md
├── scripts/                     # 🔧 所有腳本
│   ├── README.md               # 腳本說明
│   ├── deployment/             # 部署相關
│   │   ├── update-and-deploy.sh
│   │   └── fix-permissions.sh
│   ├── maintenance/            # 維護相關
│   │   └── disk-cleanup.sh
│   └── debugging/              # 除錯相關
│       ├── check-gcs-proxy.sh
│       ├── check-scheduler.sh
│       └── check-supervisor.sh
└── docker/                      # 🐳 Docker 配置
    ├── README.md
    ├── nginx.conf
    ├── supervisord.conf
    └── entrypoint.sh
```

---

## 📚 文檔變更

### 新增文檔

| 文檔 | 說明 |
|------|------|
| `README.md` | 全新的主文檔，包含完整的項目說明、快速開始、常用指令等 |
| `docs/README.md` | 文檔索引和閱讀指南 |
| `scripts/README.md` | 腳本使用說明和最佳實踐 |

### 移動和重命名

| 舊位置 | 新位置 | 說明 |
|--------|--------|------|
| `CNN_FLOW_V2.md` | `docs/CNN_FLOW.md` | 移除版本號，使用統一命名 |
| `graceful-shutdown-guide.md` | `docs/GRACEFUL_SHUTDOWN.md` | 統一使用大寫命名 |
| `docker/DATABASE_ACCESS.md` | `docs/DATABASE_ACCESS.md` | 集中所有文檔 |
| `docker/DEPLOYMENT_CHECKLIST.md` | `docs/DEPLOYMENT_CHECKLIST.md` | 集中所有文檔 |

### 刪除文檔

| 文檔 | 原因 |
|------|------|
| `DOMAIN_SETUP.md` | 內容已過時或不再需要 |
| `DISK-SPACE-OPTIMIZATION.md` | 內容已整合到其他文檔 |
| `docker/UPDATE.md` | 內容已整合到主 README |

---

## 🔧 腳本變更

### 移動和重命名

| 舊位置 | 新位置 | 分類 |
|--------|--------|------|
| `update-and-deploy.sh` | `scripts/deployment/update-and-deploy.sh` | 部署 |
| `fix-permissions-permanently.sh` | `scripts/deployment/fix-permissions.sh` | 部署 |
| `emergency-disk-cleanup.sh` | `scripts/maintenance/disk-cleanup.sh` | 維護 |
| `check-gcs-proxy-error.sh` | `scripts/debugging/check-gcs-proxy.sh` | 除錯 |
| `check-scheduler.sh` | `scripts/debugging/check-scheduler.sh` | 除錯 |
| `check-supervisor.sh` | `scripts/debugging/check-supervisor.sh` | 除錯 |

### 刪除腳本（已整合）

| 腳本 | 整合到 |
|------|--------|
| `docker/deploy-ec2.sh` | `deploy.sh` |
| `docker/update.sh` | `deploy.sh` |
| `rebuild-and-test.sh` | `deploy.sh` |
| `cleanup-disk.sh` | `scripts/maintenance/disk-cleanup.sh` |
| `docker/cleanup-docker.sh` | `scripts/maintenance/disk-cleanup.sh` |

---

## 🔄 路徑變更對照表

### 對於開發人員

如果你有腳本或文檔引用舊路徑，請更新：

#### 腳本路徑

```bash
# 舊路徑 → 新路徑
./update-and-deploy.sh              → ./scripts/deployment/update-and-deploy.sh
./fix-permissions-permanently.sh    → ./scripts/deployment/fix-permissions.sh
./emergency-disk-cleanup.sh         → ./scripts/maintenance/disk-cleanup.sh
./check-scheduler.sh                → ./scripts/debugging/check-scheduler.sh
./docker/deploy-ec2.sh              → ./deploy.sh --env=production
```

#### 文檔路徑

```bash
# 舊路徑 → 新路徑
./CNN_FLOW_V2.md                    → ./docs/CNN_FLOW.md
./graceful-shutdown-guide.md        → ./docs/GRACEFUL_SHUTDOWN.md
./docker/DATABASE_ACCESS.md         → ./docs/DATABASE_ACCESS.md
./docker/DEPLOYMENT_CHECKLIST.md    → ./docs/DEPLOYMENT_CHECKLIST.md
```

---

## 📖 如何使用新結構

### 1. 查看文檔

```bash
# 從主 README 開始
cat README.md

# 查看文檔索引
cat docs/README.md

# 查看特定文檔
cat docs/CNN_FLOW.md
cat docs/DATABASE_ACCESS.md
```

### 2. 使用腳本

```bash
# 查看腳本說明
cat scripts/README.md

# 部署相關
./deploy.sh --env=production
./scripts/deployment/update-and-deploy.sh --skip-build
./scripts/deployment/fix-permissions.sh

# 維護相關
./scripts/maintenance/disk-cleanup.sh

# 除錯相關
./scripts/debugging/check-scheduler.sh
./scripts/debugging/check-supervisor.sh
./scripts/debugging/check-gcs-proxy.sh
```

### 3. Docker 配置

```bash
# 查看 Docker 說明
cat docker/README.md

# Docker 配置文件位置不變
docker/nginx.conf
docker/supervisord.conf
docker/entrypoint.sh
```

---

## ✨ 主要改進

### 1. 清晰的組織

- **按功能分類**: 文檔、腳本、Docker 配置各有專門目錄
- **層級結構**: scripts/ 下按用途分為 deployment/、maintenance/、debugging/
- **命名規範**: 統一使用描述性名稱

### 2. 完整的文檔

- **主 README**: 提供項目概覽、快速開始、常用指令
- **文檔索引**: docs/README.md 提供所有文檔的導航
- **腳本說明**: scripts/README.md 詳細說明每個腳本的用途

### 3. 減少冗餘

- 刪除重複的腳本（如 5 個部署相關腳本整合為 1 個）
- 刪除過時的文檔
- 集中相關文檔到統一位置

### 4. 易於維護

- 新增文檔或腳本時，清楚知道放在哪裡
- 查找文件時，按分類快速定位
- 統一的命名和組織規範

---

## 🚀 部署建議

### 在 EC2 上更新

```bash
cd /var/www/html/web-miniverse

# 1. 拉取最新代碼
git fetch origin
git reset --hard origin/main

# 2. 更新腳本權限
chmod +x deploy.sh
chmod +x scripts/**/*.sh

# 3. 使用新的部署腳本
./deploy.sh --env=production --skip-build
```

### 更新現有腳本引用

如果你有 crontab 或其他地方引用了舊路徑的腳本：

```bash
# 查找舊路徑引用
grep -r "update-and-deploy.sh" /etc/cron.d/ /etc/crontab

# 更新為新路徑
# 舊: /path/to/update-and-deploy.sh
# 新: /path/to/scripts/deployment/update-and-deploy.sh
```

---

## 📝 後續維護

### 添加新文檔

```bash
# 技術文檔放在 docs/
touch docs/NEW_FEATURE.md

# 更新 docs/README.md 索引
```

### 添加新腳本

```bash
# 根據用途放在對應目錄
# 部署相關
touch scripts/deployment/new-deploy-script.sh

# 維護相關
touch scripts/maintenance/new-maintenance-script.sh

# 除錯相關
touch scripts/debugging/new-debug-script.sh

# 更新 scripts/README.md 說明
```

### 更新主 README

當有重大變更時，更新 README.md 的：
- 常用指令
- 常見問題
- 版本歷史

---

## ✅ 檢查清單

在 EC2 上更新後，請檢查：

- [ ] 主 README 顯示正常
- [ ] docs/ 目錄下所有文檔存在
- [ ] scripts/ 目錄下所有腳本可執行
- [ ] deploy.sh 可以正常運行
- [ ] 舊的腳本路徑不再存在
- [ ] Docker 配置仍然正常工作

---

## 🆘 遇到問題？

### Q1: 找不到某個腳本？

查看本文檔的「路徑變更對照表」部分。

### Q2: 腳本權限問題？

```bash
chmod +x deploy.sh
chmod +x scripts/**/*.sh
```

### Q3: 需要舊文檔？

可以從 Git 歷史中恢復：

```bash
# 查看刪除的文檔
git log --all --full-history -- "DOMAIN_SETUP.md"

# 恢復特定版本
git checkout <commit-hash> -- DOMAIN_SETUP.md
```

---

## 📞 聯絡方式

如果有任何問題或建議，請聯絡：
- **項目維護**: TVBS 技術團隊
- **問題回報**: GitHub Issues

---

<div align="center">
  <sub>✨ 重組讓項目更清晰、更易維護！</sub>
</div>

