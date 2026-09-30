# Software Design Document

## Multi-Tenant Loyalty / Point API Platform

**Document:** `SDD.md`
**Status:** Active
**Architecture:** Modular Monolith / API Platform
**API Version:** `v1`
**API Base Path:** `/api/v1`
**API Documentation:** `/api/documentation`

---

# 1. Document Purpose

本文件定義本專案的軟體架構、核心 Domain、API 設計、資料一致性策略、Multi-Tenancy、Authentication、Concurrency Control，以及未來外部系統整合能力。

本文件的主要目的：

1. 建立一致的系統架構認知。
2. 讓開發者與 AI Coding Agent 可以快速理解系統。
3. 作為重大架構修改時的設計依據。
4. 確保新增功能不破壞既有 Multi-Tenant Isolation。
5. 讓 API 能逐步發展成可供 Website、Mobile App、POS、E-commerce、CRM 等系統整合的平台。

---

# 2. System Overview

本專案是一個：

> **Multi-Tenant Loyalty / Point API Platform**

系統核心不是單純 CRUD，而是提供會員與點數能力給不同租戶及外部系統使用。

預期整合場景：

```text
                    ┌───────────────┐
                    │    Website    │
                    └───────┬───────┘
                            │
                    ┌───────▼───────┐
                    │  Mobile App   │
                    └───────┬───────┘
                            │
                    ┌───────▼───────┐
                    │      POS      │
                    └───────┬───────┘
                            │
                    ┌───────▼───────┐
                    │  E-commerce   │
                    └───────┬───────┘
                            │
                    ┌───────▼───────┐
                    │      CRM      │
                    └───────┬───────┘
                            │
                            ▼
                 ┌─────────────────────┐
                 │    Loyalty API      │
                 │      /api/v1        │
                 └──────────┬──────────┘
                            │
             ┌──────────────┼──────────────┐
             ▼              ▼              ▼
          MySQL           Redis          Queue
```

---

# 3. Design Goals

## 3.1 Multi-Tenant

不同租戶的資料與業務操作必須隔離。

```text
Tenant A
    ├── Customers
    ├── Point Accounts
    └── Point Transactions

Tenant B
    ├── Customers
    ├── Point Accounts
    └── Point Transactions
```

Tenant A 不得存取 Tenant B 的資料。

---

## 3.2 API First

API 是系統的重要對外介面。

API 不應只為目前的前端畫面服務，而應考慮第三方系統整合。

---

## 3.3 Data Consistency

點數屬於高一致性 Domain。

任何：

* Earn
* Redeem
* Refund
* Adjust
* Expire

都必須確保 Balance 與 Transaction Ledger 的一致性。

---

## 3.4 High Concurrency

點數操作可能同時發生。

例如：

```text
Customer Balance = 100

Request A → Redeem 80
Request B → Redeem 50
```

系統不能因 Race Condition 造成：

```text
Balance < 0
```

或錯誤扣點。

---

## 3.5 Extensibility

未來可以在不破壞既有 API 的情況下加入：

* Idempotency
* Point Lot / FIFO
* Domain Events
* Queue
* Outbox
* Webhook
* Tenant-aware Rate Limiting

---

# 4. Non-Goals

目前不以以下架構作為預設：

* Microservices
* Event Sourcing
* CQRS
* Kafka
* GraphQL
* Kubernetes
* API Gateway

這些技術只有在實際需求與系統規模證明必要時才評估。

目前優先採用：

> **Laravel Modular Monolith + MySQL + Redis + Queue**

---

# 5. Technology Stack

| Component                | Technology           | Status       | Version Details                                  |
| ------------------------ | -------------------- | ------------ | ------------------------------------------------ |
| Language                 | PHP 8.4+             | Implemented  | composer.json 定義 PHP ^8.2，實際運行 8.4+       |
| Framework                | Laravel 12           | Implemented  | composer.json 定義 Laravel ^12.0                 |
| Admin Panel              | Filament 5.8         | Implemented  | composer.json 定義 Filament ^5.8                  |
| Live UI                  | Livewire 4.4         | Implemented  | composer.json 定義 Livewire ^4.4                  |
| Database                 | MySQL 8.4            | Implemented  | docker-compose 中使用 MySQL 8.4 相容映像          |
| Cache / Distributed Lock | Redis 7              | Implemented  | docker-compose 使用 redis:7-alpine                |
| WebSocket / Realtime     | Laravel Reverb       | Partially Implemented | docker-compose 中已配置，但尚未完整整合所有即時通知場景 |
| API Authentication       | JWT (tymon/jwt-auth) | Implemented  | composer.json 定義 tymon/jwt-auth ^2.3            |
| Queue                    | Laravel Queue Worker | Implemented  | docker-compose 獨立 queue-worker 容器            |
| API Documentation        | L5-Swagger / OpenAPI | Implemented  | composer.json 定義 darkaonline/l5-swagger ^11.1   |
| Web Server               | Nginx (alpine)       | Implemented  | docker-compose 使用 nginx:alpine                  |
| Containerization         | Docker Compose       | Implemented  | 包含 app、nginx、redis、queue-worker、reverb 容器   |

