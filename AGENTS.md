# Loyalty API - AGENTS.md
## AI Coding Agent Specification & Rules

---

## 1. Project Identity

本專案是一個：

**企業級 Multi-Tenant Loyalty / Point API Platform**

核心目標不是單純建立 CRUD 後台，而是提供一套可以被不同外部系統整合的會員與點數服務。

可能的整合系統包括：

- Website
- Mobile App
- POS
- E-commerce
- CRM
- 第三方會員系統
- 第三方服務

所有 API 應以「可被外部系統穩定整合」為重要設計原則。

本文件同時作為：

- 專案架構規範
- Business Domain 規範
- Implementation Status 規範
- AI Coding Agent 工作規範

**重要：本文件不能取代實際程式碼、Database Schema 或 Tests。**

當文件與實際程式碼不一致時，必須以實際驗證結果為準，不得依文件內容猜測實作。

---

## 2. Source of Truth (Highest Priority)

當 AI Coding Agent 需要判斷目前功能、架構或實作狀態時，優先順序如下：

```text
Runtime Behavior
        ↓
Production Code
        ↓
Database Schema / Migration
        ↓
Automated Tests
        ↓
Configuration / Routes
        ↓
Architecture / ADR
        ↓
Project Specification (本文件)
        ↓
README / Documentation
        ↓
AI Assumption
```

其中：

> **AI Assumption 永遠不能作為實作依據。**

如果發現：

```text
Documentation ≠ Code
```

不得自行選擇其中一個當成正確答案。

必須：

1. 找出差異
2. 檢查實際程式碼
3. 檢查相關 Tests
4. 必要時驗證 Runtime
5. 再決定是否需要修改文件或程式碼

---

## 3. No Guessing Rule (Critical)

> **如果不知道，不要猜。**

必須：

```text
Search Code
    ↓
Inspect Configuration
    ↓
Inspect Database
    ↓
Inspect Tests
    ↓
Verify Runtime
```

禁止自行猜測：

- `tenant_id` 來源
- Super Admin Tenant 行為
- Tenant Context
- Authentication Guard
- Model Relation
- API Route
- API Response
- Point Business Rule
- Coupon Business Rule
- Idempotency behavior
- Lock order
- Database Constraint
- Filament tenancy behavior
- Permission behavior

如果現有程式碼仍不足以判斷：

> 明確指出缺少的資訊，不得自行創造規則。

---

## 4. Technology Stack

**實際安裝版本 (verified from composer.json):**

- PHP: `^8.2` (constraint)
- Laravel: `^12.0`
- Filament: `^5.8`
- Livewire: `^4.4`
- tymon/jwt-auth: `^2.3` (JWT Authentication)
- darkaonline/l5-swagger: `^11.1` (OpenAPI/Swagger)
- bezhansalleh/filament-shield: `4.3.1` (Filament permissions)
- MySQL 8
- Redis
- Laravel Queue

API Base Path:
```text
/api/v1
```

Swagger / OpenAPI:
```text
/api/documentation
```

實際安裝版本必須以 `composer.lock` 及 `composer show` 為準。

---

## 5. Core Architecture

系統核心架構：

```text
External Systems
       │
       ▼
   REST API
    /api/v1
       │
       ▼
 Authentication
       │
       ▼
 Tenant Context
       │
       ▼
 Controllers
       │
       ▼
 Form Requests
       │
       ▼
 Services / Domain Logic
       │
       ▼
 Eloquent Models
       │
       ▼
 MySQL
```

基礎設施：
```text
Redis (Lock/Cache)
Queue (Async processing)
Events (Domain events)
Outbox (Reliable event delivery)
```

不得為了「看起來 Enterprise」而任意增加技術：Kafka、RabbitMQ、GraphQL、Kubernetes、Microservices、API Gateway、Event Sourcing、CQRS、Elasticsearch、OAuth Server 等。如果 Laravel + Redis + MySQL + Queue 已能解決問題，優先保持簡單。

---

## 6. Application Layer Responsibility

標準請求流程：
```text
Route
  ↓
Middleware
  ↓
Authentication / Authorization
  ↓
Tenant Context
  ↓
Form Request
  ↓
Controller
  ↓
Service / Domain Logic
  ↓
Model
  ↓
Database
```

### Controller
- 接收 Request
- 呼叫 Validation
- 呼叫 Service
- 回傳 Resource / Response
- 不應承擔複雜 Business Logic

