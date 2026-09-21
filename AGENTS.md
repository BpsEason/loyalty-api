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

API：

```text
/api/v1
```

Swagger / OpenAPI：

```text
/api/documentation
```

---

# 3. Core Architecture

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

Redis、Queue、Events 等基礎設施應在真正需要時加入，不得為了「看起來企業級」而任意增加技術。

---

# 4. Multi-Tenancy

本專案採 Multi-Tenant 架構。

所有 Tenant-scoped 資料都必須正確隔離。

核心原則：

> Tenant A 絕對不能讀取、修改或操作 Tenant B 的資料。

任何新增或修改 API 時，都必須確認：

1. Tenant 是如何解析的。
2. Tenant Context 是如何建立的。
3. Model 是否有 Tenant Scope。
4. Query 是否可能繞過 Tenant Isolation。
5. Service 是否可能接受任意 tenant_id。
6. 外部 API 是否可能透過 IDOR 存取其他 Tenant 資料。

不得為了方便 API 查詢而移除或弱化 Tenant Isolation。

---

# 5. Authentication

API Authentication 與 Filament Web Authentication 是不同的 Context。

API 使用：

```text
JWT
```

Filament Admin 使用：

```text
Web Session
```

不得因為 API 開發而破壞 Filament Web Authentication。

新增 API 時必須明確確認：

- Authentication Middleware
- Guard
- Token lifecycle
- Authorization
- Tenant Context

不要直接假設 `auth()`、`auth('web')`、JWT Guard 的行為相同。

---

# 6. API Design Philosophy

API 必須設計成：

> Stable、Predictable、Integration-friendly

而不是只方便目前的前端。

API 必須考慮：

- 外部系統重試
- 網路逾時
- 重複 Request
- Concurrent Request
- API Versioning
- Error Handling
- Rate Limiting
- Tenant Isolation
- 向後相容性

API URL 使用：

```text
/api/v1/...
```

未來可以增加：

```text
/api/v2/...
```

而不應破壞既有 v1 API。

---

# 7. API Responsibility

Controller 不應承擔複雜 Business Logic。

推薦：

```text
Controller
    ↓
Request Validation
    ↓
Service / Domain Logic
    ↓
Model / Database
```

Controller 主要負責：

- 接收 Request
- 呼叫 Service
- 回傳 Resource / Response

複雜商業邏輯應放在 Service 或適當的 Domain 層。

---

# 8. Point System

點數是本系統的重要 Domain。

目前點數操作包括實際存在於專案中的功能，例如：

- Earn
- Redeem
- Refund
- Adjust
- Expire

新增或修改點數邏輯時，必須特別注意：

- Balance correctness
- Transaction atomicity
- Concurrent requests
- Point transaction ledger
- Tenant isolation
- Negative balance prevention

不得直接修改 Balance 而沒有留下對應的 Point Transaction。

---

# 9. High Concurrency

點數操作屬於高一致性資料。

目前架構使用：

```text
Redis Distributed Lock
        ↓
Database Transaction
        ↓
SELECT ... FOR UPDATE
        ↓
Update Balance
        ↓
Create Point Transaction
        ↓
Commit
```

修改這部分時，不得隨意移除：

- Distributed Lock
- Database Transaction
- Row Lock
- Database Constraints

除非有實際測試與證據證明目前方案存在問題。

---

# 10. Idempotency

外部系統可能因：

- timeout
- network retry
- client retry
- load balancer retry

而重複送出相同 Request。

點數寫入 API **已實作**冪等性支援：

```text
Idempotency-Key
```

支援的 API：

- 僅套用在需要冪等性保護的點數變更路由
- 保護的操作：POST /customers/{customer}/point-transactions, POST /customers/{customer}/points/redeem (對應 Earn、Redeem、Refund、Adjust 等關鍵點數操作)

實現狀態：

- 詳細實現狀態請參考 Implementation Status 章節

---

# 11. Point Expiration / Point Lot

本系統核心已實作 Point Lot / FIFO 機制，詳細驗證狀態請參考 Implementation Status 章節：

```text
Point Lot / Bucket
        ↓
Expiration Date
        ↓
FIFO Consumption
```

FIFO = First In, First Out，實際排序規則：