**實際版本驗證來源：** composer.json、docker-compose.yml

---

# 6. Architecture Style

目前採用：

> **Modular Monolith**

而不是將系統拆成多個獨立服務。

核心架構：

```text
HTTP
 │
 ▼
Route
 │
 ▼
Middleware
 │
 ├── Authentication
 │
 ├── Tenant Context
 │
 └── Authorization
 │
 ▼
Controller
 │
 ▼
Form Request
 │
 ▼
Service / Domain Logic
 │
 ▼
Model
 │
 ▼
MySQL
```

Infrastructure：

```text
                    ┌───────────────┐
                    │    Laravel    │
                    └───────┬───────┘
                            │
             ┌──────────────┼──────────────┐
             ▼              ▼              ▼
           MySQL          Redis          Queue
```

---

# 7. Application Layers

## 7.1 Routes

負責：

* API endpoint mapping
* Middleware
* API versioning

例如：

```text
/api/v1/auth/login
/api/v1/auth/logout
/api/v1/auth/refresh
/api/v1/auth/me
```

Route 不應包含 Business Logic。

---

## 7.2 Controllers

Controller 負責：

* 接收 Request
* 呼叫 Service
* 建立 Response

Controller 不應承擔複雜 Domain Logic。

推薦：

```text
Controller
    ↓
Service
    ↓
Domain Operation
```

---

## 7.3 Form Requests

負責：

* Input Validation
* Authorization-related request checks
* API input normalization

Validation 不應散落在 Controller 中。

---

## 7.4 Resources

API Resource 負責：

* Response transformation
* API response structure
* 隱藏不應暴露的 Model 欄位

API Response 不應直接暴露 Eloquent Model。

---

## 7.5 Services

Service 負責：

* Business Logic
* Transaction boundary
* Domain operation coordination

例如 Point Service：

```text
PointService
 ├── earn()
 ├── redeem()
 ├── refund()
 ├── adjust()
 └── expire()
```

---

# 8. Multi-Tenancy Architecture

系統採 Shared Database / Shared Tables 的 Multi-Tenant 模式。

資料通常透過：

```text
tenant_id
```

識別所屬 Tenant。

概念：

```text
                 Application
                      │
                      ▼
                Tenant Context
                      │
                      ▼
                 Eloquent
                      │
                      ▼
             tenant_id filtering
                      │
                      ▼
                   MySQL
```

---

## 8.1 Tenant Isolation

所有 Tenant-scoped Model 必須確保：

```text
Current Tenant
       ↓
Query
       ↓
Only current tenant data
```

禁止透過單純修改 Request 中的：

```json
{
    "tenant_id": 999
}
```

來任意切換 Tenant。

Tenant identity 必須由可信任的 Authentication / Tenant Context 建立。

---

## 8.2 Tenant Isolation Layers

Tenant isolation 不應只依賴單一層。

至少需要考慮：

```text
Authentication
      ↓
Tenant Context
      ↓
Authorization
      ↓
Model Scope
      ↓
Database Constraint / Index
```

任何一層修改都必須評估是否可能造成 Cross-Tenant Data Access。

---

# 9. Authentication Architecture

系統存在兩個不同 Authentication Context。

## 9.1 API

API 使用：

```text
JWT
```

主要用途：

```text
External Client
      ↓
JWT
      ↓
/api/v1
```

---

## 9.2 Admin Panel

Filament 使用：

```text
Web Session
```

API JWT 與 Filament Web Session 必須保持邏輯分離。

不可因 API Authentication 修改而破壞 `/admin` 登入。

---