### Form Request
- Request Validation
- Input normalization
- Authorization（適用時）

### Service
- Business Logic
- Transaction
- Domain consistency
- Cross-model operations
- Point / Coupon 等核心操作

如果現有功能已經由 Service 處理，新增功能應優先延續現有 Service。不得因為「看起來更乾淨」而任意新增 Repository、Action、Handler、UseCase、DTO 等。

---

## 7. Multi-Tenancy Rules (Critical)

本專案採：
```text
Shared Database
+
Shared Tables
+
tenant_id
+
Application-level Tenant Isolation
```

核心原則：
> Tenant A 絕對不能讀取、修改或操作 Tenant B 的資料。

任何新增或修改功能時，都必須確認：
1. Tenant 如何解析 (TenantResolver)
2. Tenant Context 如何建立 (TenantContext)
3. Model 是否有 Tenant Scope (BelongsToTenant)
4. Query 是否可能繞過 Tenant Isolation
5. Middleware 是否正確設定
6. Authorization 是否阻止 IDOR
7. Database Constraint 是否符合 Tenant 邏輯
8. Super Admin 行為是否正確

> **Super Admin 不代表目前操作 Tenant。**

不得自行假設：
```php
auth()->user()->tenant_id
```
就是目前操作 Tenant。Tenant Context 永遠必須透過 `app/Support/Tenancy/TenantContext.php` 取得。

---

## 8. Authentication Rules

API Authentication 與 Filament Web Authentication 是不同 Context：

- **API Authentication**: JWT (`tymon/jwt-auth`)
- **Filament Admin Authentication**: Web Session

不得因 API 開發而破壞 Filament Authentication。新增 API 時必須確認：
- Authentication Middleware
- Guard (`auth:api`)
- Token lifecycle
- Authorization
- Tenant Context

---

## 9. API Rules

API 必須：
> Stable、Predictable、Integration-friendly、Backward Compatible

API URL: `/api/v1/...`
不得任意破壞既有 v1 Contract。

### Current API v1 Domain (verified from routes/api.php)
- Authentication
- Customers
- Points / Point Accounts
- Point Transactions
- Coupons
- Rewards

### API Response Contract
實際 Response 必須以目前 `App\Support\Api\ApiResponse` 及現有 Controller / Resource 為準。不得為單一 Controller 自行發明新的 Response Format。

### Swagger / OpenAPI
如果 OpenAPI Server 已經設定 `/api/v1`，Endpoint path 不得再次加入 `/api/v1`，避免 `/api/v1/api/v1/...`。修改 Swagger 前必須先執行 `php artisan route:list` 確認。

### Rate Limiting
目前 API 已使用 `throttle:api`，但**目前不是 Tenant-aware**。Tenant-aware Rate Limiting = **Planned**。

---

## 10. Point Domain Rules (Core)

點數是本系統核心 Domain。目前存在的主要操作：
- Earn
- Redeem
- Refund
- Adjust
- Expire

任何影響 `PointAccount.balance` 的操作，必須遵循目前 `app/Services/Point/PointService.php` 的統一處理方式。不得在其他 Controller、Model Event 或獨立 Service 中自行修改 Balance。

### Point Concurrency Architecture
目前點數核心交易採：
```text
Redis Distributed Lock
        ↓
Database Transaction
        ↓
PointAccount lockForUpdate()
        ↓
PointLot lockForUpdate()
        ↓
Update Balance
        ↓
Create PointTransaction
        ↓
Commit
```

各層責任：
- **Redis Lock**: 跨 Process / Instance 同步
- **DB Transaction**: Atomicity
- **lockForUpdate()**: Database-level serialization
- **Database Constraint**: 最終完整性保護

不得任意移除其中任何一層。

### Point Balance Invariant (Must Maintain)
```text
SUM(PointLot.remaining_points)
=
PointAccount.balance
```
此規則適用於所有會影響 Point Balance 的操作：Earn、Redeem、Refund、Adjust+、Adjust-、Expire。

### Point Lot / FIFO
FIFO 排序：`earned_at ASC, id ASC`

```text
Earn
  ↓
Create Point Lot

Redeem
  ↓
Consume Point Lot FIFO

Refund
  ↓
Create Point Lot

Adjust+
  ↓
Create Point Lot

Adjust-
  ↓
Consume Point Lot FIFO

Expire
  ↓
Consume / Expire Point Lot
```