```text
earned_at ASC
id ASC
```

目的：

讓不同批次取得的點數可以獨立追蹤到期時間，實現精準的點數過期管理。

目前所有點數操作都與 Point Lot 系統整合：

- Earn: 建立新的點數批次
- Redeem: 按 FIFO 順序消耗點數
- Refund: 建立新的點數批次
- Adjust: 正數建立批次，負數按 FIFO 消耗
- Expire: 按 FIFO 順序標記點數過期

---

# 12. Event-Driven Architecture

點數異動未來可透過：

```text
Domain Event
      ↓
Queue
      ↓
Async Processing
```

處理：

- Notification
- Webhook
- Audit
- Analytics
- External Integration

同步交易與非同步工作必須清楚分離。

Point Balance 更新的核心交易不能因為非核心通知失敗而被錯誤 rollback，除非實際 Business Requirement 明確要求。

---

# 13. Outbox Pattern

如果未來需要可靠地將 Domain Event 傳送到外部系統，應評估：

```text
Database Transaction
        ↓
Outbox Record
        ↓
Queue Worker
        ↓
External System
```

Outbox 的目的：

> 確保 Database Transaction 與 Event Publication 之間的一致性。

如果目前尚未實作 Outbox：

不得在 README 或 API 文件宣稱已完成。

---

# 14. External Integration

本系統的重要目標之一是讓第三方系統容易整合。

API 設計應考慮：

### Authentication

第三方系統如何取得與使用 Token。

### Tenant Context

Request 如何正確識別 Tenant。

### Customer

外部系統如何：

- 建立會員
- 查詢會員
- 查詢會員狀態

### Points

外部系統如何：

- Earn
- Redeem
- Refund
- Adjust
- 查詢 Point Balance
- 查詢 Point Transactions

### Idempotency

外部系統 retry 時不能造成重複扣點或加點。

### Webhook

未來可以讓外部系統接收：

```text
Point Earned
Point Redeemed
Point Refunded
Point Expired
```

等事件。

---

# 15. API Error Contract

所有 API 應維持一致的 Response 結構。

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

不要讓不同 Controller 自行發明完全不同的 Response 格式。

Error response 必須讓外部系統容易解析。

---

# 16. API Documentation

Swagger / OpenAPI 是第三方整合的重要入口。

API 文件：

```text
/api/documentation
```

API 必須按照 Business Domain 分類。

例如：

```text
Authentication
Customers
Points
Tenants
Users
```

實際分類必須以專案目前存在的 API 為準。

不要建立不存在的 API 分類。

每個重要 API 應盡可能描述：

- HTTP Method
- Path
- Authentication
- Request Parameters
- Request Body
- Response
- Error Response
- Example

---

# 17. Swagger Version Path

必須特別注意：

Laravel route：

```text
/api/v1/...
```

Swagger 不得產生：

```text
/api/v1/api/v1/...
```

如果 OpenAPI Server 已經使用：

```text
/api/v1
```

Endpoint path 就不應再次重複 `/api/v1`。

修改 Swagger 前必須先確認實際 Laravel routes。

---

# 18. Rate Limiting

目前已提供 Laravel 基礎 API Rate Limiting，所有 API 路由皆已套用 `throttle:api`。

但目前限制模型並非以 Tenant 為獨立隔離單位，未來如實際流量與業務需求需要，再評估實作 Tenant-aware Rate Limiting：

設計時要考慮：

```text
Tenant A
    ↓
大量 API Requests

Tenant B
    ↓
正常 API Requests
```

不能因 Tenant A 的大量流量影響 Tenant B。

因此未來應評估：

```text
Tenant-aware Rate Limiting
```

但除非實際需求與程式碼證明必要，不得自行引入複雜 Gateway 或第三方 Rate Limit 系統。

---

# 19. Database Integrity

Database Constraint 是最後一道資料一致性防線。

不要只依賴 Application Code。

新增資料表或修改 Point Domain 時，必須檢查：

- Primary Key
- Foreign Key
- Unique Constraint
- Index
- Tenant Index
- Nullable
- Default
- Data Type

Application Logic 與 Database Constraint 必須互相配合。

---

# 20. Testing

