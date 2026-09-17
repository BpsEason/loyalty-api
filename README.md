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

```text
Tenant A
   │
   ├── Customers
   ├── Point Accounts
   └── Point Transactions


Tenant B
   │
   ├── Customers
   ├── Point Accounts
   └── Point Transactions
```

Tenant Isolation 不應只依賴 Controller。

整體概念：

```text
Authentication
       ↓
Tenant Context
       ↓
Authorization
       ↓
Model / Query Scope
       ↓
Database Constraints
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

基本流程：

```text
Request
   ↓
Point Service
   ↓
Concurrency Control
   ↓
Database Transaction
   ↓
Lock Point Account Row
   ↓
Validate Balance
   ↓
Update Balance
   ↓
Create Transaction Ledger
   ↓
Commit
```

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

Client 可以提供：

```http
Idempotency-Key: 8f3a...
```

系統透過 Idempotency Key 判斷同一業務 Request 是否已經被處理。

Concurrency 與 Idempotency 解決不同問題：

```text
Concurrency
→ 防止同時交易造成 Race Condition


Idempotency
→ 防止同一 Request 被重複執行
```

兩者需要同時存在，不能互相取代。

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

目前使用 Laravel 標準結構與業務責任分層：

```text
app/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
│
├── Models/
│
├── Services/
│
├── Support/
│
└── ...

config/
database/
routes/
resources/
storage/
tests/
```

實際目錄與模組以 Repository 為準。

README 不假設 Repository 中不存在的資料夾或功能。

---

# 🚀 Installation

## Requirements

基本環境：

| Requirement | Version           |
| ----------- | ----------------- |
| PHP         | 8.2+              |
| Composer    | 2.x               |
| MySQL       | 8.x               |
| Redis       | Redis Server      |
| Node.js     | 依前端 Build 需求 |
| NPM         | 依 Node.js 版本   |

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

- Point Tests
- Tenant Isolation Tests
- Concurrency Tests
- Idempotency Tests

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

測試重點不只放在 CRUD。

---

## Point Correctness

驗證：

```text
Earn
Redeem
Refund
Adjust
Expire
```

---

## Transaction Safety

驗證：

```text
Update Balance
      +
Create Ledger
```

兩者必須保持一致。

任何一步失敗：

```text
ROLLBACK
```

---

## Concurrency

例如：

```text
Initial Balance = 100

10 concurrent requests
Redeem 20
```

預期：

```text
Success = 5
Failed  = 5
Balance = 0
```

---

## Tenant Isolation

驗證：

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

---

## Idempotency

驗證：

```text
Same Idempotency-Key
        +
Same Business Request
        ↓
Must not execute twice
```

同時驗證不同 Tenant 使用相同 Idempotency-Key 時，不會互相污染。

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

| Phase   | Focus                                                                    | Status          |
| ------- | ------------------------------------------------------------------------ | --------------- |
| Phase 1 | Multi-Tenant / JWT / Customer / Point Account / Ledger / Base API        | **Implemented** |
| Phase 2 | Redis Lock / DB Row Lock / Transaction / Idempotency / Concurrency Tests | **In Progress** |
| Phase 3 | Point Lot / FIFO / Point Expiration / Batch Job                          | **Planned**     |
| Phase 4 | Domain Events / Queue / Event-Driven Processing                          | **Planned**     |
| Phase 5 | Outbox Pattern / Webhook / Retry / Signature Verification                | **Planned**     |
| Phase 6 | Tenant-aware Rate Limiting / Observability / Scalability                 | **Planned**     |

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

# 📌 Current Project Status

目前已建立的核心能力：

```text
Multi-Tenant Architecture
        ↓
JWT API Authentication
        ↓
Versioned REST API
        ↓
Customer
        ↓
Point Account
        ↓
Point Transaction / Ledger
        ↓
Redis Distributed Lock
        ↓
Database Transaction
        ↓
Database Row Lock
        ↓
Tenant Isolation
        ↓
API Documentation
```

目前正在持續驗證與完善：

```text
Concurrency
      ↓
Idempotency
      ↓
Tenant Isolation
      ↓
Transaction Consistency
```

後續規劃：

```text
Point Lot / FIFO
      ↓
Domain Events
      ↓
Queue
      ↓
Outbox
      ↓
Webhook
      ↓
Rate Limiting
      ↓
Observability
```

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