---

## 11. Idempotency Rules

已建立 Database-backed Idempotency 機制：
- Header: `Idempotency-Key`
- Database Table: `idempotency_keys`
- Unique Constraint: `UNIQUE(tenant_id, idempotency_key)`
- Middleware: `DatabaseIdempotencyMiddleware`

### Currently Applied Routes (verified from routes/api.php)
```
POST /customers/{customer}/point-transactions
POST /customers/{customer}/points/redeem
POST /customers/{customer}/coupons/claim
POST /customers/{customer}/coupons/{userCoupon}/redeem
POST /customers/{customer}/mixed-payment
POST /customers/{customer}/rewards/grant
```

所有 POST 寫入操作都應優先套用 idempotent middleware。

完整 Idempotency 必須驗證：
- 相同 Tenant + 相同 Key 不重複執行
- 相同 Key + 不同 Payload 會被拒絕 (409)
- 不同 Tenant 可以使用相同 Key
- Completed Request 可以 Replay
- Concurrent Duplicate Request 不會重複修改數據

---

## 12. Queue / Events / Outbox

### Domain Events (Implemented)
已實現：
- `PointEarned`
- `PointRedeemed`
- `PointsUpdated`
- `CouponClaimed`
- `CouponRedeemed`

### Queue Infrastructure (Implemented)
- Laravel Queue
- Redis Queue Configuration

### Outbox Pattern (Implemented)
**Outbox 已實作！**
- Database Table: `outbox_events`
- Service: `app/Services/Outbox/OutboxService.php`
- Job: `ProcessOutboxEvent`
- Command: `outbox:process-pending`

實際流程：
```text
DB Transaction
        ↓
Outbox Record (原子性記錄)
        ↓
Queue Worker 處理
        ↓
External System
```

### Webhook / External Adapters (Planned)
- Webhook Delivery: Planned
- CRM Connector: Planned
- POS Connector: Planned

---

## 13. Database Rules

Database Constraint 是最後一道資料一致性防線。新增或修改 Schema 時必須檢查：
- Primary Key
- Foreign Key
- Unique Constraint
- Index
- Tenant Index
- Nullable
- Default
- Data Type

> 不得看到 `tenant_id` 就自動將所有 Unique Constraint 改成 `(tenant_id, ...)`。必須依實際 Business Rule 判斷。

Index 必須根據實際 Query Pattern 建立。禁止「未來可能會用到，所以先加 Index」。

---

## 14. Testing Rules

```text
Test exists
≠
Feature fully verified

Test passes
≠
All edge cases verified
```

### Required Test Categories
#### Point System
- Earn
- Redeem
- Refund
- Adjust
- Expire

#### Multi-Tenant
- Tenant A 不能操作 Tenant B 的數據

#### Idempotency
- 同一 Idempotency-Key 不得重複執行 Business Operation

#### Concurrency Example
```text
Initial balance = 100
10 concurrent redeem requests
Each redeem = 20
```
預期：
```text
Maximum successful redemptions = 5
Remaining balance = 0
No negative balance
No duplicated ledger effects
```

如果只有 Unit/Feature Test，不得宣稱「Real Multi-process HTTP Concurrency Verified」。

---

## 15. Implementation Status Definitions

### Implemented
必要 production code 已存在，且相關 Configuration、Schema、Routes、Tests、Runtime verification 已具備足夠證據。

### Implemented — Core (Validation In Progress)
核心程式碼已存在，但 Edge Cases、完整測試、Concurrency、Runtime Verification 仍未全部完成。

### Not Yet Fully Verified
核心機制存在，但尚未有足夠證據證明完整正確。

### Planned
只有架構規劃，尚未實作。

> Class 存在 ≠ Feature 完成。Migration 存在 ≠ Feature 完成。Test File 存在 ≠ Feature 完成。

---

## 16. Current Implementation Status (Verified from Repository)