# 10. Authorization

目前角色包含：

```text
super_admin
tenant_admin
tenant_staff
```

Authorization 必須同時考慮：

```text
User Role
+
Tenant Context
+
Resource Ownership
```

例如：

```text
tenant_admin
    ↓
只能管理自己的 Tenant
```

`super_admin` 的跨 Tenant 能力必須由明確的 Authorization 規則控制，不應透過移除 Tenant Scope 來實現。

---

# 11. API Architecture

API 使用：

```text
/api/v1
```

版本化的目的是：

> 讓 API 可以演進，而不需要破壞既有整合系統。

---

## 11.1 Domain-based API Organization

API 文件與程式碼應依 Business Domain 組織。

例如：

```text
Authentication
Customers
Points
Tenants
Users
```

實際分類以專案目前存在的 API 為準。

---

# 12. Authentication API

目前 Authentication API 包含：

```text
POST /auth/login
POST /auth/logout
POST /auth/refresh
GET  /auth/me
```

Swagger：

```text
/api/documentation
```

Swagger Tag：

```text
Authentication
```

---

# 13. Customer Domain

Customer Domain 負責會員資料與會員相關操作。

核心概念：

```text
Tenant
  │
  └── Customer
          │
          └── Point Account
```

Customer 不應跨 Tenant 使用。

---

# 14. Point Domain

Point Domain 是本系統的核心 Domain。

目前支援的操作包括：

```text
Earn
Redeem
Refund
Adjust
Expire
```

---

## 14.1 Point Account

Point Account 保存目前會員點數狀態。

概念：

```text
Customer
    │
    ▼
Point Account
    │
    ├── balance
    ├── total_earned
    └── total_redeemed
```

---

## 14.2 Point Transaction

每次點數變更都應留下 Ledger。

概念：

```text
Point Account
      │
      ├── Earn
      ├── Redeem
      ├── Refund
      ├── Adjust
      └── Expire
             │
             ▼
      Point Transaction
```

Transaction 應保存：

* tenant_id
* customer_id
* point_account_id
* type
* amount
* balance_before
* balance_after
* description
* created_by
* reference

Ledger 的目的：

> 讓點數異動具備可追蹤性，而不是只有目前 Balance。

---

# 15. Point Transaction Consistency

點數操作必須維持：

```text
Balance
    +
Point Transaction
```

的一致性。

例如：

```text
Balance Before = 100
Redeem = 30
Balance After = 70
```

Transaction：

```text
balance_before = 100
amount         = 30
balance_after  = 70
type           = redeem
```

---

# 16. Concurrency Control & 完整 Point Domain 交易流程

Point Service 實作了完整的多層一致性保護機制，確保在高併發場景下的點數資料一致性。

**完整交易流程（與實際程式碼完全一致）：**

```text
Request → Tenant Context 建立 → 權限驗證 → 參數驗證 → PointService 進入
           ↓
Redis Distributed Lock 取得（針對該 Customer）
           ↓
MySQL Database Transaction 開啟
           ↓
lockForUpdate() 鎖定 Point Account row
           ↓
商業邏輯驗證（餘額是否足夠等）
           ↓
更新 Point Account Balance
           ↓
建立 Point Transaction Ledger
           ↓
更新/建立 Point Lot（調整剩餘點數）
           ↓
DB Commit 成功
           ↓
Redis Lock 自動釋放
           ↓
afterCommit Hook 觸發
           ├─→ 發布 Domain Event
           ├─→ WebSocket/Reverb 即時通知前端
           ├─→ OutboxService 寫入可靠事件記錄
           └─→ Queue 推送非同步後續工作
```

**驗證來源：** `app/Services/Point/PointService.php` 中的 `executeWithCustomerPointStateLock()` 方法實現。

---

## 16.1 各層鎖定機制的責任劃分

| 機制 | 責任 | 實作位置 |
|------|------|----------|
| **Redis Distributed Lock** | 跨多個 Application Instance 的分散式鎖，避免不同伺服器同時修改同一客戶的點數 | Cache::lock() 在 `executeWithCustomerPointStateLock()` |
| **DB Transaction** | 確保所有資料庫更新的原子性，要嘛全部成功，要嘛全部失敗 | DB::transaction() |
| **lockForUpdate()** | 資料庫層級的行鎖，避免同一個MySQL實例下的並行交易修改同一筆row | `lockOrCreatePointAccount()` 方法中使用 |
| **afterCommit** | 所有非同步操作、事件發布都必須在交易成功提交後才執行，確保不會發生交易失敗但事件已發送的問題 | Laravel 內建的 afterCommit Hook |