任何涉及核心 Business Logic 的修改都必須考慮測試。

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
每次 redeem = 20
```

預期：

```text
成功 5 次
失敗 5 次
最終 balance = 0
```

### Multi-Tenant

確認：

```text
Tenant A
不能操作
Tenant B
的 Customer / Point
```

### Idempotency

相同：

```text
Idempotency-Key
```

重複 Request 不應重複執行 Business Operation。

---

# 21. Development Workflow

任何修改前：

```text
1. Inspect
2. Understand
3. Verify
4. Modify
5. Test
6. Report
```

禁止：

```text
Guess
↓
Rewrite
↓
Hope it works
```

必須先閱讀相關：

- Controller
- Request
- Resource
- Service
- Model
- Migration
- Routes
- Config
- Tests

再決定修改方式。

---

# 22. Minimal Change Principle

優先：

> 最小且正確的修改

不要因為發現一個問題就：

- 重寫整個 Service
- 重寫整個 Controller
- 更換 Authentication
- 更換 Multi-Tenancy
- 更換 Database Architecture
- 引入 Microservices

除非有實際證據證明目前架構無法滿足需求。

---

# 23. Do Not Add Technology for Appearance

禁止為了讓專案看起來「Enterprise」而任意加入：

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

技術選擇必須能回答：

> 為什麼需要？

以及：

> 解決了什麼實際問題？

如果 Laravel + Redis + MySQL + Queue 已經能正確解決問題，優先保持簡單。

---

# 24. Documentation Accuracy

README、Swagger、AGENTS.md、Code Comments 都必須反映實際狀態。

區分：

```text
Implemented
```

與：

```text
Planned
```

不得把尚未完成的：

- Idempotency
- Point Lot / FIFO
- Event-Driven
- Outbox
- Webhook
- Tenant-aware Rate Limiting

寫成已完成。

文件寧可少，也不能虛構。

---

# 25. AI Coding Agent Rules

你是本專案的 Coding Agent。

每次接到任務時：

### Step 1 — Inspect

先找實際程式碼。

### Step 2 — Trace

確認：

```text
Route
 → Controller
 → Request
 → Service
 → Model
 → Database
```

必要時追蹤：

```text
Middleware
 → Authentication
 → Tenant Context
```

### Step 3 — Identify Root Cause

不要只修表面錯誤。

先找真正原因。

### Step 4 — Minimal Fix

只修改完成任務所需要的程式碼。

### Step 5 — Test

執行適合的：

```bash
php artisan test
```

或相關測試。

### Step 6 — Verify

如果涉及：

- Routes
- Swagger
- Migration
- Config
- Queue
- Cache

必須實際驗證。

### Step 7 — Report

最後清楚回報：

```text
Problem
Root Cause
Changed
Tests
Result
Remaining Risks
```

---

# 26. Critical Rule

當你不知道時：

> **不要猜。**

應該：

```text
Search the code
↓
Inspect the configuration
↓
Inspect the database
↓
Inspect the tests
↓
Verify runtime behavior
```

只有確認實際狀態後，才能提出修改方案。

---

# 27. Project Direction

本專案長期方向：

```text
                         ┌──────────────┐
                         │   Website    │
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
                         │ E-commerce   │
                         └──────┬───────┘
                                │
                                ▼
                    ┌──────────────────────┐
                    │   Loyalty API        │
                    │      /api/v1         │
                    ├──────────────────────┤
                    │ Authentication       │
                    │ Tenant Context       │
                    │ Customers            │
                    │ Points               │
                    │ Transactions         │
                    │ Integration          │
                    └──────────┬───────────┘
                               │
                 ┌─────────────┼─────────────┐
                 ▼             ▼             ▼
              MySQL          Redis         Queue