### Implemented
- **Multi-Tenancy**: Tenant Model, TenantResolver, TenantContext, BelongsToTenant, Global Tenant Scope, Super Admin bypass
- **Authentication**: JWT API Auth, Filament Web Session, API Guard, Tenant Middleware
- **Point System Core**: PointService, PointAccount, PointTransaction, Redis Lock, DB Transaction, lockForUpdate(), Deadlock Retry
- **Queue Infrastructure**: Laravel Queue, Redis Queue Configuration
- **API v1**: `/api/v1` 所有核心路由已實作
- **Swagger / OpenAPI**: L5-Swagger, OpenAPI Attributes, `/api/documentation`
- **Basic API Rate Limiting**: `throttle:api`
- **Outbox Pattern**: OutboxService, ProcessOutboxEvent, outbox:process-pending command
- **Domain Events**: 核心領域事件已實作
- **Idempotency Core**: DatabaseIdempotencyMiddleware, idempotency_keys table, UNIQUE constraint

### Implemented — Core (Validation In Progress)
- **Point Lot / FIFO**: 核心操作已存在，但完整會計不變量、所有 Edge Cases 仍需驗證
- **Database Idempotency**: 核心機制已建立，但完整的併發場景測試仍在進行中

### Not Yet Fully Verified
- **Real Multi-process / Multi-worker HTTP Concurrency**: Redis Lock + lockForUpdate() 已實作，但真實多進程 HTTP 併發場景未經完整驗證

### Planned
- **Tenant-aware Rate Limiting**: 目前僅有全域 throttle:api
- **Webhook Delivery System**: 尚未實作
- **External System Adapters**: Salesforce, POS Connector, CRM Connector
- **Advanced Analytics**: 尚未實作

---

## 17. Development Workflow

任何修改前必須遵循：
```text
1. Inspect (搜尋程式碼)
2. Trace (追蹤資料流)
3. Identify Root Cause (找出根本原因)
4. Verify Existing Pattern (確認現有模式)
5. Minimal Fix (最小修改)
6. Test (執行測試)
7. Runtime Verify (驗證執行狀態)
8. Report (回報結果)
```

不要在 Root Cause 尚未確認前直接修改。

---

## 18. Existing Pattern First

新增程式碼前先搜尋 Repository，優先延續現有 Pattern：
- Service: 繼續使用現有 Service 模式
- ApiResponse: 使用 `App\Support\Api\ApiResponse`
- TenantContext: 使用現有多租戶機制
- BelongsToTenant: 所有租戶資料都使用此 Trait
- Policy: 授權使用現有 Policy 模式
- Form Request: 驗證使用 Form Request

不得自行新增不存在的模式，除非使用者明確要求且現有架構無法解決。

---

## 19. Minimal Change Principle

優先：**最小且正確的修改。**

不要因為發現問題就：
- 重寫 Service/Controller
- 更換 Authentication/Tenant Architecture
- 新增 Repository/DTO/Action 等新模式
- 引入 Microservices 等複雜架構

除非使用者明確要求，且有實際程式碼/Test/Runtime Evidence 支持。

每次修改都必須：
```text
Small
Focused
Traceable
Testable
```

不得順便進行無關的重構、命名清理、格式重寫、依版本升級等。

---

## 20. AI Modification Constraints

除非使用者明確要求或 Repository Evidence 證明必要，不得自行：
- 改變 API Contract
- 改變 Authentication
- 改變 Tenant Architecture
- 改變 Database Architecture
- 新增 Business Rule
- 新增 API Endpoint
- 新增 Model Relation
- 新增 Permission Rule
- 新增 Service Pattern
- 新增第三方套件
- 新增 Infrastructure
- 修改既有 Tests 來掩蓋 Production Code 問題

---

## 21. Test Modification Rule

當 Production Code 與 Test 不一致時：
1. 判斷 Production Code 是否正確？
2. 判斷 Business Requirement 是什麼？
3. 判斷 Test 是否正確反映 Requirement？

只有確認 Test 錯誤時才修改 Test。不得直接修改 Test 讓它變綠。

---

## 22. Documentation Accuracy

所有文件：README、Swagger、AGENTS.md、SDD、ADR、Code Comments 都必須以實際 Repository Evidence 為基礎。

如果文件與程式碼不一致，必須先檢查驗證，再更新文件。不得猜測哪一個正確。

---

## 23. Performance Claims

禁止自行宣稱：
```text
Production Ready
High Performance
Enterprise Scale
Handles X requests/sec
Supports X million records
```

除非存在實際的 Benchmark、Load Test、EXPLAIN ANALYZE、Concurrency Test、Runtime Metrics。沒有測量 = Not Measured。

---

## 24. Project Direction (Long-term, Not Current Implementation)

