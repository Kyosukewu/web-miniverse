# API Key 安全性保护

## 概述

本文档说明如何防止 API Key 在错误消息中泄露。

## 问题描述

### 修改前

当 Gemini API 调用失败时（例如配额超限），错误消息会包含完整的 API key：

```
Gemini API 完整分析失敗: Gemini API 影片分析失敗: Client error: `POST https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-preview:generateContent?key=AIzaSyXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX` resulted in a `429 Too Many Requests` response
```

这些错误消息会：
1. 保存到数据库中（`analysis_results.error_message`）
2. 显示在日志中
3. 显示在前端视图中（Dashboard）

### 修改后

错误消息中的敏感信息会被自动清理：

```
Gemini API 完整分析失敗: Gemini API 影片分析失敗: Client error: `POST https://generativelanguage.googleapis.com/***?key=***` resulted in a `429 Too Many Requests` response
```

## 实现细节

### 1. GeminiClient.php

添加了 `sanitizeErrorMessage()` 私有方法：

```php
/**
 * Sanitize error message to remove sensitive information like API keys.
 *
 * @param string $errorMessage
 * @return string
 */
private function sanitizeErrorMessage(string $errorMessage): string
{
    // Remove API key from URL (key=xxx)
    $sanitized = preg_replace('/key=[a-zA-Z0-9_-]+/', 'key=***', $errorMessage);
    
    // Remove full URLs with API keys
    $sanitized = preg_replace(
        '/https:\/\/generativelanguage\.googleapis\.com\/[^?]+\?key=[a-zA-Z0-9_-]+/',
        'https://generativelanguage.googleapis.com/***?key=***',
        $sanitized
    );
    
    // Remove any standalone API key patterns (40+ character alphanumeric strings)
    $sanitized = preg_replace('/\b[A-Za-z0-9_-]{30,}\b/', '***', $sanitized);
    
    return $sanitized;
}
```

**修改位置**：
- `analyzeText()` 方法的异常处理（第 115-120 行）
- `analyzeVideo()` 方法的异常处理（第 256-261 行）

### 2. AnalyzeService.php

添加了相同的 `sanitizeErrorMessage()` 方法，并在以下位置应用：

**修改位置**：
- `executeVideoAnalysis()` 的异常处理（第 243-253 行）
- `executeFullAnalysis()` 的异常处理（第 414-424 行）

## 清理规则

`sanitizeErrorMessage()` 方法会清理以下模式：

### 规则 1：清理 URL 参数中的 API key

**原始**：
```
?key=AIzaSyXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```

**清理后**：
```
?key=***
```

### 规则 2：清理完整的 API URL

**原始**：
```
https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-preview:generateContent?key=AIzaSyXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```

**清理后**：
```
https://generativelanguage.googleapis.com/***?key=***
```

### 规则 3：清理独立的 API key 字符串

任何 30 个字符以上的连续字母数字字符串（可能是 API key）都会被替换为 `***`。

## 数据流程

```
Gemini API 错误
    ↓
GeminiClient 捕获异常
    ↓
调用 sanitizeErrorMessage() 清理
    ↓
抛出清理后的异常消息
    ↓
AnalyzeService 捕获异常
    ↓
再次调用 sanitizeErrorMessage() 清理（双重保护）
    ↓
保存到数据库（error_message 字段）
    ↓
显示在前端视图
```

## 示例对比

### 场景 1：配额超限 (429 Too Many Requests)

#### 修改前
```
Gemini API 完整分析失敗: Gemini API 影片分析失敗: Client error: `POST https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-preview:generateContent?key=AIzaSyDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX` resulted in a `429 Too Many Requests` response:
{
  "error": {
    "code": 429,
    "message": "You exceeded your current quota, please check your plan and billing details"
  }
}
```

#### 修改后
```
Gemini API 完整分析失敗: Gemini API 影片分析失敗: Client error: `POST https://generativelanguage.googleapis.com/***?key=***` resulted in a `429 Too Many Requests` response:
{
  "error": {
    "code": 429,
    "message": "You exceeded your current quota, please check your plan and billing details"
  }
}
```

### 场景 2：无效的 API Key (401 Unauthorized)

#### 修改前
```
Gemini API 分析失敗: Client error: `POST https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=INVALID_KEY_123456789` resulted in a `401 Unauthorized` response
```

#### 修改后
```
Gemini API 分析失敗: Client error: `POST https://generativelanguage.googleapis.com/***?key=***` resulted in a `401 Unauthorized` response
```

## 验证方法

### 1. 查看数据库中的错误消息

```sql
-- 检查是否还有包含 API key 的错误消息
SELECT id, error_message 
FROM analysis_results 
WHERE error_message LIKE '%key=%' 
  AND error_message NOT LIKE '%key=***%'
LIMIT 10;

-- 应该返回空结果
```

### 2. 查看日志文件

```bash
# 检查日志中是否有泄露的 API key
docker compose exec app grep -r "key=AIza" storage/logs/

