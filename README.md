# 多租戶會員點數 API

一套以 **Laravel 12** 建構的可擴充多租戶會員點數管理 API。

本專案提供一套可重複使用的後端架構，讓企業能透過統一的 API 管理：

- 多租戶
- 使用者與租戶權限
- 會員
- 點數帳戶
- 點數交易
- 點數取得與兌換
- 點數規則
- 獎勵
- 獎勵兌換
- 租戶資料隔離

專案同時提供 **Filament 5 後台管理介面**以及 **OpenAPI / Swagger API 文件**。

---

## ✨ 功能特色

- 多租戶架構
- 租戶資料隔離
- JWT 身分驗證
- RESTful API
- API 版本控制
- 會員管理
- 點數帳戶管理
- 點數交易帳本
- 點數取得 / 兌換
- 點數規則管理
- 獎勵管理
- 獎勵兌換
- 活動紀錄
- Filament 5 後台管理介面
- Livewire 4 後台 UI
- OpenAPI / Swagger API 文件
- Feature / Unit 測試

---

## 🛠 技術堆疊

| 技術     | 版本               |
| -------- | ------------------ |
| PHP      | 8.2+               |
| Laravel  | 12.x               |
| Filament | 5.8.1              |
| Livewire | 4.4                |
| JWT Auth | tymon/jwt-auth 2.x |
| API 文件 | L5-Swagger 11.x    |
| 資料庫   | MySQL 8            |

---

## 🏗 系統架構

本專案採用：

**共用資料庫 + 共用資料表 + `tenant_id`**

的多租戶架構。

```text
                         Laravel 12
                             │
             ┌───────────────┴───────────────┐
             │                               │
             ▼                               ▼
        Filament 5                       RESTful API
             │                               │
        Livewire 4                           JWT
             │                               │
             └───────────────┬───────────────┘
                             │
                             ▼
                       租戶 Context
                             │
                             ▼
                          授權
                             │
                             ▼
                         Services
                             │
                             ▼
                         Eloquent
                             │
                             ▼
                          MySQL 8
```

API 與 Filament 後台共用相同的應用程式服務與商業規則。

這樣可以避免 API 與後台各自實作一套不同的商業邏輯。

---

## 🏢 多租戶架構

租戶資料隔離是本專案的核心安全機制之一。

系統採用：

```text
共用資料庫
    +
共用資料表
    +
tenant_id
```

需要進行租戶隔離的資源會包含 `tenant_id` 欄位。

例如：

```text
租戶
├── 使用者
├── 會員
│   └── 點數帳戶
│       └── 點數交易
├── 點數規則
├── 獎勵
└── 獎勵兌換
```

Tenant A 的使用者不應該能夠存取 Tenant B 的資料。

租戶資訊會從已驗證的使用者與租戶機制中解析，並維護於中央 `TenantContext`。

```text
身分驗證
    ↓
已驗證使用者
    ↓
TenantResolver
    ↓
TenantContext
    ↓
授權
    ↓
Controller
    ↓
Service
    ↓
Eloquent
    ↓
資料庫
```

不可信任 Client 直接提供的 `tenant_id` 作為資料存取範圍。

---

## 🔐 身分驗證

RESTful API 使用 **JWT** 進行身分驗證。

### 認證 API

```http
POST /api/v1/auth/login
POST /api/v1/auth/logout
POST /api/v1/auth/refresh
GET  /api/v1/auth/me
```

需要驗證的 API Request 必須帶入：

```http
Authorization: Bearer {token}
```

### 後台身分驗證

Filament 後台使用 **Web Session** 進行身分驗證。

```text
API
 ↓
JWT
 ↓
RESTful API


後台
 ↓
Web Session
 ↓
Filament
```

API 的 JWT 驗證與 Filament 後台的 Web Session 是兩個獨立的驗證環境。

---

## 🎯 會員點數系統

本系統以**點數帳本（Point Ledger）**作為點數管理的核心。

會員目前的點數餘額儲存在 `point_accounts`，每一次點數異動則記錄於 `point_transactions`。

```text
會員
 │
 ▼
點數帳戶
 │
 ├── 取得點數
 ├── 兌換點數
 ├── 手動調整
 ├── 退款
 ├── 額外獎勵
 └── 點數到期
         │
         ▼
     點數交易
```

系統不只依賴目前餘額。