本專案長期方向是建立一套可以被不同產品、平台與第三方服務重複整合的 Multi-Tenant Loyalty / Point API Platform。

```text
Website
      │
Mobile App
      │
POS
      │
E-commerce
      │
CRM
      │
      ▼
┌──────────────────────┐
│   Loyalty API         │
│      /api/v1          │
├──────────────────────┤
│ Authentication        │
│ Tenant Context        │
│ Customers             │
│ Points                │
│ Transactions          │
│ Integration           │
└──────────┬───────────┘
           │
     ┌─────┼─────┐
     ▼     ▼     ▼
   MySQL Redis  Queue
```

所有「長期方向」均不得被 AI 視為 Current Implementation，除非 Repository Evidence 已證明已完成。

---

## 25. Final Rules (Most Important)

```text
Actual Code
    ↓
Actual Tests
    ↓
Actual Runtime Behavior
    ↓
Documentation
```

> **先確認，再修改。**
> **先找現有 Pattern，再新增程式碼。**
> **能小改就不要重構。**
> **能使用現有架構就不要增加新架構。**
> **不知道就搜尋，不要猜。**
> **沒有證據，就不要宣稱已完成。**

```text
Initial balance = 100

10 concurrent redeem requests

Each redeem = 20
```

預期：

```text
Maximum successful redemptions = 5
Remaining balance = 0
No negative balance
No duplicated ledger effects
```

### Multi-Tenant

確認：

```text
Tenant A
```

不能操作：

```text
Tenant B
```

的 Customer / Point。

### Idempotency

相同：

```text
Idempotency-Key
```

不能重複執行 Business Operation。

---

# 28. Test Status

Tests 必須區分：

```text
Implemented
```

與：

```text
Verified
```

存在 Test File 不代表功能已完整驗證。

例如：

```text
Test exists
+
Test passes
```

只能證明該 Test Scenario 通過。

不能因此宣稱：

```text
所有 Concurrency Scenario 已驗證
```

如果尚未進行真正：

```text
multi-process
multi-worker
real HTTP concurrency
```

則不得宣稱已完成真實高並發驗證。

---

# 29. Development Workflow

任何修改前：

```text
1. Inspect
2. Trace
3. Verify
4. Identify Root Cause
5. Modify
6. Test
7. Runtime Verify
8. Report
```

必須優先檢查：

- Route
- Middleware
- Controller
- Form Request
- Resource
- Service
- Model
- Migration
- Config
- Tests

必要時檢查：

- Filament Resource
- Policy
- Permission
- Queue
- Events
- Cache
- Redis Lock
- Database Constraint

---

# 30. No Guessing Rule

當不知道時：

> **不要猜。**

必須：

```text
Search Code
    ↓
Inspect Configuration
    ↓
Inspect Database
    ↓
Inspect Tests
    ↓
Verify Runtime
```

禁止自行猜測：

- `tenant_id` 來源
- Super Admin Tenant 行為
- Tenant Context
- Authentication Guard
- Model Relation
- API Route
- API Response
- Point Business Rule
- Coupon Business Rule
- Idempotency behavior
- Lock order
- Database Constraint
- Filament tenancy behavior
- Permission behavior

如果現有程式碼仍不足以判斷：

> 明確指出缺少的資訊，不得自行創造規則。

---

# 31. Minimal Change Principle

優先：

> **最小且正確的修改。**

不要因為發現問題就：

- 重寫 Service
- 重寫 Controller
- 更換 Authentication
- 更換 Multi-Tenancy
- 更換 Database Architecture
- 新增 Repository
- 新增 DTO
- 新增 Action
- 引入 Microservices

除非：

1. 使用者明確要求
2. 現有架構確實無法解決問題
3. 有實際程式碼 / Test / Runtime Evidence 支持

---

# 32. Existing Pattern First

新增程式碼時：

> **優先延續現有 Pattern，而不是建立新的 Pattern。**

例如目前已有：

```text
Service
```

就優先使用：

```text
Service
```

而不是新增：

```text
Action
UseCase
Handler
Repository
```

目前已有：

```text
ApiResponse
```

就優先使用既有：

```text
ApiResponse
```

不要建立新的 Response Helper。

目前已有：

```text
BelongsToTenant
TenantContext
TenantResolver
```

就優先使用現有 Multi-Tenant 機制。

---

# 33. Do Not Add Technology for Appearance

禁止為了讓專案看起來 Enterprise 而任意加入：