```

最終目標不是「完成一個後台 CRUD」。

而是建立：

> **一套可以被不同產品、平台與第三方服務重複整合的 Multi-Tenant Loyalty / Point API Platform。**

---

# 28. Implementation Status

本節記錄所有功能的實際實現狀態，狀態定義：

- **Implemented**: 功能已完成，且其必要的 production code、configuration、schema、tests 或 runtime verification 已存在，所有必要證據齊全
- **Implemented — Core (Validation In Progress)**: 核心程式碼已實作，但完整驗證、邊緣場境測試或生產環境驗證仍在進行中
- **Not Yet Fully Verified**: 基礎機制存在，但真實世界場景的完整驗證尚未完成
- **Planned**: 僅有架構規劃，尚未實作

## Evidence Rule

Implementation status must be determined from repository evidence.

Evidence may include:

- Production Code
- Database Migration
- Routes
- Configuration
- Tests
- Runtime verification

Architecture diagrams, comments, README descriptions,
or planned designs are not sufficient evidence by themselves.

When documentation conflicts with implementation:

1. Runtime behavior
2. Production code
3. Tests
4. Database schema / configuration
5. Documentation
6. Planned design

take precedence in determining current capability.

Runtime evidence and actual implementation take precedence over documentation. Tests are verification evidence and must not be weakened merely to make documentation claims pass.

When implementation exists but tests are incomplete: Do not claim full verification.
When functionality is planned but not implemented: Do not describe it as a current capability.

### Evidence Completeness Rule

Implementation Status 不得僅根據「存在某個 class、migration 或 method」判定功能已完成。
判定一項功能時，必須確認其完整流程：

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
Runtime Verification（適用時）
```

對於具有資料一致性要求的功能，還必須確認：

- Application Logic
- Database Constraints
- Transaction Boundary
- Concurrency Behavior
- Failure / Retry Behavior
- Tenant Isolation
- Relevant Tests
  是否彼此一致。

例如：
`Point Lot / FIFO` 不能僅因為存在 `PointLot` Model、Migration 和 FIFO Query 就視為完整實作。
必須確認：

```text
Earn
    ↓
Create Lot

Redeem
    ↓
Consume Lot FIFO

Refund
    ↓
Create Lot

Adjust +
    ↓
Create Lot

Adjust -
    ↓
Consume Lot FIFO

Expire
    ↓
Consume / Expire Lot
```

並驗證：

```text
SUM(PointLot.remaining_points)
    =
PointAccount.balance
```

在所有會影響 Point Balance 的操作完成後仍成立。

同樣地，Idempotency 不能僅因為存在：

```text
Idempotency-Key
Idempotency Middleware
idempotency_keys table
UNIQUE(tenant_id, idempotency_key)
```

就視為完整實作。
還必須驗證：

- 相同 Tenant + 相同 Key 不重複執行 Business Operation
- 相同 Key + 不同 Request Payload 會被拒絕
- 不同 Tenant 可以使用相同 Key
- 已完成 Request 可以正確 Replay Response
- Duplicate Request 不會建立第二筆 PointTransaction
- Concurrent Duplicate Request 不會造成重複扣點或加點
- Replay 的 HTTP Status / Response Contract 符合既有 API 行為

如果上述必要條件尚未全部驗證，不得標記為完整 `Implemented`.

---

## Implemented

### Multi-Tenancy

- **Tenant model**: 已實作 [app/Models/Tenant.php](app/Models/Tenant.php)
- **User tenant_id**: 已實作，使用者綁定租戶 [database/migrations/2026_09_15_022027_add_tenant_id_to_users_table.php](database/migrations/2026_09_15_022027_add_tenant_id_to_users_table.php)
- **TenantResolver**: 已實作 [app/Support/Tenancy/TenantResolver.php](app/Support/Tenancy/TenantResolver.php)
- **TenantContext**: 已實作 [app/Support/Tenancy/TenantContext.php](app/Support/Tenancy/TenantContext.php)
- **BelongsToTenant**: 已實作全域租戶範圍 [app/Models/Concerns/BelongsToTenant.php](app/Models/Concerns/BelongsToTenant.php)
- **Global Scope**: 已實作，自動套用租戶過濾
- **Super Admin bypass**: 已實作，Super Admin (tenant_id = null) 可跳過租戶隔離
- **Tenant Admin isolation**: 已實作，一般使用者只能存取所屬租戶資料
- **測試覆蓋**: TenantIsolationTest、SuperAdminTenantTest、TenantAdminPermissionTest [tests/Feature/](tests/Feature/)

### Authentication

