# 外電 AI 分析系統

### Prerequisites 
- PHP = ^8.2
- Laravel = 12.x 
- MySQL = 8.0

## Setup
    $ cp .env.example .env
    $ php artisan key:generate
    $ composer install
### Migrations
    $ php artisan migrate
### Seeds
    $ php artisan db:seed
### Testing
    $ php artisan test

### Git Branch
    $ 正式站：main

## 規範
1. `Controllers` 命名規則: `{名稱}{API}{Controller}`

	> 如: UserAPIController

1. `Services` 命名規則: `{名稱}{Service}`

	> 如: UserService

1. `Repositories` 命名規則: `{名稱}{Repository}`

	> 如: UserRepository
	
1. `Repositories` 設計規範: 一個`Model`對應一個`Repository`

1. `Models` 命名規則: `{名稱}`

	> 如: User
	
1. `Models` 設計規範:
	1. 請設定 Relationship 來設定 Model 間的關聯
	2. 常用之 Query 請使用

1. `Request Validation` 命名規則: `{依據功能切分資料夾}{名稱}`

	> 如: UserList

1. 增加資料庫 schema 的部分應使用 `migration`
   1. 一次 `migration` 以一張表為準，不要一個 `migration` 對多張表做操作
1. 填充資料庫預設資料應使用 `seed`
1. 回傳資料時，應使用 Api Resource
1. 多語系: 
	1. [參考](https://laravel.com/docs/9.x/localization)
	1. 來源 `json` : __()
	1. 來源 `php` : trans()
1. 關於 transaction 必要在 Controller，也必須用 `try catch` 包起
1. env() 不該在 config 外的地方呼叫，因為  `php artisan config:cache` 會導致讀不到新的資訊

## 回傳格式
### 正常回傳
```json
{
    "status":"00000",
    "message":"success",
    "data":[]
}
```
#### 除主要資料以外的附屬資料，應放置在 meta
如：
```json
{
    "status":"00000",
    "message":"success",
    "data":[],
    "meta": {
      "is_show": false
    }
}
```

### Error Handler
- 客製化訊息 path: `app/Http/StatusMessages`
    - 在內部設定相關 `http status code` `status code` `message`
- 客製化 Exception path: `app/Exceptions`
    - 依據各自的例外狀況增加
- 使用舉例: 
    - `throw new MemberException(MemberStatusMessage::LOGIN_FAILED);` 
- Log 警報: `> alert` 會發通知到 slack，[層級參考](https://laravel.com/docs/9.x/logging#writing-log-messages)
    - env: LOG_CHANNEL=stack, LOG_LEVEL=alert, LOG_SLACK_WEBHOOK_URL=<url>
- 使用舉例:
    - `\Log::alert('error message');`
- 例外狀況一律回傳 `http status code = 500`
```json
{
    "status":"99999",
    "message":"server error.",
    "data":[]
}
```
#### 產生錯誤代碼列表
##### 於 command 輸出結果
    $ php artisan status_list:generate
    
##### 可使用 `--mode=file` 產出 markdown。 
    $ php artisan status_list:generate --mode=file