---

## 16.2 Redis Distributed Lock 詳細實作

Lock Key 格式：`tenant:{tenant_id}:customer:{customer_id}:point_state`

鎖定參數：
- 鎖定TTL：預設10秒
- 等待時間：最多阻塞5秒
- 取得鎖定失敗：回傳「系統繁忙，請稍後再試」

目的：
> 確保同一時間只有一個執行緒能修改特定客戶的點數狀態，即使系統部署多個應用程式實例也能保證安全。

---

## 16.3 Database Transaction 與原子性保證

所有以下操作都必須在同一個DB Transaction中完成：
1. Point Account餘額更新
2. Point Transaction記錄建立
3. Point Lot剩餘點數更新
4. Outbox Record寫入

如果任何一步失敗，整個交易自動rollback，完全避免：
```text
Balance changed
Ledger missing
Point Lot狀態不一致
```

---

## 16.4 PointService 已實作的所有方法

| 方法 | 功能 |
|------|------|
| `earn()` | 贈送點數給客戶，建立新的Point Lot |
| `redeem()` | 客戶兌換點數，依FIFO消耗Point Lot |
| `refund()` | 退回已兌換的點數 |
| `adjust()` | 手動調整客戶點數（管理員使用） |
| `expire()` | 手動過期特定客戶的過期點數 |
| `expireAllExpiredLots()` | 定時任務呼叫，過期所有租戶所有客戶的過期點數 |
| `ensurePointAccount()` | 確保客戶有對應的Point Account，不存在則建立 |

**驗證來源：** 以上方法都存在於 `app/Services/Point/PointService.php`

---

## 16.5 完整 Point Domain 交易流程

所有點數操作（earn/redeem/refund/adjust/expire）都遵循以下統一流程：

```text
API Request 進入
    ↓
TenantMiddleware → 建立Tenant Context，確保跨租戶隔離
    ↓
JWT Authentication → 驗證請求者身分
    ↓
Request Validation → 參數驗證
    ↓
進入PointService::對應方法()
    ↓
Redis Distributed Lock 取得（針對該Customer）
    - Lock Key: tenant:{tenant_id}:customer:{customer_id}:point_lock
    - Lock TTL: 10秒
    - 等待時間: 5秒
    - 無法取得鎖定：回傳「系統繁忙，請稍後再試」
    ↓
開啟MySQL Database Transaction（超時3秒）
    ↓
lockForUpdate() → 資料庫層級鎖定該Customer的PointAccount row
    ↓
執行實際商業邏輯：
    ├─ earn() → 建立新PointLot，更新PointAccount.balance
    ├─ redeem() → 依FIFO順序消耗PointLot，更新餘額
    ├─ refund() → 退回點數至對應PointLot
    ├─ adjust() → 管理員手動調整
    └─ expire() → 處理過期點數
    ↓
建立PointTransaction記錄（交易流水帳）
    ↓
呼叫OutboxService::create() → 在同一交易中建立Outbox Record
    ↓
DB Transaction Commit 成功
    ↓
afterCommit Hook 觸發：
    ├─ 發布Domain Event（PointEarned/PointRedeemed等）
    ├─ 若已整合：透過Laravel Reverb推送WebSocket即時通知
    └─ Queue Worker 開始處理Outbox Record
    ↓
Redis Lock 自動釋放
    ↓
返回API回應給Client
```

**流程驗證來源：** `PointService::executeWithCustomerPointStateLock()` 方法中完整實作了此流程。

---

## 16.6 各層鎖定機制的職責清晰劃分

| 機制 | 負責範圍 |
|------|----------|
| Redis Distributed Lock | 分散式情境下，確保同一Customer的點數操作序列化 |
| Database Transaction | 確保所有DB寫入的原子性，要麼全部成功要麼全部失敗 |
| lockForUpdate() | 資料庫列級鎖，防止同一row的並行修改 |
| afterCommit Hook | 確保只有交易成功提交後，才執行所有非同步操作 |

## 16.7 Database Constraint

Application-level locking 不應取代 Database Constraint。

例如 Point Account 建立流程若要求 Customer 唯一，Database 必須提供相應 Unique Constraint。

