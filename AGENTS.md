# Project Specification

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

# 2. Technology Stack

請以專案目前實際安裝版本為準，不要自行假設版本。

目前主要技術：

- PHP 8.2+
- Laravel 12
- Filament 5.8
- Livewire 4.4
- MySQL 8
- Redis
- JWT Authentication
- Laravel Queue
- L5-Swagger / OpenAPI

實際 package constraint：

- PHP: `^8.2`
- Laravel: `^12.0`
- Filament: `^5.8`
- Livewire: `^4.4`
- tymon/jwt-auth: `^2.3`
- darkaonline/l5-swagger: `^11.1`
- bezhansalleh/filament-shield: `4.3.1`

實際安裝版本必須以：

```bash
composer show
```

及：

```text
composer.lock
```

為準。

不得僅依本文件中的版本號判定目前實際安裝版本。

API：

```text
/api/v1
```

Swagger / OpenAPI：

```text
/api/documentation
```

---

# 3. Source of Truth

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
Project Specification
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

# 4. Core Architecture

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
Redis
Queue
Events
Cache
Locks
```

只有在實際需求存在時才加入。

不得為了「看起來 Enterprise」而任意增加技術。

---

# 5. Application Layer Responsibility

基本責任：

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

## Controller

Controller 主要負責：

- 接收 Request
- 呼叫 Validation
- 呼叫 Service
- 回傳 Resource / Response

Controller 不應承擔複雜 Business Logic。

## Form Request

負責：

- Request Validation
- Input normalization
- Authorization（適用時）

## Service

負責：

- Business Logic
- Transaction
- Domain consistency
- Cross-model operations
- Point / Coupon 等核心操作

如果現有功能已經由 Service 處理，新增功能應優先延續現有 Service。

不得因為「看起來更乾淨」而任意新增：

- Repository
- Action
- Handler
- UseCase
- DTO

除非現有架構已採用該模式，或實際需求明確要求。

---

# 6. Multi-Tenancy

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

1. Tenant 如何解析
2. Tenant Context 如何建立
3. Model 是否有 Tenant Scope
4. Query 是否可能繞過 Tenant Isolation
5. Service 是否接受任意 `tenant_id`
6. Authorization 是否阻止 IDOR
7. Database Constraint 是否符合 Tenant 邏輯

不得為了方便查詢而移除或弱化 Tenant Isolation。

---

# 7. Tenant Context

Tenant Context 的實際來源必須以目前程式碼為準。

不得自行假設：

```php
auth()->user()->tenant_id
```

一定存在或一定是目前操作 Tenant。

特別是：

```text
Super Admin
```

可以存在：

```text
tenant_id = null
```

因此：

> **Super Admin 不代表目前操作 Tenant。**

當 Super Admin 建立或修改 Tenant-owned 資料時，如果需要指定 Tenant，必須有明確的 Tenant Context 或明確的 Tenant selection。

不得直接寫：

```php
$model->tenant_id = auth()->user()->tenant_id;
```

然後假設所有使用者都一定有 `tenant_id`。

如果目前流程無法判斷應該使用哪個 Tenant：

> 不得猜測，必須先檢查現有 UI、Route、Middleware、Service、Model 與 Tests。

---

# 8. Authentication

API Authentication 與 Filament Web Authentication 是不同 Context。

API：

```text
JWT
```

Filament Admin：

```text
Web Session
```

不得因 API 開發而破壞 Filament Authentication。

新增 API 時必須確認：

- Authentication Middleware
- Guard
- Token lifecycle
- Authorization
- Tenant Context

不要直接假設：

```php
auth()
auth('web')
auth('api')
```

具有相同行為。

---

# 9. API Design Philosophy

API 必須：

> Stable、Predictable、Integration-friendly

API 必須考慮：

- 外部系統 retry
- Network timeout
- Duplicate Request
- Concurrent Request
- API Versioning
- Error Handling
- Rate Limiting
- Tenant Isolation
- Backward Compatibility

API URL：

```text
/api/v1/...
```

未來可增加：

```text
/api/v2/...
```

不得任意破壞既有 v1 Contract。

---

# 10. API Responsibility

目前 API v1 的實際 Domain 必須以：

```text
routes/api.php
```

與實際 Controller 為準。

目前已知 Domain：

- Authentication
- Customers
- Points
- Point Accounts
- Point Transactions

不要因為文件描述而自行建立不存在的 API。

如果使用者要求新增 API：

1. 先確認現有 Route
2. 確認 Controller
3. 確認 Service
4. 確認 Model
5. 確認 Authorization
6. 確認 Tenant Isolation
7. 再決定新增位置

---

# 11. API Error Contract

API Response 應維持一致格式。

成功：

```json
{
    "success": true,
    "message": "...",
    "data": {}
}
```

失敗：

```json
{
    "success": false,
    "message": "...",
    "data": null,
    "errors": {}
}
```

實際 Response 必須以目前：

```text
App\Support\Api\ApiResponse
```

及現有 Controller / Resource 為準。

不得為單一 Controller 自行發明新的 Response Format。

---

# 12. API Documentation

Swagger / OpenAPI：

```text
/api/documentation
```

API 文件必須反映實際存在的 Route。

每個重要 API 應盡可能描述：

- HTTP Method
- Path
- Authentication
- Request Parameters
- Request Body
- Response
- Error Response
- Example

Swagger 文件不得創造不存在的 API。

---

# 13. Swagger Version Path

Laravel Route：

```text
/api/v1/...
```

如果 OpenAPI Server 已經設定：

```text
/api/v1
```

Endpoint path 不得再次加入：

```text
/api/v1
```

避免：

```text
/api/v1/api/v1/...
```

修改 Swagger 前必須先確認：

```bash
php artisan route:list
```

以及實際 OpenAPI Configuration。

---

# 14. Point System

點數是本系統核心 Domain。

目前存在的主要操作：

- Earn
- Redeem
- Refund
- Adjust
- Expire

新增或修改點數邏輯時，必須注意：

- Point Balance correctness
- Transaction atomicity
- Concurrent requests
- Point Transaction ledger
- Point Lot
- Tenant isolation
- Negative balance prevention
- Idempotency

任何影響：

```text
PointAccount.balance
```

的操作，必須遵循目前 PointService 的統一處理方式。

不得在其他 Controller、Model Event 或獨立 Service 中自行修改 Balance，除非確認現有架構本身就是如此設計。

---

# 15. Point Transaction

`PointTransaction` 是點數交易 Ledger。

每一筆影響 Point Balance 的 Business Operation 都應有對應交易紀錄。

目前主要 Transaction Type：

- EARN
- REDEEM
- REFUND
- ADJUST
- EXPIRE

實際 constants 與 Business Rule 必須以：

```text
app/Models/PointTransaction.php
app/Services/Point/PointService.php
```

為準。

不得自行新增不存在的 Transaction Type。

---

# 16. Point Concurrency

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

各層責任不同：

### Redis Lock

避免跨 Process / Instance 的同步競爭。

### Database Transaction

確保資料庫操作 Atomic。

### `lockForUpdate()`

確保同一筆資料的 Database-level serialization。

### Database Constraint

提供最後一道資料一致性防線。

不得任意移除其中任何一層，除非有實際測試證據證明目前方案存在問題。

---

# 17. Point Balance Invariant

核心會計不變量：

```text
SUM(PointLot.remaining_points)
=
PointAccount.balance
```

此規則適用於所有會影響 Point Balance 的操作。

包括：

```text
Earn
Redeem
Refund
Adjust+
Adjust-
Expire
```

如果修改 PointService：

必須確認上述不變量仍成立。

---

# 18. Point Lot / FIFO

本系統使用 Point Lot / FIFO 機制。

FIFO 排序：

```text
earned_at ASC
id ASC
```

主要行為：

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

Point Lot 是否完整符合所有 Edge Cases：

> 必須以 PointService 與相關 Tests 的實際驗證結果為準。

不得僅因存在：

```text
PointLot Model
PointLot Migration
FIFO Query
```

就宣稱 Point Lot 完整驗證。

---

# 19. Idempotency

點數變更 API 已建立 Database-backed Idempotency 機制。

使用：

```text
Idempotency-Key
```

Database Unique Constraint：

```text
UNIQUE(tenant_id, idempotency_key)
```

目前實際套用範圍：

```text
POST /customers/{customer}/point-transactions
POST /customers/{customer}/points/redeem
```

實際保護哪些 Operation：

- Earn
- Redeem
- Refund
- Adjust

必須以現有 Route / Controller / Service 實際行為為準。

不得僅因文件列出某 Operation，就認定該 Operation 已套用 Idempotency。

---

# 20. Idempotency Verification

完整 Idempotency 必須驗證：

- 相同 Tenant + 相同 Key 不重複執行
- 相同 Key + 不同 Payload 會被拒絕
- 不同 Tenant 可以使用相同 Key
- Completed Request 可以 Replay
- Duplicate Request 不會建立第二筆 PointTransaction
- Concurrent Duplicate Request 不會重複扣點或加點
- Replay HTTP Status 正確
- Replay Response Contract 正確

如果必要測試尚未通過：

不得標記為完整：

```text
Implemented
```

應使用：

```text
Implemented — Core (Validation In Progress)
```

或：

```text
Not Yet Fully Verified
```

---

# 21. Event-Driven Architecture

Domain Events、Queue、Webhook 等屬於非同步整合能力。

目前：

```text
Domain Events
Queue-based Business Jobs
Webhook
```

是否完成必須以實際程式碼與 Tests 為準。

Point Balance 核心 Transaction 不應因非核心 Notification、Webhook 或 Analytics 失敗而 rollback，除非 Business Requirement 明確要求。

---

# 22. Outbox Pattern

如果未來需要可靠地將 Domain Event 傳送到外部系統，可評估：

```text
Database Transaction
        ↓