每一筆點數交易會記錄例如：

- 租戶
- 會員
- 點數帳戶
- 交易類型
- 點數金額
- 交易前餘額
- 交易後餘額
- 關聯資料
- 說明
- 操作者
- 建立時間

因此可以完整追蹤會員的點數異動歷程。

---

## 💳 點數交易

目前支援的點數交易類型：

```text
earn
redeem
adjustment
refund
bonus
expire
```

點數異動會使用資料庫交易（Database Transaction）處理。

典型的點數操作流程：

```text
1. 鎖定點數帳戶
2. 取得目前餘額
3. 計算新的餘額
4. 更新餘額
5. 建立點數交易紀錄
6. 提交 Transaction
```

透過資料庫交易與 Row-Level Locking，降低並發操作造成點數餘額不一致的風險。

---

## 🎁 獎勵

每個租戶可以設定自己的獎勵。

獎勵可以包含：

- 名稱
- 說明
- 所需點數
- 庫存
- 狀態
- 開始時間
- 結束時間

進行獎勵兌換時，系統會依序確認：

```text
1. 獎勵是否可用
2. 獎勵庫存
3. 會員點數餘額
4. 扣除點數
5. 建立點數交易
6. 扣除獎勵庫存
7. 建立兌換紀錄
```

相關操作應以原子方式完成，避免只完成部分操作。

---

## 🖥 後台管理介面

後台使用：

- Filament 5
- Livewire 4

提供管理系統資料的管理介面，例如：

```text
儀表板
├── 租戶
├── 使用者
├── 會員
├── 點數帳戶
├── 點數交易
├── 點數規則
├── 獎勵
└── 兌換紀錄
```

Filament 負責後台管理與 UI 操作。

商業邏輯則集中於 Application Service，使 API 與 Filament 可以共用相同的商業規則。

---

## 📡 API

所有版本化 API 都使用：

```text
/api/v1
```

主要 API：

```text
/api/v1/auth

/api/v1/customers

/api/v1/customers/{customer}/points

/api/v1/customers/{customer}/point-transactions

/api/v1/point-rules

/api/v1/rewards

/api/v1/rewards/{reward}/redeem

/api/v1/redemptions
```

### API 回應格式

API 採用統一的回應結構。

#### 成功

```json
{
    "success": true,
    "message": "操作成功",
    "data": {}
}
```

#### 失敗

```json
{
    "success": false,
    "message": "點數不足",
    "error_code": "INSUFFICIENT_POINTS",
    "errors": []
}
```

---

## 📚 API 文件

本專案使用 **L5-Swagger** 提供互動式 API 文件。

啟動應用程式後，可前往：

```text
/api/documentation
```

本機開發環境例如：

```text
http://127.0.0.1:8000/api/documentation
```

Swagger 文件包含：

- API Endpoint
- Request Parameters
- Request Body
- JWT 身分驗證
- Response
- Error Response
- Error Code

API Endpoint 使用 `/api/v1` 作為版本前綴。

例如：

```text
/api/v1/auth/login
```

Swagger 文件中的 API Base Path 與 Endpoint Path 必須避免重複加入 `/api/v1`。

---

## 📁 專案結構

專案採用 Domain-oriented 的結構，同時保留 Laravel 熟悉的目錄規範。

```text
app/
├── Actions/
├── Exceptions/
├── Filament/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/
│   ├── Requests/
│   │   └── Api/
│   │       └── V1/
│   └── Resources/
│       └── Api/
│           └── V1/
├── Models/
├── Policies/
├── Services/
│   ├── Tenant/
│   ├── Customer/
│   ├── Point/
│   ├── Reward/
│   └── Redemption/
└── Support/
    └── Tenancy/
        ├── TenantContext.php
        └── TenantResolver.php

database/
├── factories/
├── migrations/
└── seeders/

routes/
├── api.php
└── web.php

tests/
├── Feature/
│   ├── Auth/
│   ├── Tenant/
│   ├── Customer/
│   ├── Point/
│   └── Reward/
└── Unit/
```

---

## 🧩 核心 Model

主要 Domain Model：

```text
Tenant
User
Customer
PointAccount
PointTransaction
PointRule
Reward
RewardRedemption
AuditLog
```

### 主要關聯