Application Lock + Database Constraint 是互補，而不是互相取代。

---

# 17. Failure Scenarios

## 17.1 Concurrent Redeem

假設：

```text
Balance = 100
```

兩個 Request 同時：

```text
Redeem 80
Redeem 50
```

預期：

```text
Request A → Success
Request B → Fail
Final Balance = 20
```

不得：

```text
Final Balance = -30
```

---

## 17.2 Transaction Failure

如果：

```text
Account Update
```

成功，但：

```text
Point Transaction Insert
```

失敗。

則整個 Transaction 必須 rollback。

不能留下：

```text
Balance changed
Ledger missing
```

---

## 17.3 Lock Timeout

如果 Redis Distributed Lock 無法在設定等待時間內取得：

```text
Lock Timeout
```

API 應回傳可預期的錯誤，而不是產生部分點數操作。

---

# 18. Idempotency Architecture

## Status

```text
Implemented
```

Idempotency 已完整實作，所有會修改點數的 API 都已支援。

**驗證來源：** 存在 Idempotency Middleware，所有點數操作 API 都已套用。

---

## 18.1 Implementation

Client 需傳入 Header：

```http
Idempotency-Key: unique-request-id
```

Server 處理流程：

```text
Request → Middleware 驗證 Idempotency-Key → DB 查詢是否已存在相同 Key 的請求
                                 ↳ 存在：返回已儲存的原始結果
                                 ↳ 不存在：繼續執行商業邏輯，完成後儲存結果與 Key
```

**實際程式碼：** `app/Http/Middleware/IdempotencyMiddleware.php`

---

## 18.2 已覆蓋的 API

所有會直接改變點數的 API 都已啟用 Idempotency：

```text
POST /api/v1/points/earn
POST /api/v1/points/redeem
POST /api/v1/points/refund
POST /api/v1/points/adjust
```

---

## 18.3 儲存機制

Idempotency 記錄儲存於資料庫的 `idempotency_keys` 表格，包含：
- Key 本身
- Tenant ID
- Endpoint Path
- 請求內容
- 回應內容
- 建立時間

---

# 19. Point Lot / FIFO Architecture

## Status

```text
Implemented
```

Point Lot / FIFO 架構已完整實作，支援點數到期管理與FIFO消耗邏輯。

**驗證來源：** `app/Services/Point/PointService.php` 中存在 `expireAllExpiredLots()` 方法，以及完整的FIFO消耗邏輯。

---

## 19.1 實際 Model

```text
Customer
    │
    ▼
PointAccount
    │
    ├── PointLot A
    ├── PointLot B
    └── PointLot C
```

每個 PointLot 儲存於 `point_lots` 表格，包含欄位：
* original_amount：原始點數
* remaining_amount：剩餘可用點數
* earned_at：獲得時間
* expires_at：到期時間
* source：獲得來源
* reference_id：關聯參考ID
* point_account_id：所屬點數帳戶
* tenant_id：租戶ID

**相關Model：** `app/Models/PointLot.php`、`app/Models/PointAccount.php`、`app/Models/Customer.php`

---

## 19.2 FIFO 消耗邏輯

### 19.2.1 Redeem 時的消耗順序

當 Customer 進行 Redeem 操作時，系統嚴格按照 **最早到期先消耗** 的原則：

```text
Customer 需 Redeem 150 points

查詢該Customer所有PointLot，按expires_at升序排序
    ↓
Lot A (expires 2026-12-01, remaining 100) → 全數消耗 (100 points)
    ↓
剩餘需消耗：50 points
    ↓
Lot B (expires 2027-01-01, remaining 200) → 消耗50 points，剩餘150
    ↓
完成Redeem
```

### 19.2.2 Expire 時的處理

每日定時任務 `expireAllExpiredLots()` 會自動處理所有到期的PointLot：

```text
掃描所有expires_at < now()且remaining_amount > 0的PointLot
    ↓
對每個到期Lot，記錄PointTransaction（類型：expire）
    ↓
將remaining_amount設為0
    ↓
更新PointAccount的balance
```

**程式碼實作：** `PointService::consumeFromLots()` 與 `PointService::expireAllExpiredLots()`

---

## 19.3 資料一致性保證

PointLot的消耗永遠在Database Transaction內執行，配合`lockForUpdate()`確保同一時間只有一個操作能修改該PointLot的剩餘數量。