- **JWT**: 已實作 API 認證，使用 tymon/jwt-auth [config/jwt.php](config/jwt.php)
- **Web Session**: Filament 後台使用標準 Laravel Web Session
- **Guards**: api (JWT) + web 雙守護程序
- **Middleware**: auth:api、tenant 中間件實作 [app/Http/Middleware/TenantMiddleware.php](app/Http/Middleware/TenantMiddleware.php)
- **Token lifecycle**: Login/Logout/Refresh/Me 已完整實作
- **測試覆蓋**: AuthApiTest [tests/Feature/AuthApiTest.php](tests/Feature/AuthApiTest.php)

### Point System Core

- **PointService**: 已完整實作核心點數服務 [app/Services/Point/PointService.php](app/Services/Point/PointService.php)
- **PointAccount**: 已實作，記錄當前餘額 [app/Models/PointAccount.php](app/Models/PointAccount.php)
- **PointTransaction**: 已實作，交易分類帳 [app/Models/PointTransaction.php](app/Models/PointTransaction.php)
- **lockForUpdate()**: 已實作，資料庫行鎖
- **Redis Lock**: 已實作，分散式鎖
- **DB Transaction**: 已實作，交易原子性保證
- **Deadlock Retry**: 已實作，DB::transaction 重試機制
- **核心規範**: 任何影響 PointAccount.balance 的操作，必須透過 PointService 統一處理，同步維持 PointLot 與 PointTransaction 會計不變量
- **會計不變量**: 必須保持 `SUM(PointLot.remaining_points) = PointAccount.balance` 成立，所有影響餘額的操作都需驗證此不變量
- **高並發架構**:
    ```text
    Redis Lock
          ↓
    DB Transaction
          ↓
    PointAccount lockForUpdate
          ↓
    PointLot lockForUpdate
    ```

### Queue Infrastructure

- **Laravel Queue infrastructure**: 已安裝並設定 [config/queue.php](config/queue.php)
- **Redis queue configuration**: 已完成，支援 Redis 驅動
- **Business-specific asynchronous jobs**: 規劃中

### API v1

- **routes/api.php**: 已實作 v1 API 路由 [routes/api.php](routes/api.php)
- **實際 API 領域**: Authentication、Customers、Points、PointAccounts、PointTransactions
- **Controller**: 已實作 API 控制器 [app/Http/Controllers/Api/V1/](app/Http/Controllers/Api/V1/)
- **Middleware aliases**: 已註冊 idempotent、tenant 中間件別名
- **Request Validation**: 透過 Form Requests 或 Laravel 驗證機制實作
- **測試覆蓋**: CustomerApiTest [tests/Feature/Api/V1/CustomerApiTest.php](tests/Feature/Api/V1/CustomerApiTest.php)

### Swagger / OpenAPI

- **L5-Swagger**: 已安裝 darkaonline/l5-swagger [config/l5-swagger.php](config/l5-swagger.php)
- **OpenAPI Attributes**: 已使用
- **/api/documentation**: 可存取
- **API 前綴**: `/api/v1`，無重複路徑問題

### Basic API Rate Limiting

- **Laravel RateLimiter**: 已使用基礎速率限制 `throttle:api`
- **Route throttle**: 已在所有 API 路由套用
- **目前限制**: 以 Laravel 預設全域速率限制為基礎，尚未以 Tenant 為獨立隔離單位

---

## Implemented — Core (Validation In Progress)

### Point Lot / FIFO

- **核心操作全部實作**:
    - Earn: PointAccount += amount + PointTransaction = EARN + PointLot += amount ✅
    - Redeem: PointLot FIFO consumption + PointAccount -= amount + PointTransaction = REDEEM ✅
    - Refund: PointAccount += amount + PointTransaction = REFUND + PointLot += amount ✅
    - Adjust positive: PointAccount += amount + PointTransaction = ADJUST + PointLot += amount ✅
    - Adjust negative: PointLot FIFO consumption + PointAccount -= amount + PointTransaction = ADJUST ✅
    - Expire: PointLot FIFO consumption + PointAccount -= amount + PointTransaction = EXPIRE ✅