- Kafka
- RabbitMQ
- GraphQL
- Kubernetes
- Microservices
- API Gateway
- Event Sourcing
- CQRS
- Elasticsearch
- OAuth Server

技術選擇必須回答：

> 為什麼需要？

以及：

> 解決了什麼實際問題？

如果：

```text
Laravel + Redis + MySQL + Queue
```

已經可以正確解決問題：

> 優先保持簡單。

---

# 34. Documentation Accuracy

所有文件：

- README
- Swagger
- AGENTS.md
- Project Specification
- Code Comments
- ADR

都必須反映實際狀態。

文件中的：

```text
Implemented
```

必須有 Repository Evidence。

不得把：

- Idempotency
- Point Lot / FIFO
- Event-Driven
- Outbox
- Webhook
- Tenant-aware Rate Limiting

在尚未實作時寫成已完成。

---

# 35. Implementation Status

Implementation Status 必須依實際 Repository Evidence 判定。

狀態：

### Implemented

必要 production code 已存在，且相關：

- Configuration
- Schema
- Routes
- Tests
- Runtime verification

已具備足夠證據。

### Implemented — Core (Validation In Progress)

核心程式碼已存在，但：

- Edge Cases
- 完整測試
- Concurrency
- Runtime Verification

仍未全部完成。

### Not Yet Fully Verified

核心機制存在，但尚未有足夠證據證明完整正確。

### Planned

只有架構規劃，尚未實作。

---

# 36. Evidence Completeness Rule

不能因為存在：

```text
Class
Migration
Method
```

就宣稱功能完成。

完整功能必須依實際需求確認：

```text
Route
    ↓
Middleware
    ↓
Authentication / Authorization
    ↓
Tenant Context
    ↓
Request Validation
    ↓
Controller
    ↓
Service / Domain Logic
    ↓
Model / Database
    ↓
Tests
    ↓
Runtime Verification
```

對資料一致性功能，還需要確認：

- Application Logic
- Database Constraints
- Transaction Boundary
- Concurrency Behavior
- Failure / Retry Behavior
- Tenant Isolation
- Relevant Tests

---

# 37. Implementation Status Rules

以下情況不得直接標記：

```text
Implemented
```

### Point Lot / FIFO

不能只因存在：

```text
PointLot
Migration
FIFO Query
```

就宣稱完整。

必須確認：

```text
Earn
Redeem
Refund
Adjust+
Adjust-
Expire
```

並確認：

```text
SUM(PointLot.remaining_points)
=
PointAccount.balance
```

在所有相關操作後仍成立。

### Idempotency

不能只因存在：

```text
Idempotency-Key
Middleware
idempotency_keys
UNIQUE(tenant_id, idempotency_key)
```

就宣稱完整。

必須確認：

- Duplicate Request
- Different Payload
- Different Tenant
- Replay
- Concurrent Duplicate Request
- Duplicate PointTransaction
- HTTP Status
- Response Contract

---

# 38. Current Implementation Status

以下內容為目前已知 Repository 狀態。

## Implemented

### Multi-Tenancy

已存在：

- Tenant Model
- User `tenant_id`
- TenantResolver
- TenantContext
- BelongsToTenant
- Global Tenant Scope
- Super Admin bypass
- Tenant Admin isolation

相關程式：

```text
app/Models/Tenant.php
app/Support/Tenancy/TenantResolver.php
app/Support/Tenancy/TenantContext.php
app/Models/Concerns/BelongsToTenant.php
```

相關 Tests：

```text
tests/Feature/TenantIsolationTest
tests/Feature/SuperAdminTenantTest
tests/Feature/TenantAdminPermissionTest
```

實際狀態仍以 Tests / Runtime 為準。

---

### Authentication

已存在：

- JWT API Authentication
- Filament Web Session
- API Guard
- Web Guard
- Tenant Middleware
- Login
- Logout
- Refresh
- Me

相關：

```text
config/jwt.php
app/Http/Middleware/TenantMiddleware.php
tests/Feature/AuthApiTest.php
```

---

### Point System Core

已存在：

```text
PointService
PointAccount
PointTransaction
Redis Lock
DB Transaction
lockForUpdate()
Deadlock Retry
```

核心規則：

> 影響 PointAccount.balance 的操作必須透過 PointService 統一處理。

並維持：

```text
SUM(PointLot.remaining_points)
=
PointAccount.balance
```