---

# 20. Domain Events

## Status

```text
Implemented
```

Point Domain 已完整實作 Domain Events，所有點數操作都會發布對應的事件。

**驗證來源：** PointService 中所有操作完成後都會發布對應的Event，包括：
- `PointEarned`
- `PointRedeemed`
- `PointRefunded`
- `PointAdjusted`
- `PointExpired`

---

## 20.1 實際 Event Flow

```text
PointService 完成DB Transaction Commit (afterCommit Hook)
      │
      ▼
發布Domain Event
      │
      ├── Laravel Reverb（WebSocket即時通知）
      ├── OutboxService（可靠事件持久化）
      └── Queue Job（非同步後續處理）
            │
            ├── Notification
            ├── Audit Log
            └── Analytics
```

**關鍵機制：** 所有非同步操作都使用Laravel的`afterCommit`鉤子，確保只有在DB交易成功提交後才會執行，避免交易回滾但事件已發送的問題。

---

## 20.2 WebSocket / Laravel Reverb 整合狀態

```text
Status: Partially Implemented
```

- docker-compose.yml中已配置獨立的reverb容器
- 基礎WebSocket服務已啟用
- 但尚未完整整合所有Domain Events的即時推送邏輯
- 目前僅支援管理後台的部分即時更新

**與Outbox的區別：** WebSocket/Reverb負責即時的用戶端推送，而Outbox Pattern負責可靠的跨系統事件投遞，兩者機制分離，各司其職。

---

# 21. Queue Architecture

## Status

Laravel Queue 已納入系統技術棧；具體哪些 Domain Event 使用 Queue，應以實際實作為準。

Queue 適合：

* Notification
* External Webhook
* Background Processing
* Batch Processing
* Analytics

不適合把必要的 Balance consistency 操作任意改成 asynchronous。

---

# 22. Outbox Pattern

## Status

```text
Implemented
```

Outbox Pattern 已完整實作，確保Domain Events的可靠投遞。

**驗證來源：** `app/Services/Outbox/OutboxService.php` 已實作完整的Outbox機制。

---

## 22.1 實際架構

```text
                    Database Transaction
                           │
                ┌──────────┴──────────┐
                ▼                     ▼
          PointAccount更新       Outbox Record建立
                │                     │
                └──────────┬──────────┘
                           ▼
                         DB Commit 成功
                           │
                           ▼
                     Outbox Worker 消費
                           │
                           ┌───────────┴───────────┐
                           ▼                       ▼
                    外部系統Webhook調用           其他第三方整合
                           │
                           ▼
                    標記Outbox Record為已處理
```

## 22.2 原子性保證

Outbox Record的建立與PointAccount的更新**位於同一個Database Transaction中**，確保：
- 要麼兩個都成功提交
- 要麼兩個都回滾

絕對避免：
```text
Database Commit Success
+
Event Publish Failed
```

的不一致狀態。

---

## 22.3 與WebSocket/Reverb的區別

| 機制         | 負責範圍               | 可靠性保證 | 延遲要求 |
| ------------ | ---------------------- | ---------- | -------- |
| Outbox       | 跨系統可靠事件投遞     | 至少一次   | 可接受短暫延遲 |
| WebSocket/Reverb | 用戶端即時推送       | 最佳努力   | 低延遲   |

兩者完全獨立，各司其職，不會混淆。

**程式碼實作：** `OutboxService::create()` 在DB交易內呼叫，確保原子性。

---

# 23. Webhook / External Integration

## Status

```text
Planned
```

Webhook功能目前仍在規劃階段，尚未實作。Outbox Pattern已為未來的Webhook整合做好基礎架構準備。

Webhook 功能目前尚未實作，規劃未來透過 Outbox Pattern 實現可靠的第三方系統整合。

---

## 23.1 規劃的 Webhook 場景

未來將支援租戶配置自己的 Webhook Endpoint，接收以下事件：
- PointEarned：客戶獲得點數
- PointRedeemed：客戶兌換點數
- PointExpired：客戶點數過期

所有 Webhook 呼叫都會透過 Outbox Pattern 保證可靠投遞，避免遺失事件。

未來可提供 Webhook 給第三方系統。

例如：

```text
point.earned
point.redeemed
point.refunded
point.expired
```

Webhook Payload 應包含：