Outbox Record
        ↓
Queue Worker
        ↓
External System
```

目前如果沒有實際 Outbox implementation：

> 不得宣稱 Outbox 已完成。

---

# 23. External Integration

外部系統主要透過：

```text
/api/v1
```

進行整合。

可能的整合系統：

- Website
- Mobile App
- POS
- E-commerce
- CRM
- 第三方會員系統
- 第三方服務

外部整合必須考慮：

### Authentication

如何取得與使用 JWT。

### Tenant Context

如何識別 Tenant。

### Customer

- 建立會員
- 查詢會員
- 查詢會員狀態

### Points

- Earn
- Redeem
- Refund
- Adjust
- 查詢 Balance
- 查詢 Transactions

### Idempotency

避免 retry 造成重複 Business Operation。

### Webhook

目前如果尚未實作，不得描述成現有功能。

---

# 24. Rate Limiting

目前 API 已使用：

```text
throttle:api
```

作為基礎 API Rate Limiting。

目前限制模型不是 Tenant-aware。

因此目前不能宣稱：

```text
Tenant-isolated Rate Limiting
```

未來如果實際流量與業務需求需要，可以評估：

```text
Tenant-aware Rate Limiting
```

但不得因為架構文件而提前引入：

- Gateway
- 第三方 Rate Limit Service
- Redis-based custom limiter

除非有實際需求與證據。

---

# 25. Database Integrity

Database Constraint 是最後一道資料一致性防線。

新增或修改 Schema 時必須檢查：

- Primary Key
- Foreign Key
- Unique Constraint
- Index
- Tenant Index
- Nullable
- Default
- Data Type

尤其是 Tenant-scoped 資料：

> 不得看到 `tenant_id` 就自動將所有 Unique Constraint 改成 `(tenant_id, ...)`。

必須先確認：

1. Business Rule
2. Application Query
3. Existing Migration
4. Tests
5. Data Integrity Requirement

例如：

```text
UNIQUE(reference)
```

是否應改成：

```text
UNIQUE(tenant_id, reference)
```

必須依實際 Business Rule 判斷，不得機械式修改。

---

# 26. Database Index

Index 必須根據實際 Query Pattern 建立。

判斷依據：

- WHERE
- JOIN
- ORDER BY
- GROUP BY
- Query frequency
- Existing index coverage
- EXPLAIN / EXPLAIN ANALYZE

禁止：

> 「未來可能會用到，所以先加 Index。」

除非有明確 Query Pattern 或效能證據。

---

# 27. Testing

任何核心 Business Logic 修改都必須考慮 Tests。

特別是：

### Point

- Earn
- Redeem
- Refund
- Adjust
- Expire

### Concurrency

例如：

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