---

### Queue Infrastructure

已存在：

```text
Laravel Queue
Redis Queue Configuration
```

Business-specific asynchronous jobs：

```text
Planned
```

---

### API v1

已存在：

```text
/api/v1
```

目前實際 API Domain：

- Authentication
- Customers
- Points
- Point Accounts
- Point Transactions

相關程式：

```text
routes/api.php
app/Http/Controllers/Api/V1/
```

---

### Swagger / OpenAPI

已存在：

```text
L5-Swagger
OpenAPI Attributes
/api/documentation
```

API prefix：

```text
/api/v1
```

---

### Basic API Rate Limiting

已存在：

```text
throttle:api
```

目前不是 Tenant-aware Rate Limiting。

---

# 39. Implemented — Core (Validation In Progress)

## Point Lot / FIFO

核心操作已存在：

```text
Earn
Redeem
Refund
Adjust+
Adjust-
Expire
```

FIFO：

```text
earned_at ASC
id ASC
```

相關：

```text
app/Services/Point/PointService.php
app/Models/PointLot.php
```

Migration：

```text
database/migrations/2026_09_20_000002_create_point_lots_table.php
```

已知相關 Tests：

```text
PointLotFifoTest.php
PointServiceConcurrencyTest.php
PointTransactionConcurrencyTest.php
```

目前：

> Point Lot / FIFO 核心流程已建立，但完整會計不變量、所有 Edge Cases 與真實 Runtime 情境仍需以 Tests 實際結果驗證。

---

## Database Idempotency

已建立：

```text
idempotency_keys
```

Unique：

```text
UNIQUE(tenant_id, idempotency_key)
```

已存在：

```text
app/Models/IdempotencyKey.php
app/Http/Middleware/DatabaseIdempotencyMiddleware.php
```

狀態：

```text
processing
completed
failed
```

已存在 Response Replay。

目前核心機制已建立，但：

> 基本 Replay / Duplicate Execution Tests 若尚未全部通過，不得標記為完整 Implemented。

---

# 40. Not Yet Fully Verified

## Real Multi-process / Multi-worker HTTP Concurrency

目前已驗證的範圍以 Repository 實際 Tests 為準。

目前不能僅根據：

```text
Redis Lock
lockForUpdate()
Transaction
```

宣稱：

```text
Real Multi-process HTTP concurrency
```

已完成。

目標情境：

```text
Initial balance = 100

10 concurrent redeem attempts

Each redeem = 20
```

預期：

```text
Maximum successful redemptions = 5
Remaining balance = 0
No negative balance
No duplicated ledger effects
```

以下若尚未實際執行：

```text
multi-process concurrency
multi-worker concurrency
real HTTP concurrent requests
k6 / wrk load test
```

則必須維持：

```text
Not Yet Fully Verified
```

---

# 41. Planned

## Domain Events & Async Processing

目前：

```text
Domain Events
Outbox
Webhook Delivery
Business-specific Jobs
```

若沒有實際 implementation：

```text
Planned
```

---

## External System Adapters

目前 REST API 可提供外部系統整合。

但：

```text
Salesforce
POS Connector
CRM Connector
Other External Adapters
```

若沒有實際 implementation：

```text
Planned
```

---

## Tenant-aware Rate Limiting

目前：

```text
throttle:api
```

已存在。

Tenant-aware Rate Limiting：

```text
Planned
```

---

# 42. Performance Claims

以下內容不得直接宣稱：

```text
Production Ready
High Performance
Enterprise Scale
Handles X requests/sec
Supports X million records
```

除非存在實際：

- Benchmark
- Load Test
- EXPLAIN ANALYZE
- Concurrency Test
- Runtime Metrics

如果只是設計目標：

應明確標示：

```text
Planning Target
Load Test Target
Not Measured
```

---

# 43. Database Design Rules

Database Migration 修改前必須先確認：

```text
Model
Relationships
Query
Service
Tests
Existing Data
```

不得因單一 SQL Audit 建議就直接修改 Schema。

尤其是：

- Unique Constraint
- Index
- Nullable
- Foreign Key

必須確認 Laravel 實際使用方式後再修改。

---

# 44. AI Coding Agent Modification Protocol

每次接到 Coding Task：

## Step 1 — Inspect

搜尋實際程式碼。

## Step 2 — Trace

追蹤：