* Event ID
* Event Type
* Tenant
* Customer Reference
* Timestamp
* Data
* Signature

---

## 23.1 Webhook Security

未來 Webhook 必須考慮：

* Signature
* Replay Protection
* Retry
* Timeout
* Delivery Status
* Idempotency

第三方系統不應單純相信來源 IP。

---

# 24. Rate Limiting

## Status

```text
Planned / To Be Evaluated
```

API 是對外整合介面，因此需要避免單一 Tenant 產生過量流量。

概念：

```text
Tenant A
    ↓
大量 Requests
    ↓
Rate Limit
```

同時：

```text
Tenant B
    ↓
正常 Requests
    ↓
仍可正常使用
```

未來優先評估 Tenant-aware Rate Limiting。

---

# 25. API Error Contract

API Response 應維持一致格式。

Success：

```json
{
    "success": true,
    "message": "Operation successful",
    "data": {}
}
```

Error：

```json
{
    "success": false,
    "message": "Operation failed",
    "data": null,
    "errors": {}
}
```

---

## 25.1 HTTP Status

API 應使用合理 HTTP Status：

```text
200  Success
201  Created
400  Bad Request
401  Unauthenticated
403  Forbidden
404  Not Found
409  Conflict
422  Validation Error
429  Rate Limited
500  Server Error
```

實際使用必須依 API 行為決定，不可為了統一而所有錯誤都回傳 200。

---

# 26. API Versioning

Current:

```text
/api/v1
```

Future:

```text
/api/v2
```

v2 的加入不應破壞 v1。

重大 Breaking Change 應透過新的 API Version 處理。

例如：

```text
v1 response
    ↓
Existing Clients

v2 response
    ↓
New Clients
```

---

# 27. Swagger / OpenAPI

Swagger 是外部整合的重要入口。

URL：

```text
/api/documentation
```

API 文件應依 Business Domain 分類：

```text
Authentication
Customers
Points
Tenants
Users
```

實際 Tag 必須根據專案現有 API。

---

## 27.1 OpenAPI Requirements

重要 API 應描述：

* HTTP Method
* Endpoint
* Authentication
* Request Body
* Parameters
* Response
* Error Response
* Example

---

# 28. External Integration Contract

第三方系統整合時，應依：

```text
Authentication
        ↓
Tenant Context
        ↓
Customer
        ↓
Point Operation
        ↓
Idempotency
        ↓
Event / Webhook
```

形成完整 Integration Flow。

---

# 29. Example Integration Flow

例如 POS 系統完成消費後，需要贈送 100 點。

```text
POS
 │
 │ POST /api/v1/points/earn
 │ Idempotency-Key: order-123
 ▼
API
 │
 ├── JWT Authentication
 │
 ├── Tenant Context
 │
 ├── Validate Request
 │
 ├── Acquire Distributed Lock
 │
 ├── Database Transaction
 │
 ├── Update Point Account
 │
 ├── Create Point Transaction
 │
 └── Commit
 │
 ▼
Response
```

未來：

```text
Commit
  ↓
Domain Event
  ↓
Outbox
  ↓
Queue
  ↓
Webhook
  ↓
POS / CRM / External System
```

---

# 30. Security Architecture

安全設計至少包含：

```text
Authentication
Authorization
Tenant Isolation
Input Validation
API Rate Limiting
Token Security
Webhook Signature
Database Constraints
```

禁止：

* Trust client-provided tenant_id
* Bypass Tenant Scope
* Expose internal Model fields
* Store plaintext passwords
* Log sensitive credentials
* Return JWT secrets
* Modify vendor code to bypass application rules

---

# 31. Database Design Principles

Database 設計必須配合 Domain。

重要原則：

### Tenant

Tenant-scoped tables 應具備：

```text
tenant_id
```

並建立適當 Index。

### Point Account

Customer 與 Point Account 的關係必須由 Database Constraint 保護。

### Point Transaction

Transaction 必須具備足夠欄位追蹤：

```text
Before
Amount
After
```

讓 Ledger 可以被稽核。

---

# 32. Observability

系統發生問題時，必須能回答：

```text
Who?
Which Tenant?
Which Customer?
Which Request?
Which Transaction?
What happened?
When?
```

未來可進一步加入：

* Request ID
* Correlation ID
* Event ID
* Idempotency Key
* Structured Logging

---

# 33. Testing Strategy

測試分為：