```text
Tenant
├── hasMany Users
├── hasMany Customers
├── hasMany Point Rules
├── hasMany Rewards
└── hasMany Redemptions

Customer
├── belongsTo Tenant
├── hasOne PointAccount
└── hasMany PointTransactions

PointAccount
├── belongsTo Customer
└── hasMany PointTransactions

Reward
└── hasMany Redemptions
```

---

## 🧠 開發原則

### 輕量 Controller

Controller 主要負責處理 HTTP Request，實際商業邏輯交由 Service 處理。

```text
Request
   ↓
Controller
   ↓
Service
   ↓
Model
```

商業邏輯不應直接堆積在 Controller 中。

### 共用商業邏輯

API Controller 與 Filament 操作應共用相同的 Application Service。

```text
API
 │
 └── PointService

Filament
 │
 └── PointService
```

避免不同入口各自實作不同的商業規則。

### 明確程式碼

本專案偏好：

- 清楚的命名
- 單一且明確的責任
- 熟悉的 Laravel Convention
- 明確的商業規則
- 最少必要的抽象
- 容易追蹤的依賴關係

程式碼結構也以方便**開發者與 AI Coding Assistant 快速理解**為目標。

---

## 🧪 測試

專案使用 Laravel 測試工具進行自動化測試。

重要測試情境包括：

- JWT 身分驗證
- 租戶隔離
- 會員存取權限
- 點數取得
- 點數兌換
- 點數不足
- 點數交易建立
- 點數餘額一致性
- 獎勵庫存驗證
- 跨租戶存取防護

重要的安全情境：

```text
Tenant A
 │
 └── Customer A

Tenant B
 │
 └── Customer B

Tenant A User
 │
 └── ❌ 不可存取 Customer B
```

---

## 🚀 安裝

### 1. Clone 專案

```bash
git clone <repository-url>
cd <project-directory>
```

### 2. 安裝 PHP 套件

```bash
composer install
```

### 3. 建立環境設定檔

```bash
cp .env.example .env
```

### 4. 產生 Application Key

```bash
php artisan key:generate
```

### 5. 設定資料庫

修改 `.env`：

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=loyalty
DB_USERNAME=root
DB_PASSWORD=
```

### 6. 執行 Migration

```bash
php artisan migrate
```

### 7. 產生 JWT Secret

```bash
php artisan jwt:secret
```

### 8. 產生 Swagger 文件

```bash
php artisan l5-swagger:generate
```

### 9. 啟動開發伺服器

```bash
php artisan serve
```

預設可透過以下網址存取：

```text
http://127.0.0.1:8000
```

Swagger API 文件：

```text
http://127.0.0.1:8000/api/documentation
```

---

## 🔄 開發流程

目前專案主要依照以下功能方向進行開發：

```text
專案基礎
    ↓
身分驗證
    ↓
Tenant Context
    ↓
租戶隔離
    ↓
會員管理
    ↓
點數帳戶
    ↓
點數帳本
    ↓
點數取得 / 兌換
    ↓
點數規則
    ↓
獎勵
    ↓
兌換
    ↓
活動紀錄
    ↓
API 文件
    ↓
自動化測試
```

---

## 🔒 安全原則

本專案將**租戶隔離視為安全邊界**。

重要規則：

1. 不信任 Client 提供的 `tenant_id`。
2. 從已驗證的應用程式 Context 取得目前租戶。
3. 驗證資源是否屬於目前租戶。
4. 使用 Authorization Policy 進行權限控制。
5. 防止跨租戶查詢。
6. 點數異動使用 Database Transaction。
7. 並發點數操作使用 Row-Level Locking。
8. 保留完整的點數交易紀錄。
9. API JWT Authentication 與 Filament Web Session Authentication 分離。

---

## 📌 專案目標

本專案主要展示如何使用 Laravel 建立一套可維護、可擴充的後端系統，包括：

- 多租戶架構
- JWT 身分驗證
- RESTful API
- API 版本控制
- Domain-oriented Service
- Filament 5 後台管理
- Livewire 4 UI
- 點數帳本架構
- 具交易安全性的點數操作
- Swagger API 文件
- 自動化測試

本專案的目標不是一次實作所有可能的會員忠誠度功能，而是建立一個**乾淨、明確且容易擴充的後端基礎架構**，讓後續可以依照實際商業需求持續增加功能。

---

## 📄 License

本專案採用 [MIT License](LICENSE) 授權。