```text
Route
→ Middleware
→ Controller
→ Request
→ Service
→ Model
→ Database
```

必要時：

```text
Authentication
→ Authorization
→ Tenant Context
```

## Step 3 — Identify Root Cause

先找真正原因。

不要只修第一個看到的 Exception。

## Step 4 — Minimal Fix

只修改完成任務需要的部分。

## Step 5 — Test

執行相關：

```bash
php artisan test
```

或指定 Test。

## Step 6 — Runtime Verify

如果涉及：

- Route
- Swagger
- Migration
- Config
- Queue
- Cache
- Authentication
- Tenant Isolation

必須進行適當驗證。

## Step 7 — Report

最後回報：

```text
Problem
Root Cause
Changed
Tests
Result
Remaining Risks
```

---

# 45. AI Must Not Guess

以下規則為最高優先級：

> **如果不知道，不要猜。**

如果資訊不足：

```text
Inspect
→ Verify
→ Ask / Report Missing Evidence
```

不得：

```text
Guess
→ Rewrite
→ Hope it works
```

---

# 46. AI Modification Constraints

AI Coding Agent 不得自行：

- 改變 API Contract
- 改變 Authentication
- 改變 Tenant Architecture
- 改變 Database Architecture
- 新增 Business Rule
- 新增 API Endpoint
- 新增 Model Relation
- 新增 Permission Rule
- 新增 Service Pattern
- 新增第三方套件
- 新增 Infrastructure
- 修改既有 Tests 來掩蓋 Production Code 問題

除非使用者明確要求或現有實作確實證明必要。

---

# 47. Test Modification Rule

當 Production Code 與 Test 不一致時：

> 不得直接修改 Test 讓它變綠。

必須先判斷：

```text
Production Code 是否正確？
Test 是否反映目前 Business Requirement？
```

如果 Test 錯誤：

才修改 Test。

如果 Production Code 錯誤：

修正 Production Code。

如果 Requirement 不清楚：

不得猜測。

---

# 48. Change Scope

每次修改都必須盡量：

```text
Small
Focused
Traceable
Testable
```

不得順便進行：

- 無關 Refactor
- Naming Cleanup
- Formatting Rewrite
- Architecture Rewrite
- Dependency Upgrade
- Migration Cleanup

除非任務明確包含。

---

# 49. Change Report Format

每次 Coding Task 完成後，回報：

```text
## Problem

實際問題。

## Root Cause

真正原因。

## Changed

修改的檔案與內容。

## Tests

執行的 Tests。

## Result

測試結果。

## Remaining Risks

尚未驗證的部分。
```

如果沒有 Remaining Risk：

```text
None
```

---

# 50. Documentation Hierarchy

不同文件負責不同內容：

```text
README
↓
What the system is

Architecture / ADR
↓
Why the system is designed this way

Project Specification
↓
Rules for implementation and AI modification

Code
↓
How it actually works

Tests
↓
What has been verified

Benchmark
↓
What performance has actually been measured
```

任何文件不得凌駕於實際 Runtime Behavior 與 Production Code。

---

# 51. Project Direction

本專案長期方向：

```text
Website
      │
Mobile App
      │
POS
      │
E-commerce
      │
CRM
      │
      ▼
┌──────────────────────┐
│   Loyalty API         │
│      /api/v1          │
├──────────────────────┤
│ Authentication        │
│ Tenant Context        │
│ Customers             │
│ Points                │
│ Transactions          │
│ Integration           │
└──────────┬───────────┘
           │
     ┌─────┼─────┐
     ▼     ▼     ▼
   MySQL Redis  Queue
```

最終目標不是：

> 完成一個後台 CRUD。

而是：

> **建立一套可以被不同產品、平台與第三方服務重複整合的 Multi-Tenant Loyalty / Point API Platform。**

但所有「長期方向」均不得被 AI 視為：

```text
Current Implementation
```

除非 Repository Evidence 已證明已完成。

---

# 52. Final Rule

本專案最重要的規則：

```text
Actual Code
    ↓
Actual Tests
    ↓
Actual Runtime Behavior
    ↓
Documentation
```

以及：

> **先確認，再修改。**

> **先找現有 Pattern，再新增程式碼。**

> **能小改就不要重構。**

> **能使用現有架構就不要增加新架構。**

> **不知道就搜尋，不要猜。**

> **沒有證據，就不要宣稱已完成。**