- **會計不變量**: 需驗證永遠保證 `SUM(PointLot.remaining_points) = PointAccount.balance` (程式碼維護一致性，驗證中)
- **FIFO 排序**: `earned_at ASC, id ASC` [app/Services/Point/PointService.php](app/Services/Point/PointService.php)
- **PointLot model**: 已實作 [app/Models/PointLot.php](app/Models/PointLot.php)
- **point_lots migration**: 已建立 [database/migrations/2026_09_20_000002_create_point_lots_table.php](database/migrations/2026_09_20_000002_create_point_lots_table.php)
- **測試覆蓋**: 實際存在的測試檔案需透過專案掃描確認，目前已知路徑下存在 PointLotFifoTest.php、PointServiceConcurrencyTest.php、PointTransactionConcurrencyTest.php [tests/Feature/Services/Point/](tests/Feature/Services/Point/)
- **目前狀態**: 目前 Point Lot / FIFO 核心流程已建立，實際各點數操作是否完整維持 PointAccount、PointLot、PointTransaction 三者會計不變量，以目前 PointService 與相關測試驗證結果為準，剩餘工作仍需完整驗證所有邊緣場景，並長期觀察生產環境運行狀況

### Database Idempotency

- **Database-backed idempotency mechanism**: 已建立
- **idempotency_keys migration**: UNIQUE(tenant_id, idempotency_key) 已實作 [database/migrations/2026_09_20_000001_create_idempotency_keys_table.php](database/migrations/2026_09_20_000001_create_idempotency_keys_table.php)
- **Idempotency model**: 已實作 [app/Models/IdempotencyKey.php](app/Models/IdempotencyKey.php)
- **處理狀態**: processing/completed/failed 已建立
- **回應重放機制**: 已實作
- **實際套用範圍**: 僅套用在需要冪等性保護的點數變更路由 (POST /customers/{customer}/point-transactions, POST /customers/{customer}/points/redeem)
- **DatabaseIdempotencyMiddleware**: [app/Http/Middleware/DatabaseIdempotencyMiddleware.php](app/Http/Middleware/DatabaseIdempotencyMiddleware.php)
- **測試覆蓋**: DatabaseIdempotencyTest [tests/Feature/Services/Idempotency/DatabaseIdempotencyTest.php](tests/Feature/Services/Idempotency/DatabaseIdempotencyTest.php)
- **目前狀態**: 核心機制已建立，但基本 replay / duplicate execution 測試尚未全部通過，仍在驗證與修正階段

---

## Not Yet Fully Verified

### Real Multi-process / Multi-worker HTTP Concurrency

- **目前測試已驗證**:
    - Transaction atomicity
    - lockForUpdate behavior
    - Redis lock flow
    - insufficient balance protection
    - sequential stress scenarios
- **目標驗證場景 (Expected Scenario)**:

    ```text
    Initial balance = 100
    10 concurrent redeem attempts
    Each redeem = 20

    Expected invariant:
    - Maximum successful redemptions = 5
    - Remaining balance = 0
    - No negative balance
    - No duplicated ledger effects
    ```

- **Current automated tests validate locking and transaction correctness, but do not yet represent true multi-process HTTP concurrency.**
- **尚未驗證**:
    - multi-process concurrency
    - multi-worker concurrency
    - real HTTP concurrent requests
    - k6 / wrk load test

---

## Planned

### Domain Events & Async Processing

- **Domain Events**: 規劃中
- **Outbox pattern**: 尚未實作
- **Webhook delivery**: 尚未實作
- **Business-specific Jobs**: 規劃中

### External System Adapters / Integrations

- **Current REST API**: 已提供第三方系統整合介面，可供 Website/Mobile App/POS/E-commerce/CRM 等系統使用
- **External system connectors (Salesforce/POS/CRM etc.)**: 尚未實作

### Tenant-aware Rate Limiting

- 目前已提供 Laravel 基礎 API Rate Limiting
- 未來如實際流量與業務需求需要，再評估實作以 Tenant 為獨立隔離單位的速率限制

---

## 技術堆疊 (實際版本)

- PHP 8.2+ (constraint: ^8.2)
- Laravel 12.69.2 (constraint: ^12.0)
- Filament 5.8 (constraint: ^5.8)
- Livewire 4.4 (constraint: ^4.4)
- tymon/jwt-auth 2.3 (constraint: ^2.3)
- darkaonline/l5-swagger 11.1 (constraint: ^11.1)
- bezhansalleh/filament-shield 4.3.1 (constraint: 4.3.1)