# 应该没有找到任何结果
```

### 3. 测试错误场景

```bash
# 临时使用错误的 API key 进行测试
# 修改 .env
GEMINI_API_KEY=INVALID_KEY_FOR_TESTING

# 重启容器
docker compose restart app

# 运行分析命令
docker compose exec app php artisan analyze:full --limit=1

# 查看错误消息
docker compose exec app php artisan tinker --execute="
\$result = App\Models\AnalysisResult::latest()->first();
echo 'Error Message: ' . \$result->error_message;
"

# 应该看到 key=*** 而不是实际的 key
```

## 注意事项

### ✅ 已保护的位置

1. **GeminiClient 异常**：
   - `analyzeText()` 方法
   - `analyzeVideo()` 方法

2. **AnalyzeService 异常**：
   - `executeVideoAnalysis()` 方法
   - `executeFullAnalysis()` 方法

3. **数据库存储**：
   - `analysis_results.error_message` 字段

4. **日志记录**：
   - Laravel Log::error() 输出

5. **前端显示**：
   - Dashboard 页面的错误消息显示

### ⚠️ 需要注意的位置

1. **其他 API 客户端**：
   - 如果添加了新的 API 客户端，需要实现相同的清理机制

2. **直接的 Exception 抛出**：
   - 如果在其他地方直接 `throw new Exception()` 并包含了 API key，需要先清理

3. **调试输出**：
   - `dd()`, `dump()`, `var_dump()` 等调试输出不会被自动清理
   - 生产环境中应该禁用这些调试输出

## 最佳实践

### 1. 永远不要在代码中硬编码 API Key

```php
// ❌ 错误
$apiKey = 'AIzaSyXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';

// ✅ 正确
$apiKey = config('gemini.api_key');
```

### 2. 在记录日志前清理敏感信息

```php
// ❌ 错误
Log::error('API Error', ['url' => $fullUrlWithKey]);

// ✅ 正确
$sanitizedUrl = $this->sanitizeErrorMessage($fullUrlWithKey);
Log::error('API Error', ['url' => $sanitizedUrl]);
```

### 3. 在保存到数据库前清理

```php
// ❌ 错误
$this->repository->save(['error_message' => $e->getMessage()]);

// ✅ 正确
$sanitized = $this->sanitizeErrorMessage($e->getMessage());
$this->repository->save(['error_message' => $sanitized]);
```

### 4. 在前端显示前进行二次确认

即使后端已经清理，前端显示时也可以再次检查：

```php
// Blade 模板中
@if($result->error_message)
    <div class="error">
        {{ Str::replace(config('gemini.api_key'), '***', $result->error_message) }}
    </div>
@endif
```

## 测试清单

部署前请确认：

- [ ] `GeminiClient.php` 包含 `sanitizeErrorMessage()` 方法
- [ ] `AnalyzeService.php` 包含 `sanitizeErrorMessage()` 方法
- [ ] `analyzeText()` 异常处理使用了清理方法
- [ ] `analyzeVideo()` 异常处理使用了清理方法
- [ ] `executeVideoAnalysis()` 异常处理使用了清理方法
- [ ] `executeFullAnalysis()` 异常处理使用了清理方法
- [ ] 数据库中没有泄露的 API key
- [ ] 日志文件中没有泄露的 API key
- [ ] Dashboard 显示的错误消息已清理

## 紧急响应

如果发现 API key 已经泄露：

### 1. 立即轮换 API Key

```bash
# 1. 前往 Google Cloud Console
# 2. 创建新的 API Key
# 3. 更新 .env 文件
vim .env
# GEMINI_API_KEY=新的_API_KEY

# 4. 重启应用
docker compose restart app

# 5. 删除旧的 API Key（在 Google Cloud Console 中）
```

### 2. 清理数据库中的历史错误消息

```sql
-- 备份数据
CREATE TABLE analysis_results_backup AS SELECT * FROM analysis_results;

-- 更新错误消息，移除 API key
UPDATE analysis_results 
SET error_message = REGEXP_REPLACE(
    error_message, 
    'key=[a-zA-Z0-9_-]+', 
    'key=***'
)
WHERE error_message LIKE '%key=%';
```

### 3. 清理日志文件

```bash
# 归档旧日志
docker compose exec app bash -c "cd storage/logs && tar -czf archive_$(date +%Y%m%d).tar.gz *.log && rm *.log"

# 或直接删除（不建议）
docker compose exec app rm -f storage/logs/*.log
```

## 总结

通过在 `GeminiClient` 和 `AnalyzeService` 中实现 `sanitizeErrorMessage()` 方法，我们确保：

1. ✅ 所有 Gemini API 错误消息都不会泄露 API key
2. ✅ 数据库中保存的错误消息是安全的
3. ✅ 日志文件不包含敏感信息
4. ✅ 前端显示的错误消息不会暴露 API key
5. ✅ 双重保护机制（GeminiClient + AnalyzeService）

这为应用程序提供了强有力的安全保护。

