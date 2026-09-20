# Multi-Tenant Loyalty & Point API Platform

一套基於 **Laravel** 開發、以 **API First** 為核心的 **Multi-Tenant Loyalty / Point API Platform**。

本專案不是單純的 CRUD 後台，而是一套以真實商業系統整合為前提所設計的會員與點數核心服務。

系統可以被：

- Website
- Mobile App
- POS
- E-commerce
- CRM
- Third-party Systems

等不同產品與服務整合。

專案目前採用 **Modular Monolith** 架構，優先建立清楚的模組責任、Multi-Tenant Isolation、Point Transaction Consistency 與 API Reliability，再依實際需求逐步演進。

---

# 📌 Table of Contents

- [Project Overview](#-project-overview)
- [Project Goal](#-project-goal)
- [Core Problems](#-core-problems)
- [Architecture](#-architecture)
- [Multi-Tenant Architecture](#-multi-tenant-architecture)
- [Authentication](#-authentication)
- [Point System](#-point-system)
- [Concurrency Control](#-concurrency-control)
- [Point Transaction Ledger](#-point-transaction-ledger)
- [Idempotency](#-idempotency)
- [API Design](#-api-design)
- [API Documentation](#-api-documentation)
- [Admin Panel](#-admin-panel)
- [Technology Stack](#-technology-stack)
- [Project Structure](#-project-structure)
- [Installation](#-installation)
- [Environment Configuration](#-environment-configuration)
- [Database Setup](#-database-setup)
- [Redis Setup](#-redis-setup)
- [Storage Setup](#-storage-setup)
- [API Documentation Setup](#-api-documentation-setup)
- [Running the Application](#-running-the-application)
- [Testing](#-testing)
- [Production Deployment](#-production-deployment)
- [Production Environment](#-production-environment)
- [Queue Worker](#-queue-worker)
- [Web Server](#-web-server)
- [Deployment Verification](#-deployment-verification)
- [Security](#-security)
- [Testing Strategy](#-testing-strategy)
- [Development Philosophy](#-development-philosophy)
- [Reliability Roadmap](#-reliability-roadmap)
- [Future Point Lifecycle](#-future-point-lifecycle)
- [Future Event-Driven Architecture](#-future-event-driven-architecture)
- [Future Outbox Pattern](#-future-outbox-pattern)
- [Future Tenant-aware Rate Limiting](#-future-tenant-aware-rate-limiting)
- [Current Project Status](#-current-project-status)
- [Development Direction](#-development-direction)

---

# 🎯 Project Overview

一個 Loyalty / Point 系統一開始可能只有：

```text
Customer
   ↓
Point Balance
```

但當系統開始被 Website、Mobile App、POS、CRM 或其他第三方系統同時使用後，真正困難的問題就不再是 CRUD。

系統必須處理：

- 不同 Tenant 的資料如何隔離？
- 同一會員同時收到多個點數交易時如何避免 Race Condition？
- Client Timeout 後重新送出 Request 如何避免重複扣點？
- Point Balance 與 Transaction Ledger 如何保持一致？
- 發生異常時如何追查每一筆點數異動？
- 核心交易完成後如何可靠地通知其他系統？
- 多個 Application Instance 同時執行時如何維持一致性？

因此，本專案的重點不是「把 API 做出來」，而是逐步建立一個可以處理真實交易一致性問題的 Loyalty API Core。

---

# 🎯 Project Goal

本專案希望建立一套可以被不同產品重複整合的：

**Multi-Tenant Loyalty API Platform**

整體架構：

```text
                  ┌──────────────┐
                  │   Website   │
                  └──────┬───────┘
                         │
                  ┌──────▼───────┐
                  │ Mobile App   │
                  └──────┬───────┘
                         │
                  ┌──────▼───────┐
                  │     POS      │
                  └──────┬───────┘
                         │
                  ┌──────▼───────┐
                  │     CRM      │
                  └──────┬───────┘
                         │
                         ▼
              ┌─────────────────────┐
              │    Loyalty API      │
              │       /api/v1       │
              └──────────┬──────────┘
                         │
               ┌─────────┼─────────┐
               ▼         ▼         ▼
             MySQL     Redis     Queue
```

API 與前端 UI 分離，使會員、點數與交易核心邏輯不依賴特定產品。

---

# 🧩 Core Problems

本專案目前主要圍繞以下問題：

### 1. Multi-Tenant Isolation

不同 Tenant 的：

- Customer
- Point Account
- Point Transaction

必須彼此隔離。

### 2. Point Consistency

Point Balance 與 Transaction Ledger 必須保持一致。

### 3. Concurrency

多個 Request 同時操作同一會員時，必須避免 Race Condition。

### 4. Idempotency

Client Retry 時，同一個業務操作不能因為重送而被重複執行。

### 5. Traceability

每一次點數異動都必須能夠追查。

### 6. Reliability

未來需要處理 Queue、Event、Webhook 與外部系統整合的一致性問題。

---

# 🏗️ Architecture

目前採用：

**Modular Monolith**

不因為系統具有 Enterprise 特性，就一開始拆成 Microservices。

目前更重要的是建立清楚的 Module 與 Responsibility Boundary。

```text
                         API
                          │
                          ▼
                  ┌─────────────┐
                  │ Middleware  │
                  │             │
                  │ Auth        │
                  │ Tenant      │
                  │ Permission  │
                  └──────┬──────┘
                         │
                         ▼
                  ┌─────────────┐
                  │ Controller  │
                  └──────┬──────┘
                         │
                         ▼
                  ┌─────────────┐
                  │ FormRequest │
                  └──────┬──────┘
                         │
                         ▼
                  ┌─────────────┐
                  │   Service   │
                  └──────┬──────┘
                         │
                         ▼
                  ┌─────────────┐
                  │    Model    │
                  └──────┬──────┘
                         │
                         ▼
                       MySQL
```

## Responsibility

### Middleware

負責：

- Authentication
- Tenant Context
- Permission
- Request-level protection

### Controller

負責：

- 接收 Request
- 呼叫 Service
- 組織 Response

Controller 不應包含核心點數交易規則。

### FormRequest

負責：

- Request Validation
- Input Rules
- Request-level authorization

### Service

負責：

- Business Logic
- Point Transaction
- Transaction Coordination
- Concurrency Control

### Model

負責：

- Data Representation
- Relationships
- Model-level behavior

---

# 🏢 Multi-Tenant Architecture

本專案採用：

**Shared Database / Shared Tables**

透過 `tenant_id` 區分不同租戶。

## 實作細節

- **BelongsToTenant Trait**：所有需要租戶隔離的 Model 都使用此 Trait
- **Global Scope**：自動在所有查詢中加入 `tenant_id` 過濾（Super Admin 除外）
- **TenantResolver**：解析當前請求的租戶上下文
- **TenantContext**：儲存當前請求的租戶實例，全程維護租戶隔離

## 角色權限

### Super Admin

- `tenant_id = null`，不屬於任何租戶
- 自動 bypass 所有租戶限制，可管理全部 Tenant
- 可跨 Tenant 查看所有資料
- 不會被錯誤限制在任何單一租戶

### Tenant Admin

- `tenant_id` 綁定所屬租戶
- 僅能操作自己 Tenant 的所有資源
- 擁有該租戶下所有權限

### Tenant Staff

- `tenant_id` 綁定所屬租戶
- 僅有唯讀權限，可查看但無法修改刪除資料

## 資料模型關係

```text
Tenant
 ├── Users (系統使用者：Admin/Staff)
 ├── Customers (會員客戶)
 │    └── PointAccount (每個客戶一個點數帳戶)
 │         └── PointTransactions (所有點數交易明細)
 ├── Campaigns (行銷活動)
 │    └── CampaignRewards (活動可兌換獎勵)
 │         └── RewardGrants (實際發放的獎勵記錄)
```

所有上層實體都有 `tenant_id`，下層實體透過關聯繼承租戶隔離，配合 Global Scope 確保跨租戶資料無法存取。

## 隔離流程

```text
Authentication
       ↓
Tenant Context Resolve
       ↓
Authorization Check
       ↓
Model Global Scope 自動套用
       ↓
Database Constraints 最終防線
```

核心原則：

- Client 不應直接決定可信任的 Tenant Context
- API Request 必須在正確 Tenant Context 中執行
- Tenant A 不得存取 Tenant B 的資料
- Tenant Context 與 Authentication / Authorization 必須有清楚責任
- Database Constraints 作為資料完整性的最後一道防線

---

# 🔐 Authentication

API 與 Admin Panel 使用不同 Authentication Context。

## API Authentication

```text
API Client
    ↓
JWT
    ↓
/api/v1/*
```

API 使用 JWT Authentication。

---

## Admin Authentication

```text
Admin User
    ↓
Filament
    ↓
Web Session
    ↓
/admin/*
```

API Authentication 與 Admin Session 不混用。

---

# ⚡ Point System

Point System 是本專案最需要保護資料一致性的核心模組。

目前 Point Transaction 類型包含：

- Earn
- Redeem
- Refund
- Adjust
- Expire

## 點數操作實際流程

根據程式碼中 `PointService` 的實作，每筆點數交易都會經過以下流程：

```text
API Request
    ↓
Controller 接收請求
    ↓
PointService 進入核心邏輯
    ↓
Redis Lock (Cache::lock) 取得跨實例鎖
    ↓
lock->block() 自旋等待最多 5 秒
    ↓
DB::transaction() 開啟資料庫交易 (3 次死鎖重試)
    ↓
PointAccount::lockForUpdate() 取得資料庫行鎖
    ↓
驗證租戶一致性、餘額合法性
    ↓
更新 PointAccount 餘額與累計數據
    ↓
建立 PointTransaction 交易明細
    ↓
Commit 交易
    ↓
回傳交易結果給 Client
```

## 並發保護機制

- Redis Distributed Lock：避免多個 Application Instance 同時操作同一客戶
- Database Row Lock (`lockForUpdate()`)：確保同一時間只有一個交易能修改餘額
- SQL 層級餘額檢查：`WHERE balance >= amount` 作為最後一道防線
- Unique Constraint：處理並發建立 PointAccount 的競爭狀況

Controller 不負責點數交易規則。

核心邏輯集中在 Point Service。

```text
PointController
      ↓
PointService
      ↓
PointAccount
      +
PointTransaction
```

這樣未來 API、Queue 或其他入口需要執行點數交易時，可以共用相同的核心 Business Logic。

---

# 🔒 Concurrency Control

點數交易必須處理 Concurrent Requests。

例如：

```text
Initial Balance = 100

10 concurrent requests

Each request:
Redeem 20
```

正確結果：

```text
5 requests → Success
5 requests → Failed

Final Balance = 0
```

而不是：

```text
Request A reads 100
Request B reads 100
Request C reads 100
...

Concurrent Update

↓

Incorrect Balance
```

目前核心設計：

```text
Redis Distributed Lock
        ↓
Database Transaction
        ↓
SELECT ... FOR UPDATE
        ↓
Validate Balance
        ↓
Update Balance
        ↓
Create Ledger
        ↓
Commit
```

## Redis Distributed Lock

Redis Lock 用於跨 Application Instance 的同步。

```text
Customer
   ↓
Redis Distributed Lock
```

其主要目的，是降低同一會員同時執行多個點數交易時的競爭。

---

## Database Transaction

Balance 更新與 Ledger 建立位於同一 Database Transaction：

```text
BEGIN TRANSACTION

Update Point Account
        +
Create Point Transaction

COMMIT
```

如果任何一步失敗：

```text
ROLLBACK
```

避免 Balance 與 Ledger 產生不一致。

---

## Database Row Lock

在 Database Transaction 中鎖定 Point Account Row：

```sql
SELECT ...
FOR UPDATE
```

Database Row Lock 作為最終資料一致性防線。

---

## Concurrency Responsibility

三個機制負責不同問題：

```text
Redis Lock
→ Cross-instance synchronization

Database Transaction
→ Atomicity

Database Row Lock
→ Database-level concurrent update protection
```

因此：

> **Redis 負責跨 Instance 的同步；Database Transaction 與 Row Lock 負責最終資料一致性。**

---

# 📒 Point Transaction Ledger

Point Account 只代表目前狀態：

```text
Point Account
      ↓
Current Balance
```

但只看 Balance 無法回答：

> 「這些點數是怎麼來的？」

因此每一次 Point Mutation 都建立 Transaction Ledger。

例如：

```text
Balance: 100

       ↓ Redeem 30

Point Transaction
────────────────────
Type:       REDEEM
Amount:     -30
Before:      100
After:        70
Customer:     123
Created At:   ...
────────────────────

Balance: 70
```

Ledger 用於：

- Transaction Traceability
- Auditability
- Balance Change History
- Issue Investigation

兩者負責不同角色：

```text
Point Account
      ↓
Current State


Point Transaction
      ↓
Historical Changes
```

---

# 🔁 Idempotency

Idempotency 用於處理 Client Retry。

典型情境：

```text
POST /api/v1/points/redeem

Request
   ↓
Server successfully processes
   ↓
Network Timeout
   ↓
Client retries
```

沒有 Idempotency：

```text
Redeem 100
     +
Redeem 100
```

同一個業務操作可能被執行兩次。

## 目前實作狀態

### 已完成的基礎實作

- ✅ `IdempotencyMiddleware` 中介層已實作
- ✅ 支援 Request Header `Idempotency-Key`
- ✅ 僅套用到 POST 請求
- ✅ 使用 Redis 快取成功回應 24 小時
- ✅ 已套用到需要冪等性的 API 端點：
    - `POST /api/v1/customers/{customer}/point-transactions`
    - `POST /api/v1/customers/{customer}/points/redeem`
- ✅ 支援租戶隔離的冪等性鍵，避免跨租戶鍵碰撞

### 仍需完善的部分

- 🚧 尚未實作資料庫級別的冪等性儲存表
- ⚠️ 僅依賴 Redis 快取，若 Redis 清除可能發生重複執行
- ⚠️ 尚未完整處理所有邊界案例與錯誤重試場景

Concurrency 與 Idempotency 解決不同問題：

```text
Concurrency
→ 防止同時交易造成 Race Condition


Idempotency
→ 防止同一 Request 被重複執行
```

兩者需要同時存在，不能互相取代。

目前：**In Progress**

---

# 🌐 API Design

API 採用 Versioning：

```text
/api/v1
```

主要 API Domain：

```text
Authentication
Customers
Points
Tenants
Users
```

目前 API 主要以外部系統整合為設計前提。

API Client 不應依賴 Admin Panel 的 UI 行為。

---

# 🔗 API Endpoints

目前 API 以 `/api/v1` 作為版本前綴。

主要 API：

```text
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
POST   /api/v1/auth/refresh
GET    /api/v1/auth/me

GET    /api/v1/customers
POST   /api/v1/customers
GET    /api/v1/customers/{customer}
PUT    /api/v1/customers/{customer}
DELETE /api/v1/customers/{customer}

GET    /api/v1/customers/{customer}/points

GET    /api/v1/customers/{customer}/point-transactions

GET    /api/v1/customers/{customer}/point-transactions/{pointTransaction}

POST   /api/v1/customers/{customer}/point-transactions

GET    /api/v1/customers/{customer}/qr-code

POST   /api/v1/customers/identify

POST   /api/v1/customers/{customer}/points/redeem
```

實際 Endpoint、HTTP Method、Request / Response Contract 應以 Repository 目前的 `routes/api.php` 與 API Documentation 為準。

---

# 📖 API Documentation

API 使用：

**L5-Swagger / OpenAPI**

Swagger Documentation：

```text
/api/documentation
```

API 文件應描述：

- Endpoint
- HTTP Method
- Authentication
- Request Parameters
- Request Body
- Response
- Error Response

API Contract 必須與實際 Code 保持一致。

---

# 🖥️ Admin Panel

Admin Panel 使用：

```text
Filament 5.8
+
Livewire 4.4
```

主要用途：

- Tenant Management
- Customer Management
- Point Account Management
- Point Transaction Management
- User Management
- Permission Management

Admin Panel 是管理與操作介面。

Point Business Logic 仍由 Service Layer 負責。

不應因為從 Admin Panel 執行交易，就建立另一套 Point Transaction Logic。

---

# 🛠️ Technology Stack

| Category            | Technology           |
| ------------------- | -------------------- |
| Language            | PHP 8.2+             |
| Framework           | Laravel 12           |
| Admin Panel         | Filament 5.8         |
| UI Runtime          | Livewire 4.4         |
| Database            | MySQL 8              |
| Cache / Lock        | Redis                |
| Authentication      | JWT                  |
| Queue               | Laravel Queue        |
| API Documentation   | L5-Swagger / OpenAPI |
| Dependency Manager  | Composer             |
| Frontend Build Tool | NPM / Node.js        |

---

# 📁 Project Structure

根據實際程式碼的目錄結構：

```text
app/
├── Console/
├── Filament/                          # Filament Admin Panel
│   ├── Concerns/
│   ├── Resources/                     # 所有 Filament Resource
│   │   ├── TenantResource/
│   │   ├── UserResource/
│   │   ├── CustomerResource/
│   │   ├── PointAccountResource/
│   │   ├── PointTransactionResource/
│   │   ├── CampaignResource/
│   │   ├── CampaignRewardResource/
│   │   ├── RewardGrantResource/
│   │   └── Roles/
│   └── Widgets/                       # Dashboard Widgets
│       ├── OverviewStatsWidget.php
│       ├── PointTrendWidget.php
│       ├── CustomerGrowthWidget.php
│       ├── CampaignOverviewWidget.php
│       ├── RewardOverviewWidget.php
│       └── RecentTransactionsWidget.php
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/                    # API v1 控制器
│   │           ├── AuthController.php
│   │           ├── CustomerController.php
│   │           ├── PointAccountController.php
│   │           └── PointTransactionController.php
│   ├── Middleware/                    # 中介層
│   │   ├── IdempotencyMiddleware.php
│   │   ├── TenantMiddleware.php
│   │   └── ...
│   ├── Requests/
│   │   └── Api/
│   │       └── V1/                    # FormRequest 驗證
│   └── Resources/
│       └── Api/
│           └── V1/                    # API Resource 轉換
├── Models/
│   ├── Concerns/
│   │   └── BelongsToTenant.php        # 租戶隔離 Trait
│   ├── Tenant.php
│   ├── User.php
│   ├── Customer.php
│   ├── PointAccount.php
│   ├── PointTransaction.php
│   ├── Campaign.php
│   ├── CampaignReward.php
│   └── RewardGrant.php
├── Policies/                           # 授權政策
├── Providers/
│   └── Filament/
│       └── AdminPanelProvider.php     # Filament Panel 設定
├── Services/
│   ├── Point/
│   │   └── PointService.php           # 點數核心服務
│   └── Reward/
│       └── RewardService.php          # 獎勵服務
└── Support/
    ├── Api/
    │   └── ApiResponse.php            # 統一 API 回應格式
    └── Tenancy/
        ├── TenantContext.php          # 租戶上下文
        └── TenantResolver.php         # 租戶解析器

config/
database/
routes/
resources/
storage/
tests/
```

---

# 🚀 Installation

## Requirements

根據 `composer.json` 與 `package.json` 的實際依賴：

- **PHP**: 8.2+ (Laravel 12 要求)
- **Laravel Framework**: 12.x
- **MySQL**: 8.0+ (支援 InnoDB、行鎖與複雜查詢)
- **Redis**: 7.0+ (用於快取、分佈式鎖、冪等性快取)
- **Node.js**: 20+ / NPM (用於編譯 Filament 前端資源)
- **Composer**: 2.x
- **Filament**: 5.8+
- **Livewire**: 4.4+

## 核心套件依賴

- `tymon/jwt-auth`: API JWT 認證
- `darkaonline/l5-swagger`: Swagger/OpenAPI 文件
- `spatie/laravel-permission`: 權限管理
- `filament/spatie-laravel-permission-plugin`: Filament Shield 權限面板
- `filament/filament`: 後台管理面板框架

實際 PHP Extensions 需求以：

```text
composer.json
composer.lock
```

為準。

---

# 📥 Clone Repository

```bash
git clone <repository-url>
cd <project-directory>
```

---

# 📦 Install PHP Dependencies

```bash
composer install
```

既有專案環境不建議直接執行：

```bash
composer update
```

應優先依照 `composer.lock` 安裝已鎖定版本。

---

# ⚙️ Environment Configuration

建立 `.env`：

```bash
cp .env.example .env
```

建立 Application Key：

```bash
php artisan key:generate
```

設定：

```env
APP_NAME="Loyalty API Platform"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=loyalty
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

其他設定依實際環境需求配置。

敏感資訊不應提交至 Git Repository。

---

# 🗄️ Database Setup

建立 MySQL Database 後執行：

```bash
php artisan migrate
```

Development Environment 可以使用：

```bash
php artisan migrate:fresh --seed
```

注意：

`migrate:fresh` 會刪除目前 Database 中的資料。

因此：

```text
Development
→ 可以使用

Testing
→ 可以使用

Production
→ 不應使用
```

Production 應使用：

```bash
php artisan migrate --force
```

---

# 🌱 Database Seeding

執行：

```bash
php artisan db:seed
```

或：

```bash
php artisan migrate:fresh --seed
```

Seeder 主要用於建立 Development / Testing 所需資料。

Production 是否執行 Seeder，必須依實際 Seeder 內容與部署需求判斷。

---

# 🔴 Redis Setup

Redis 用於：

- Cache
- Distributed Lock
- Future Reliability Components

確認 Redis：

```bash
redis-cli ping
```

正常應回傳：

```text
PONG
```

確認 Laravel Redis：

```bash
php artisan tinker
```

```php
Cache::put('health-check', 'ok', 60);
Cache::get('health-check');
```

如果 Redis 無法連線，使用 Redis Lock 的 Point Transaction 可能無法正常執行。

---

# 🔗 Storage Setup

如果 Application 使用 Laravel Public Storage：

```bash
php artisan storage:link
```

建立：

```text
public/storage
```

指向：

```text
storage/app/public
```

---

# 📦 NPM Setup

安裝 Frontend Dependencies：

```bash
npm install
```

Development Environment：

```bash
npm run dev
```

Production Build：

```bash
npm run build
```

Production Deployment 前應先執行：

```bash
npm run build
```

Build 後的 Frontend Assets 位於：

```text
public/build
```

---

# 📖 API Documentation Setup

產生 Swagger Documentation：

```bash
php artisan l5-swagger:generate
```

完成後：

```text
/api/documentation
```

確認 Swagger UI 可以正常開啟。

---

# ▶️ Running the Application

Laravel Development Server：

```bash
php artisan serve
```

預設：

```text
http://127.0.0.1:8000
```

如果專案需要同時執行前端資產：

```bash
npm install
npm run dev
```

Production Build：

```bash
npm run build
```

---

# 🧪 Testing

完整測試：

```bash
php artisan test
```

或：

```bash
vendor/bin/phpunit
```

執行特定 Test：

```bash
php artisan test --filter=CustomerApiTest
```

修改核心交易邏輯後，至少應重新驗證相關：

- Point Transaction Tests（包含 Earn/Redeem/Refund/Adjust/Expire 邏輯）
- Tenant Isolation Tests（跨租戶存取隔離）
- Locking & Transaction Consistency Tests（Redis鎖、資料庫交易、餘額一致性）
- Sequential Stress Tests（大量順序請求下的資料正確性）
- Idempotency Tests（基礎Redis中間件已實作，資料庫級冪等性仍在開發）

---

# 🏭 Production Deployment

Production Deployment 的基本流程：

```text
Deploy Code
     ↓
Install Dependencies
     ↓
Configure Environment
     ↓
Build Assets
     ↓
Database Migration
     ↓
Storage Link
     ↓
Optimize Laravel
     ↓
Restart Queue Workers
     ↓
Reload Application Runtime
     ↓
Health Check
     ↓
API Verification
```

---

# 📦 Production Dependencies

Production：

```bash
composer install --no-dev --optimize-autoloader
```

不要在 Production 執行：

```bash
composer update
```

Production 應依據 Repository 中的：

```text
composer.lock
```

安裝固定版本。

---

# 🔐 Production Environment

Production：

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
```

Database：

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=loyalty
DB_USERNAME=your-user
DB_PASSWORD=your-password
```

Redis：

```env
CACHE_STORE=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Production 不應將：

```text
.env
Database Password
JWT Secret
Redis Credentials
Application Secrets
```

提交至 Git。

---

# 🔑 Application Key

Production 必須使用自己的 Application Key。

不要直接複製其他環境的：

```env
APP_KEY
```

到 Production。

如果 Production 尚未建立 Key：

```bash
php artisan key:generate
```

如果 Application 已經存在正式資料，不應任意重新產生 `APP_KEY`。

---

# 🗄️ Production Migration

Production：

```bash
php artisan migrate --force
```

不要使用：

```bash
php artisan migrate:fresh
```

因為：

```text
migrate:fresh
→ Drop existing tables
→ Recreate tables
```

會造成正式資料遺失。

---

# 📦 Production Assets

如果 Application 包含前端資產：

```bash
npm ci
npm run build
```

確認：

```text
public/build/
```

存在且內容為最新 Build。

如果使用 CI/CD Pipeline，也可以由 CI/CD 完成 Asset Build。

---

# ⚡ Laravel Optimization

Production：

```bash
php artisan optimize
```

或依部署流程使用：

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

修改 `.env` 後，需要注意 Configuration Cache。

---

# ⚙️ Queue Worker

未來 Queue 相關功能會由 Laravel Queue Worker 執行。

Production 不應依賴 SSH Session 長期執行：

```bash
php artisan queue:work
```

應使用 Process Manager，例如：

```text
Supervisor
     ↓
Laravel Queue Worker
     ↓
Queue
```

部署新版本後：

```bash
php artisan queue:restart
```

讓 Worker 重新載入最新 Application Code。

Process Manager 應負責重新啟動 Worker。

---

# 🌐 Web Server

Production Web Server 必須將 Laravel：

```text
public/
```

設定為 Document Root。

正確：

```text
/var/www/loyalty/public
```

錯誤：

```text
/var/www/loyalty
```

基本流程：

```text
HTTPS
  ↓
Web Server
  ↓
Laravel public/
  ↓
index.php
  ↓
Laravel Application
```

實際 Nginx / Apache 設定依 Production Infrastructure 決定。

---

# 🐘 PHP Runtime

Production PHP Runtime 必須符合專案：

```text
PHP 8.2+
```

如果使用 PHP-FPM：

```text
Nginx
  ↓
PHP-FPM
  ↓
Laravel
```

修改 PHP Configuration 或部署新 PHP Runtime 後，需要重新載入 PHP Runtime。

實際 PHP-FPM Service Name 依 Server Environment 為準。

---

# 🔴 Redis Production

Redis Production 應：

- 不直接暴露於 Public Internet
- 使用適當 Authentication
- 限制 Network Access
- 配合 Application Server 使用

確認：

```bash
redis-cli ping
```

預期：

```text
PONG
```

---

# 🔄 Recommended Production Deployment Flow

完整部署流程：

```text
1. Deploy New Code
        ↓
2. composer install --no-dev
        ↓
3. Configure / Verify .env
        ↓
4. npm ci && npm run build
        ↓
5. php artisan migrate --force
        ↓
6. php artisan storage:link
        ↓
7. php artisan optimize
        ↓
8. php artisan queue:restart
        ↓
9. Reload PHP Runtime / Web Server
        ↓
10. Health Check
        ↓
11. API Verification
        ↓
12. Monitor Logs
```

---

# ❤️ Deployment Verification

部署完成後至少確認：

## Application

```bash
php artisan about
```

## Database

```bash
php artisan migrate:status
```

## Redis

```bash
redis-cli ping
```

## Queue

確認 Queue Worker 正常執行。

## Health Check

Laravel Health Check：

```text
/up
```

## API

確認：

```text
/api/v1/*
```

正常回應。

## Swagger

確認：

```text
/api/documentation
```

正常開啟。

---

# 🔐 Security

Production 必須：

```env
APP_ENV=production
APP_DEBUG=false
```

同時：

- 使用 HTTPS
- 不提交 `.env`
- 不公開 Database Credentials
- 不公開 JWT Secret
- 不公開 Redis Credentials
- 不將 Project Root 設為 Web Root
- 限制 MySQL 對外暴露
- 限制 Redis 對外暴露
- 使用適當的 Queue Worker Process Manager
- 定期更新安全性套件

---

# 🧪 Testing Strategy

測試重點不只放在 CRUD，目前已完成的測試核心能力：

---

## Point Correctness

✅ 已完整驗證所有交易類型：

```text
Earn
Redeem
Refund
Adjust
Expire
```

所有方法的邊界案例、錯誤處理與資料變更都已透過單元測試驗證。

---

## Transaction Safety / Consistency

✅ 已完整驗證交易一致性：

```text
PointAccount Balance
        +
PointTransaction Ledger
```

兩者必須保持一致。任何一步失敗都會正確觸發：

```text
ROLLBACK
```

包含餘額不足、參考交易無效等異常情境下的資料回滾邏輯。

---

## Locking / Sequential Stress Testing

✅ 已驗證鎖定邏輯與順序壓力情境：

- Redis Lock 正確取得與釋放
- 資料庫交易與行鎖邏輯
- 大量順序請求下的點數餘額一致性
- 並發建立 PointAccount 時的 Unique Constraint 處理
- Refund/Adjust/Expire 等交易在鎖定保護下的正確性

目前的測試為**同一進程內的鎖保護順序執行測試**，驗證鎖邏輯能防止更新遺失。

⚠️ 尚未完整涵蓋：真正的 Multi-process / Multi-worker 並發測試

---

## Tenant Isolation

✅ 已完整驗證租戶隔離：

```text
Tenant A
   X
Tenant B Data
```

Tenant A 不應取得 Tenant B 的：

- Customer
- Point Account
- Point Transaction
- Point Operation

包含中間件層級的授權阻擋與全域查詢範圍的自動過濾。

---

## Idempotency

🚧 僅基礎實作，尚未完整驗證：

目前僅實作基礎的 Redis Middleware 快取機制，資料庫級冪等性儲存、完整重試處理邊界案例仍在開發中。

---

# 🧠 Development Philosophy

## 1. Correctness First

點數系統最重要的是資料不能算錯。

優先順序：

```text
Correctness
    ↓
Consistency
    ↓
Security / Isolation
    ↓
Performance
```

---

# 2. API First

API 的使用者不是只有自己的 Frontend。

設計時假設可能存在：

```text
Website
Mobile App
POS
CRM
Third-party Systems
```

因此：

- API Contract
- Authentication
- Tenant Isolation
- Error Handling
- Idempotency

都必須以外部系統整合為前提。

---

# 3. Minimal Change

遇到問題時：

```text
Inspect
   ↓
Trace
   ↓
Verify
   ↓
Modify
   ↓
Test
```

先找到真正原因，再做最小且正確的修改。

不因為單一 Bug 就重寫整個架構。

---

# 4. Do Not Guess

不要因為：

```text
「應該是這樣」

「Laravel 通常會這樣」

「我猜問題在這裡」
```

就直接修改 Code。

正確流程：

```text
Inspect Actual Code
        ↓
Trace Actual Execution
        ↓
Verify Actual Behavior
        ↓
Identify Root Cause
        ↓
Minimal Modification
        ↓
Run Tests
        ↓
Verify Result
```

判斷依據優先使用：

```text
Code
Database Schema
Runtime Behavior
Logs
Tests
Package Implementation
Framework Implementation
```

---

# 5. Do Not Change Tests Just to Make Them Pass

測試失敗時，先確認：

```text
Test
 ↓
Expected Behavior
 ↓
Actual Behavior
 ↓
Root Cause
```

只有確認 Test 本身與正式需求不一致時，才修改 Test。

不應：

- 降低 Assertion
- 移除測試
- 加入無意義的 `assertTrue(true)`
- 修改 Expected Result 只為了讓 Test 通過
- Skip 真正需要驗證的測試

---

# 6. Do Not Add Dependencies Without Verification

新增 Composer Package 前，必須先檢查目前專案是否已經存在可以完成需求的套件。

流程：

```text
composer.json
      ↓
composer.lock
      ↓
vendor/
      ↓
Existing Package API
      ↓
Verify Capability
      ↓
Only If Necessary
      ↓
Add Dependency
```

不要因為遇到某個功能，就直接安裝另一個功能相同的 Package。

---

# 7. Documentation Must Match Reality

README 不應該比程式碼更先進。

狀態定義：

```text
Implemented
→ Code 已存在並經過驗證

In Progress
→ 正在實作或驗證

Planned
→ 設計方向，尚未完成
```

README 的 Project Status 必須跟實際 Code、Test 與 Runtime Behavior 保持一致。

---

# 🚧 Reliability Roadmap

本專案不追求一次加入所有 Enterprise Pattern。

而是依照實際問題逐步演進。

| Phase   | Focus                                                                                             | Status          |
| ------- | ------------------------------------------------------------------------------------------------- | --------------- |
| Phase 1 | Multi-Tenant / JWT / Customer / Point Account / Ledger / Base API                                 | **Implemented** |
| Phase 2 | Redis Lock / DB Row Lock / Transaction / Idempotency / Concurrency Tests                          | **In Progress** |
|         | _已完成：Redis Lock、DB Row Lock、Database Transaction、Lock-protected Sequential Stress Testing_ |                 |
|         | _進行中：完整Idempotency（僅基礎Redis Middleware完成）、真正的Multi-process/Multi-worker並發測試_ |                 |
| Phase 3 | Point Lot / FIFO / Point Expiration / Batch Job                                                   | **Planned**     |
| Phase 4 | Domain Events / Queue / Event-Driven Processing                                                   | **Planned**     |
| Phase 5 | Outbox Pattern / Webhook / Retry / Signature Verification                                         | **Planned**     |
| Phase 6 | Tenant-aware Rate Limiting / Observability / Scalability                                          | **Planned**     |

---

# 🧮 Future Point Lifecycle

目前 Point Model 以 Point Account Balance 為核心。

未來會進一步導入 Point Lot。

例如：

```text
Earn 100
   ↓
Lot A
100 points
Expires: 2027-01-01


Earn 50
   ↓
Lot B
50 points
Expires: 2027-06-01
```

Redeem：

```text
Available Lots
      ↓
FIFO
      ↓
Lot A
      ↓
Lot B
```

未來可能支援：

- Point Lots
- Expiration Date
- FIFO Redemption
- Lot Expiration
- Batch Expiration Job

目前屬於：

**Planned**

---

# 📡 Future Event-Driven Architecture

未來當外部整合增加後，不希望所有工作都塞在同一個 HTTP Request。

避免：

```text
Redeem Point
    ↓
Update DB
    ↓
Send Email
    ↓
Call CRM
    ↓
Call Webhook
    ↓
Analytics
```

未來預計：

```text
Point Transaction
       ↓
Domain Event
       ↓
Queue
       ├── Notification
       ├── CRM Sync
       ├── Analytics
       └── Webhook
```

核心交易完成後，再由非同步 Worker 處理外部工作。

目前：

**Planned**

---

# 📦 Future Outbox Pattern

Event / Queue / Webhook 會遇到：

```text
DB Transaction
      ↓
COMMIT
      ↓
Queue Publish Failed
```

此時：

```text
Database
→ Success

Event
→ Failed
```

因此未來會評估 Outbox Pattern：

```text
┌─────────────────────────┐
│      DB Transaction     │
│                         │
│ Point Transaction       │
│ Outbox Event            │
└────────────┬────────────┘
             │
           COMMIT
             │
             ▼
       Outbox Worker
             │
             ▼
       Queue / Webhook
```

Point Transaction 與 Outbox Event 在同一 Database Transaction 中建立。

目前：

**Planned**

---

# 🚦 Future Tenant-aware Rate Limiting

Multi-Tenant 系統除了資料隔離，也需要控制資源使用。

未來會加入 Tenant-aware Rate Limiting。

概念：

```text
Tenant A

1000 req/min
      ↓
Allowed


Tenant B

Limit Exceeded
      ↓
Rate Limited
```

重點不是單純限制 IP，而是讓不同 Tenant 可以擁有不同 Resource Policy。

目前：

**Planned**

---

# 📊 Future Observability

未來會逐步增加：

- Application Logs
- Point Transaction Trace
- Request Correlation
- Queue Monitoring
- Performance Metrics
- Tenant-level Metrics
- Error Tracking

讓系統發生異常時，可以從：

```text
Request
  ↓
Tenant
  ↓
Customer
  ↓
Point Transaction
  ↓
Ledger
  ↓
Queue / Event
```

進行完整追查。

目前：

**Planned**

---

# 📊 Large-Scale Scalability & Capacity Planning

本專案的 Loyalty / Point API 並非只針對數千或數萬會員設計，而是以未來可能面對：

- 300,000+ Customers
- 10M+ Point Transactions
- 30M+ Point Transactions
- 更高交易量與 API concurrency

作為容量規劃情境。

> 這些數字目前屬於容量規劃與壓力測試目標，不代表目前環境已經完成對應規模的實際 Benchmark。

## Capacity Model

| Scale       |              Customers | Point Transactions | Architecture Focus                             |
| ----------- | ---------------------: | -----------------: | ---------------------------------------------- |
| Current     | 目前 Seeder / 測試規模 |         目前資料量 | Laravel + MySQL + Redis                        |
| Growth      |                   100K |           Millions | Index / Query / Pagination                     |
| Target      |                   300K |            10M–30M | Queue / Aggregation / Read Optimization        |
| Large Scale |                    1M+ |              100M+ | Read Replica / Partition / Archive / Reporting |

## Scalability Principles

### 1. PointTransaction 是 Ledger

PointTransaction 是點數異動的歷史 Ledger。不要因為資料量增加就把歷史交易直接從主流程移除。

設計原則：

- PointAccount 保存目前餘額
- PointTransaction 保存異動紀錄
- PointService 負責交易一致性
- Redis Lock + DB Transaction + Row Lock 保護高併發點數操作

真正的長期瓶頸不是單純 `PointAccount.balance`，而是：

- PointTransaction 持續成長
- Dashboard aggregation
- Customer transaction history
- Reporting / Export
- Historical data query

### 2. Dashboard 不應無限制掃描 Ledger

目前 Dashboard 以 SQL aggregation / query 為主要方式。隨著 PointTransaction 成長，未來需要演進為：

```text
PointTransaction
      ↓
Daily / Monthly Aggregation
      ↓
Dashboard / Reporting
```

> Aggregation table / reporting database 目前尚未實作，僅列為 Future Architecture。

### 3. Read / Write Separation

未來架構：

```text
Laravel API
    │
    ├── Write → MySQL Primary
    │
    └── Read  → Read Replica
```

目前仍使用單一 MySQL instance。Read Replica 是未來當以下狀況發生時再考慮的演進方案：

- API Read Traffic 增加
- Dashboard 查詢增加
- Reporting 查詢增加
- PointTransaction history 查詢增加

## Asynchronous Processing

當會員規模達到 300K+ 後，以下操作不應長時間阻塞 HTTP Request：

- 大量會員匯入
- 大量點數發放
- 大量 Reward Grant
- CSV / Excel Export
- 大型報表
- Webhook delivery
- Notification
- Historical data processing

架構概念：

```text
API Request
    ↓
Create Job
    ↓
Queue
    ↓
Worker
    ↓
Batch Processing
```

目前狀態：

- **Infrastructure → Completed / Available**：Laravel Queue 基礎設施已存在（jobs 表格已建立）
- **Business Async Processing → Planned**：尚未實作上述實際商業邏輯的非同步 Job

## Large Dataset API Design

300K customers 不代表 API 可以一次回傳全部資料。API 已實作以下流程：

```text
Request
  ↓
Tenant Scope
  ↓
Filter
  ↓
Pagination
  ↓
Limited Result Set
```

目前 API 使用 page-based pagination，每頁最多 50 筆資料。未來當 offset pagination 成為實際瓶頸時，可評估導入 cursor-based pagination。

> Cursor-based pagination may be evaluated when offset pagination becomes a measured bottleneck.

## Large Data Import / Export

300K customers 時，避免使用 `Customer::all()` 一次載入全部資料。未來大型資料處理應採：

```text
Chunk / Lazy Processing
        ↓
Queue Job
        ↓
Batch Processing
        ↓
Temporary File / Object Storage
        ↓
Download
```

原則：

- Export 應採 Async Job
- Import 應採 Batch Processing
- 避免一次將 300K records 載入 PHP memory
- 避免長時間 HTTP request
- 避免單一 transaction 包含整批資料

目前狀態：Export / Import 功能尚未實作，列為未來規劃。

## Point Transaction Lifecycle

當交易數量由：

```text
1M
 ↓
10M
 ↓
30M
 ↓
100M+
```

持續增加時，資料管理策略需要逐步演進。

### Current

```text
PointTransaction
    ↓
MySQL
```

### Future

```text
Hot Transactions
        ↓
MySQL Primary
        ↓
Historical Transactions
        ↓
Archive / Partition
```

> Partitioning / Archive strategy is a future scalability option and is not assumed to be implemented unless verified in the current database schema.

## Database Scalability

目前階段的第一優先：

- Composite indexes
- Tenant-aware indexes
- Query optimization
- EXPLAIN / EXPLAIN ANALYZE
- N+1 prevention
- Pagination

當資料量進一步增加，再考慮：

```text
MySQL Primary
      │
      ├── Read Replica
      │
      ├── Reporting / Aggregation
      │
      └── Archive / Partition
```

> Indexes solve query access patterns; they do not by themselves solve unlimited data growth.

## Application Scalability

未來 Laravel Application 可以水平擴展：

```text
                Load Balancer
                     │
          ┌──────────┼──────────┐
          ↓          ↓          ↓
      Laravel 1  Laravel 2  Laravel 3
          │          │          │
          └──────────┼──────────┘
                     ↓
                   Redis
                     │
                   MySQL
```

需確認：

- Session 不依賴單一 application instance
- Cache 使用 shared Redis
- Queue 使用 shared backend
- File storage 不依賴 local instance
- JWT API 本身適合 stateless request

> Future horizontal scaling architecture. 目前尚未部署 Load Balancer / 多 Laravel instances。

## Redis Responsibilities

目前 Redis 已實際使用在：

- Point transaction distributed lock（跨實例同步）
- Idempotency（Redis 快取冪等性回應）
- Cache（一般應用快取）
- Queue（Laravel Queue 後端）

Redis 是 coordination / caching layer，不應成為 Point Ledger 的 source of truth。核心資料仍以 MySQL 為準。

## Scalability & Load Testing

未來容量測試情境：

### Customer Scale

```text
100K Customers
300K Customers
1M Customers
```

### Point Transaction Scale

```text
1M
10M
30M
100M
```

### API Concurrency

```text
100 concurrent requests
500 concurrent requests
1,000 concurrent requests
```

### Point Redemption Concurrency

```text
Same Customer
Same PointAccount
Concurrent Redeem
```

需驗證：

- 不會 negative balance
- 不會 double spend
- Transaction ledger 正確
- Lock contention
- Deadlock / retry
- Response latency

## Performance Metrics

未來壓測至少觀察：

- Throughput / Requests per Second
- P50 latency
- P95 latency
- P99 latency
- MySQL CPU
- MySQL memory
- MySQL connections
- Slow queries
- Lock wait
- Deadlocks
- Redis latency
- Redis memory
- Queue depth
- Queue processing latency
- PHP memory usage
- PHP-FPM workers

> Scalability must be demonstrated through measurable benchmark results rather than architectural claims.

## Reliability at Scale

大型 Loyalty System 不只需要效能，也需要：

- Database failure handling
- Redis failure handling
- Queue retry
- Dead-letter strategy（Future）
- Idempotency
- Transaction retry
- Deadlock retry
- API timeout
- External service retry
- Backup / Restore
- Recovery testing

目前狀態：基礎的交易重試機制已實作（DB 交易死鎖 3 次重試），其餘進階可靠性功能列為未來規劃。

## Scalability Roadmap

### Phase 1 — Current Foundation

```text
Laravel
+
MySQL
+
Redis
+
JWT
+
Multi-Tenant
+
Point Ledger
+
Database Indexing
+
Transaction / Locking
```

### Phase 2 — 300K Customer Readiness

```text
Query Optimization
+
Pagination
+
Async Export
+
Queue Processing
+
Dashboard Aggregation
+
Load Testing
```

### Phase 3 — High Transaction Volume

```text
Read Replica
+
Aggregation
+
Archive
+
Partition Evaluation
```

### Phase 4 — Very Large Scale

```text
Dedicated Reporting
+
Advanced Partitioning
+
Horizontal Application Scaling
+
Dedicated Analytics Infrastructure
```

## Architecture Decision: Avoid Premature Complexity

這個專案目前採取：

> Modular Monolith First, Scale Based on Evidence.

目前不因為「未來可能有 300K / 1M customers」就立即導入：

- Microservices
- Kafka
- Kubernetes
- Sharding
- Elasticsearch
- Separate Reporting Database
- Complex Event Bus

除非：

1. 實際 workload 已經出現瓶頸
2. Benchmark 證明目前架構不足
3. 新架構能解決明確問題
4. Migration / Operational cost 可以接受

核心原則：

```text
Measure
   ↓
Identify Bottleneck
   ↓
Benchmark
   ↓
Optimize
   ↓
Re-measure
   ↓
Scale Architecture
```

而不是：

```text
More Users
   ↓
Add More Infrastructure
```

---

# 📊 Current Project Status

## ✅ Completed

### Multi-Tenant Architecture

- ✅ Tenant Model 實作
- ✅ `BelongsToTenant` Trait + Global Scope 自動租戶隔離
- ✅ TenantResolver + TenantContext 租戶上下文管理
- ✅ Super Admin (tenant_id = null) 可跨租戶存取所有資料
- ✅ Tenant Admin / Tenant Staff 僅能存取所屬租戶資料
- ✅ API 與 Filament Admin Panel 皆支援租戶隔離
- ✅ Super Admin 不會被錯誤限制在單一租戶

### Point System

- ✅ 支援 5 種交易類型：Earn / Redeem / Refund / Adjust / Expire
- ✅ PointAccount 儲存餘額與累計數據 (balance / total_earned / total_redeemed)
- ✅ PointTransaction 完整交易明細帳本，支援多態關聯 reference
- ✅ 交易原子性：所有點數操作皆在 DB Transaction 中執行
- ✅ 租戶隔離：所有 Point 相關 Model 皆套用租戶全域作用域
- ✅ 客戶隔離：操作前驗證客戶與當前租戶一致性

### Concurrency / Consistency

- ✅ Redis Distributed Lock (Cache::lock) 跨實例同步
- ✅ Database Transaction + lockForUpdate() 行鎖
- ✅ SQL 層級餘額檢查 (WHERE balance >= amount) 作為深度防禦
- ✅ Unique Constraint 處理並發建立 PointAccount 的競爭
- ✅ 3 次重試機制處理短暫資料庫死鎖
- ✅ 測試包含：交易一致性、鎖定邏輯、順序壓力情境驗證
- ⚠️ 真正的多進程 / 多 worker 並發測試仍為後續強化項目

### Authentication & Authorization

- ✅ API: JWT Authentication (tymon/jwt-auth)
- ✅ Admin Panel: Filament Session Authentication
- ✅ Spatie Permission + Filament Shield 權限管理
- ✅ 角色系統：`super_admin` / `tenant_admin` / `tenant_staff`
- ✅ Super Admin 自動 bypass 所有租戶限制
- ✅ Policies 資源層級授權控制

### Filament Admin Panel

- ✅ 平台管理：Tenant / User / Roles
- ✅ 會員管理：Customer / PointAccount / PointTransaction
- ✅ 獎勵管理：Campaign / CampaignReward / RewardGrant
- ✅ 全數 Resource 皆支援租戶自動隔離
- ✅ Dashboard Widgets：OverviewStats / PointTrend / CustomerGrowth / CampaignOverview / RewardOverview / RecentTransactions
- ✅ Super Admin Dashboard 顯示跨租戶統計，Tenant User 僅顯示所屬租戶數據

### API

- ✅ 版本化 RESTful API (v1)
- ✅ API 路由包含：Auth / Customers / PointAccounts / PointTransactions / QR Code / POS Scan
- ✅ Swagger/OpenAPI 文件 (darkaonline/l5-swagger)，使用 OpenApi Attributes 定義
- ✅ Swagger UI 路徑：`/api/documentation`
- ✅ FormRequest 輸入驗證 + API Resource 資源轉換
- ✅ 統一 API Response 格式

### Database & Seeders

- ✅ 完整 Migration 定義所有資料表結構
- ✅ Database Index Optimization：已加入效能複合索引優化查詢效能
- ✅ Demo Seeder 建立：
    - Super Admin: `superadmin@example.com` / `password123`
    - Demo Tenants: `coffee.localhost` (Demo Coffee), `fitness.localhost` (Demo Fitness)
    - Tenant Admin: `admin-a@example.com`, `admin-b@example.com` / `password123`
    - Demo Customers / PointAccounts / 歷史交易數據
- ✅ 執行 `php artisan db:seed` 即可建立完整示範環境

### Testing

- ✅ Unit Tests
- ✅ Feature Tests：
    - API 測試 (CustomerApiTest)
    - 認證測試 (AuthApiTest)
    - 租戶隔離測試 (TenantIsolationTest)
    - Super Admin 行為測試 (SuperAdminTenantTest)
    - Tenant Admin 權限測試 (TenantAdminPermissionTest)
    - Point Service Locking / Transaction Consistency Tests (PointTransactionConcurrencyTest)
        - 驗證所有點數交易類型(Earn/Redeem/Refund/Adjust/Expire)的正確性
        - 驗證餘額與交易明細的一致性
        - 驗證Redis鎖、資料庫交易與行鎖邏輯
        - 驗證大量順序請求下的資料正確性(Sequential Stress)
        - 驗證PointAccount並發建立的競爭處理
    - 獎勵引擎測試 (RewardEngineTest)

## 🚧 In Progress

### Idempotency

- 🚧 已實作基礎 IdempotencyMiddleware，支援 `Idempotency-Key` Header
- 🚧 使用 Redis 快取成功回應 24 小時避免重複執行
- ⚠️ 尚未完整實作資料庫級冪等性儲存表、完整的重試處理邊界案例

### Point Expire 自動化

- 🚧 PointTransaction 已支援 TYPE_EXPIRE 類型
- ⚠️ 自動過期排程、FIFO/LIFO 點數批次管理仍在開發

## 📅 Planned (Future Roadmap)

- Point Lot FIFO 先進先出點數生命週期管理
- Event-Driven Architecture 領域事件
- Outbox Pattern 交易性發件箱模式
- Observability 可觀測性：日誌追蹤、效能指標、錯誤追蹤
- Tenant-aware Rate Limiting 租戶級別速率限制
- Webhook 系統：交易完成後主動通知外部系統
- Queue Worker 非同步處理長時間任務
- Async large-data export 非同步大數據匯出
- Daily / Monthly aggregation 每日/每月聚合表
- Read Replica 讀取複本
- Archive / Partition strategy 資料歸檔/分區策略
- Large-scale load testing 大規模負載測試
- Advanced observability 進階可觀測性
- Horizontal application scaling 應用程式水平擴展

---

# 🚀 Development Direction

本專案接下來的重點不是增加更多 CRUD，而是持續深化核心交易能力。

```text
                    Loyalty Platform
                           │
            ┌──────────────┼──────────────┐
            │              │              │
      Multi-Tenant    Point Engine    Reliability
            │              │              │
            │              ├── Ledger    ├── Idempotency
            │              ├── Concurrency
            │              └── Point Lot
            │                             └── Rate Limit
            │
            └── Tenant Isolation

                           ↓

                     Event / Queue

                           ↓

                   External Systems
```

長期方向：

```text
Multi-Tenant Isolation
        ↓
Point Consistency
        ↓
Concurrency Control
        ↓
Idempotency
        ↓
Point Lot / FIFO
        ↓
Domain Events
        ↓
Outbox
        ↓
Rate Limiting
        ↓
Observability
```

---

# 📜 Project Principles

本專案最重要的不是「功能很多」，而是：

```text
Correctness
Consistency
Isolation
Traceability
Reliability
Maintainability
```

核心原則：

> **Code First. Evidence First. Minimal Change.**

所有架構與功能狀態，都應以實際：

```text
Code
Database
Tests
Runtime
Logs
```

為判斷依據。

最終希望建立的不是一個「功能很多的 CRUD 專案」，而是一套可以持續演進、能處理實際交易一致性問題，並且方便 Website、Mobile App、POS、CRM 與其他第三方系統整合的：

# **Loyalty API Platform**