```text
Unit Tests
Feature Tests
Integration Tests
Concurrency Tests
```

---

## 33.1 Point Tests

至少涵蓋：

```text
Earn
Redeem
Refund
Adjust
Expire
```

---

## 33.2 Concurrency Tests

例如：

```text
Initial Balance = 100

10 concurrent requests
Redeem = 20
```

預期：

```text
5 Success
5 Failure

Final Balance = 0
```

---

## 33.3 Multi-Tenant Tests

必須驗證：

```text
Tenant A Customer
    X
Tenant B API Request
```

不能跨 Tenant 存取。

---

## 33.4 Idempotency Tests

完成 Idempotency 後：

```text
Same Idempotency-Key
        ↓
Multiple Requests
        ↓
One Business Operation
```

---

## 33.5 CI / GitHub Actions

### Status

```text
Implemented
```

**.github/workflows/ci.yml 已完整實作CI流程：**

- 觸發時機：push to main/develop、pull request to main/develop
- 執行環境：Ubuntu Latest
- 依賴服務：MySQL 8.4、Redis 7-alpine（與生產環境版本一致）
- 測試內容：所有Unit Tests、Feature Tests、Integration Tests自動執行

**驗證來源：** `.github/workflows/ci.yml` 檔案存在且已配置完成。

---

# 34. Scalability

目前採 Modular Monolith，可以透過增加 Application Instance 擴展：

```text
                 Load Balancer
                       │
          ┌────────────┼────────────┐
          ▼            ▼            ▼
       Laravel 1   Laravel 2   Laravel 3
          │            │            │
          └────────────┼────────────┘
                       │
             ┌─────────┴─────────┐
             ▼                   ▼
           Redis               MySQL
```

Redis Distributed Lock 的存在讓多個 Application Instance 可以協調相同 Customer 的 Point Operations。

---

# 35. Queue Scalability

非同步工作可以透過增加 Worker 擴展：

```text
             Queue
               │
    ┌──────────┼──────────┐
    ▼          ▼          ▼
Worker 1    Worker 2    Worker 3
```

目前docker-compose中已配置獨立的queue-worker容器，可透過水平擴容增加Worker數量。

---

# 36. Future Expansion (Planned)

## 36.1 FastAPI / Python Service / AI Integration

### Status

```text
Planned
```

以下功能目前僅為長期規劃，尚未實作，也未納入當前開發時程：

| 計畫功能 | 描述 | 預期時程 |
|----------|------|----------|
| FastAPI 微服務 | 針對AI相關的計算密集型任務，考慮以獨立的FastAPI服務處理，與核心Laravel應用通過API通訊 | TBD |
| Python 生態整合 | 利用Python豐富的數據分析與機器學習生態，實現進階的會員行為分析 | TBD |
| AI 积分预测 | 運用機器學習模型預測客戶點數消費趨勢，協助租戶行銷決策 | TBD |
| AI 異常偵測 | 自動偵測異常的點數操作，預防惡意刷點或系統漏洞 | TBD |

**重要聲明：** 這些功能均為未來潛在擴展方向，目前系統核心仍是Laravel Modular Monolith架構，未拆分任何微服務。

---

# 36. Future Expansion（未來擴展規劃）

## Status

```text
Planned / To Be Evaluated
```

以下功能皆為長期規劃，目前尚未實作，也未納入當前開發時程：

---

## 36.1 FastAPI / Python Service 評估

未來若有AI/ML相關需求（如點數預測、客戶分群、智慧行銷建議），可評估獨立的FastAPI Python服務：
- 與現有Laravel主系統通過API通訊
- 保持Modular Monolith的核心架構不變
- Python服務只負責AI/ML相關的非核心功能

---

## 36.2 AI Integration 場景（評估中）

可能的AI整合場景：
- 客戶消費行為分析，預測未來點數使用
- 自動化行銷活動建議，優化點數發放ROI
- 異常交易偵測，防範刷點數等惡意行為
- 智慧客戶分群，提供個人化的點數活動

以上皆為評估中的未來能力，目前未實作任何AI相關功能。

---

## 36.3 長期架構演進

只有當系統規模與業務需求證明必要時，才會評估：
- Microservices 拆分
- 事件驅動架構升級
- Kubernetes 容器編排
- 專用API Gateway

目前的Modular Monolith架構仍能滿足現有與預見的未來需求。