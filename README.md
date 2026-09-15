# Multi-Tenant Loyalty API

> **企業級多租戶會員點數 API，專注於高併發交易安全、資料一致性、API 冪等性與可擴充後端架構。**

本專案不是單純的 Laravel CRUD API。

系統以「**點數交易不能重複、餘額不能錯誤、租戶資料不能互相存取、服務可以水平擴充**」為核心，針對企業級會員點數系統常見的併發、重試、資料一致性與多租戶隔離問題進行設計。

目前核心點數交易已採用 **Redis Distributed Lock + MySQL Transaction + Row-Level Lock** 的雙層併發控制架構，並以 Point Ledger 保存完整交易歷程。

後續架構將進一步擴充 **Idempotency、Point Lot / FIFO、Domain Events、Queue、Outbox Pattern 與 Tenant-aware Rate Limiting**。

---

## 🎯 專案定位

```text
Traditional CRUD API
        │
        ▼
Multi-Tenant API
        │
        ▼
Concurrent Transaction Safety
        │
        ▼
Idempotent API
        │
        ▼
Point Ledger / FIFO Expiration
        │
        ▼
Event-Driven Processing
        │
        ▼
Distributed & Scalable Backend
```

本專案希望展示的不是：

> 「我會使用 Laravel 建立 CRUD。」

而是：

> **「我能從企業系統實際會遇到的問題出發，設計資料一致性、併發控制、重試安全與多租戶隔離機制。」**

---

# 🚀 核心技術亮點

## 01｜高併發點數交易安全

點數系統最重要的不是「扣點 API 能不能執行」，而是：

> **大量 Request 同時操作同一個會員時，點數仍然必須正確。**

例如會員目前有：

```text
Balance = 100
```

同時收到 10 個：

```text
redeem(20)
```

如果沒有適當的併發控制，多個 Request 可能同時讀取：

```text
Balance = 100
```

造成 Lost Update，甚至讓帳戶餘額與交易紀錄不一致。

因此 Point Service 採用兩層保護：

```text
HTTP Request
      │
      ▼
Redis Distributed Lock
      │
      ▼
DB Transaction
      │
      ▼
SELECT ... FOR UPDATE
      │
      ▼
Validate Balance
      │
      ▼
Update Point Account
      │
      ▼
Create Point Transaction
      │
      ▼
Commit
```

### Redis Distributed Lock

應用層先針對同一個會員取得 Distributed Lock：

```php
$lock = Cache::lock($lockKey, $lockTTL);

return $lock->block($lockWaitSeconds, function () {
    // Point transaction
});
```

主要目的是讓多台 Application Server 在同一時間操作同一會員時，不會同時進入核心點數交易流程。

### Database Row-Level Lock

進入 Database Transaction 後，再使用：

```php
$account = $customer->pointAccount()
    ->lockForUpdate()
    ->first();
```

透過 MySQL Row-Level Lock 保護實際資料庫中的 Point Account。

因此架構不是單純依賴 Redis：

```text
Redis Lock
    ↓
降低 Application-level contention

DB Transaction + FOR UPDATE
    ↓
Database-level consistency
```

### 設計目標

- 防止 Concurrent Redeem 造成負餘額
- 防止 Lost Update
- 確保 Point Account 更新具備 Transaction Atomicity
- 確保 Point Transaction 與 Balance 同步成功或同步 rollback
- 支援多台 Application Server 水平擴充

---

# 02｜API Idempotency

企業 API 必須假設：

> **Request 可能因 Timeout、Network Error 或 Client Retry 而被重送。**

例如：

```text
Client
  │
  │ POST /api/v1/rewards/1/redeem
  │ X-Idempotency-Key: abc-123
  ▼
Server
  │
  ├── Execute redemption
  ├── Deduct points
  └── Create redemption
```

如果 Client 沒有收到 Response，再次送出相同 Request：

```text
Client
  │
  └── Retry
       │
       ▼
X-Idempotency-Key
       │
       ▼
   Already processed?
       │
      YES
       │
       ▼
Return original result
```

同一個業務操作即使被重試，也不應再次扣除點數。

### 設計目標

```text
Same Request
      │
      ▼
Same Idempotency Key
      │
      ▼
Execute Once
      │
      ▼
Return Same Result
```

Idempotency 與 Distributed Lock 負責的是不同問題：

```text
Distributed Lock
→ 解決 Concurrent Execution

Idempotency
→ 解決 Duplicate Request / Retry
```

> **實作狀態：規劃／後續實作。**

---

# 03｜Point Ledger：完整點數交易歷程

系統不只儲存：

```text
balance = 500
```

而是保留完整的 Point Transaction Ledger。

例如：

```text
Point Account
    │
    ├── +100 Earn
    ├── +200 Bonus
    ├── -150 Redeem
    ├── -50  Adjustment
    └── ...
```

每筆交易包含：

- Tenant
- Customer
- Point Account
- Transaction Type
- Amount
- Balance Before
- Balance After
- Description
- Reference
- Operator
- Created At

例如：

```text
Balance Before : 500
Transaction    : -150
Balance After  : 350
Type           : REDEEM
```

因此系統可以回答：

> **「這個會員為什麼現在有 350 點？」**

而不是只能知道：

> 「目前餘額是 350。」

### Ledger 的價值

```text
Point Account
     │
     └── Current Balance

Point Transaction
     │
     ├── Earn
     ├── Redeem
     ├── Adjust
     ├── Refund
     └── Expire
```

Point Account 負責目前狀態。

Point Transaction 負責歷史與稽核。

---

# 04｜Point Lot / FIFO 點數到期

當點數具有不同有效期限時，單純使用：

```text
customer.points
```

無法知道每一批點數什麼時候到期。

因此後續可將點數拆分成不同 Point Lot：

```text
Point Lots

Lot A
100 points
expires: 2026-10-01

Lot B
200 points
expires: 2026-12-01

Lot C
300 points
expires: 2027-01-01
```

當會員兌換：

```text
Redeem = 150
```

系統依照最早到期優先：

```text
Lot A
100 → 0

Lot B
200 → 150
```

核心排序：

```sql
ORDER BY expired_at ASC
```

### 為什麼需要 Point Lot？

因為：

```text
Balance = 600
```

本身無法回答：

> 哪 100 點快到期？

Point Lot 則可以：

```text
Balance
   │
   ├── Lot A → 100 → 2026-10-01
   ├── Lot B → 200 → 2026-12-01
   └── Lot C → 300 → 2027-01-01
```

大量過期資料可以再透過 Batch Job / Queue 分批處理，避免一次掃描大量資料造成資料庫壓力。

> **實作狀態：規劃／後續實作。**

---

# 05｜Event-Driven Architecture

點數交易成功後，不應讓核心交易等待所有附加工作完成。

例如：

```text
Point Transaction
       │
       ▼
PointTransacted
       │
       ├── Notification
       ├── Customer Tier
       ├── Analytics
       └── Audit
```

核心交易：

```text
扣點
 ↓
更新 Balance
 ↓
建立 Point Transaction
 ↓
Commit
```

非核心工作：

```text
Notification
Analytics
Customer Tier
External Integration
```

則交由 Queue 非同步處理。

### 目的

降低主要 API Request 的處理時間，同時避免：

```text
Notification Service
       ↓
       ✗
       ↓
Point Transaction
       ↓
       ✗
```

讓非核心服務故障直接影響核心點數交易。

核心原則：

> **核心資料交易與非核心副作用分離。**

> **實作狀態：規劃／後續實作。**

---

# 06｜Outbox Pattern

單純：

```text
DB Transaction
      ↓
Dispatch Event
```

仍然存在一致性風險。

例如：

```text
DB Commit
   ✓

Event Dispatch
   ✗
```

此時：

```text
Point Transaction = 成功
Event = 遺失
```

因此可以使用 Outbox Pattern。

```text
┌────────────────────────────┐
│       DB Transaction       │
│                            │
│ Point Transaction         │
│ Point Account              │
│ Outbox Event               │
│                            │
└─────────────┬──────────────┘
              │
            Commit
              │
              ▼
        Outbox Worker
              │
              ▼
           Queue
              │
       ┌──────┼──────┐
       ▼      ▼      ▼
   Notify    Tier  Analytics
```

Point Transaction 與 Outbox Event 在同一個 Database Transaction 中寫入。

因此：

```text
Business Data
      +
Pending Event
```

具有相同的 Commit 邊界。

後續 Worker 再負責將 Outbox Event 發送至 Queue 或其他外部系統。

> **實作狀態：規劃／後續實作。**

---

# 07｜Tenant-Aware Rate Limiting

多租戶 SaaS 系統不能讓所有 Tenant 共用完全相同的資源限制。

例如：

```text
Enterprise
1000 requests / minute

Standard
300 requests / minute

Basic
100 requests / minute
```

Request 流程：

```text
Request
   │
   ▼
JWT Authentication
   │
   ▼
Tenant Context
   │
   ▼
Tenant Plan
   │
   ├── Enterprise → 1000/min
   ├── Standard   → 300/min
   └── Basic      → 100/min
```

目的在於避免單一 Tenant 產生大量流量，進而影響其他 Tenant。

這類問題通常稱為：

> **Noisy Neighbor Problem**

Tenant-aware Rate Limiting 將資源限制從：

```text
Global Limit
```

提升為：

```text
Tenant-aware Limit
```

> **實作狀態：規劃／後續實作。**

---

# 🏗 系統架構

整體目標架構：

```text
                         Load Balancer
                              │
                 ┌────────────┼────────────┐
                 ▼            ▼            ▼
              App #1       App #2       App #3
                 │            │            │
                 └────────────┼────────────┘
                              │
                         Redis Cluster
                              │
                 ┌────────────┼────────────┐
                 │                         │
          Distributed Lock            Idempotency
                 │                         │
                 └────────────┬────────────┘
                              ▼
                           MySQL 8
                              │
                    ┌─────────┴─────────┐
                    │                   │
               Transaction         Row-Level Lock
                    │                   │
                    └─────────┬─────────┘
                              ▼
                        Point Account
                              │
                              ▼
                       Point Transaction
                              │
                              ▼
                           Outbox
                              │
                              ▼
                            Queue
                              │
                   ┌──────────┼──────────┐
                   ▼          ▼          ▼
               Notify       Tier     Analytics
```

---

# 🔐 Multi-Tenant Architecture

本系統採用 Shared Database / Shared Schema 的多租戶架構。

主要資料透過：

```text
tenant_id
```

進行 Tenant 隔離。

概念：

```text
Tenant A
 ├── Customer
 ├── Point Account
 └── Point Transaction

Tenant B
 ├── Customer
 ├── Point Account
 └── Point Transaction
```

所有 Tenant-aware 資料都必須確保：

```text
Tenant A
   ✗
   ↓
Tenant B Data
```

無法被跨租戶存取。

Tenant Isolation 不只是 API 層判斷，也必須考慮：

- Model Query
- Relationship
- Service
- Transaction
- Background Job
- Queue Worker
- Cache Key
- Distributed Lock Key

---

# 🔑 Authentication

API 使用 JWT Authentication。

```text
Client
  │
  ▼
JWT
  │
  ▼
Authentication
  │
  ▼
Tenant Context
  │
  ▼
Authorization
  │
  ▼
Business Service
```

API 與 Filament Admin 的 Authentication Context 分離：

```text
API
 └── JWT

Admin Panel
 └── Web Session
```

避免 API Authentication 與後台管理登入機制互相耦合。

---

# 🛡 Authorization

系統具備角色與權限控制。

主要角色：

```text
super_admin
tenant_admin
tenant_staff
```

概念：

```text
Authentication
      ↓
Who are you?

Authorization
      ↓
What can you do?

Tenant Isolation
      ↓
Which tenant data can you access?
```

三者分別處理：

```text
Authentication
→ 身份驗證

Authorization
→ 操作權限

Tenant Isolation
→ 資料範圍
```

---

# 📊 Point Transaction Flow

目前核心點數交易流程：

```text
API Request
     │
     ▼
PointService
     │
     ▼
Validate Amount
     │
     ▼
Redis Distributed Lock
     │
     ▼
DB Transaction
     │
     ▼
Get Point Account
     │
     ▼
SELECT FOR UPDATE
     │
     ▼
Validate Balance
     │
     ▼
Update Point Account
     │
     ▼
Create Point Transaction
     │
     ▼
Commit
     │
     ▼
Return Transaction
```

支援的核心操作：

```text
Earn
Redeem
Adjust
Refund
Expire
```

---

# 🧾 Point Transaction Model

Point Transaction 使用 Ledger 思維保存點數異動。

基本資料：

```text
tenant_id
customer_id
point_account_id
type
amount
balance_before
balance_after
description
reference
created_by
created_at
```

交易資料的核心目的：

```text
Current State
     +
Historical Record
     +
Auditability
```

---

# ⚡ Concurrency Design

本系統不只使用單一 Lock。

而是：

```text
Application Layer
        │
        ▼
Redis Distributed Lock
        │
        ▼
Database Layer
        │
        ▼
Transaction
        │
        ▼
SELECT FOR UPDATE
```

兩者責任不同：

| 機制                   | 主要目的                             |
| ---------------------- | ------------------------------------ |
| Redis Distributed Lock | 多 Application Instance 間的競爭控制 |
| DB Transaction         | 保證資料操作 Atomicity               |
| `SELECT FOR UPDATE`    | 保護資料庫 Row-Level Consistency     |
| Unique Constraint      | 防止資料重複                         |

因此不把 Redis Lock 當成 Database Transaction 的替代品。

---

# 🧪 Concurrency Testing

高併發系統不能只測：

```text
Request A → Success
```

還需要測：

```text
Request A
Request B
Request C
...
Request N
```

同時操作相同 Point Account 時是否仍然正確。

核心測試目標：

### Concurrent Redeem

```text
Initial Balance = 100

10 concurrent requests
redeem(20)
```

預期：

```text
5 success
5 failed

Final Balance = 0

Balance < 0
    never

Lost Update
    never
```

### Concurrent Earn

```text
Initial Balance = 0

100 concurrent requests
earn(10)
```

預期：

```text
Final Balance = 1000
```

並確認：

```text
Point Transactions = 100
```

### Concurrent Account Creation

同一 Customer 同時進行第一次點數操作。

預期：

```text
Point Account = 1
```

不得產生 duplicate account。

---

# 🧱 Data Consistency

核心點數操作必須滿足：

```text
Point Account Update
        +
Point Transaction Create
```

必須在同一個 Database Transaction 中完成。

成功：

```text
Account ✓
Transaction ✓
Commit ✓
```

失敗：

```text
Account ✗
Transaction ✗
Rollback ✓
```

避免：

```text
Balance 已扣除
        +
Transaction 沒建立
```

這種資料不一致狀態。

---

# 🧩 Technology Stack

| Technology    | Purpose                                       |
| ------------- | --------------------------------------------- |
| PHP 8.2+      | Backend Runtime                               |
| Laravel 12    | API / Application Framework                   |
| Filament 5.8  | Admin Panel                                   |
| Livewire 4.4  | Filament UI                                   |
| MySQL 8       | Relational Database                           |
| Redis         | Distributed Lock / Cache / Future Idempotency |
| JWT           | API Authentication                            |
| Laravel Queue | Asynchronous Processing                       |
| L5-Swagger    | API Documentation                             |

---

# 📁 Project Structure

```text
app/
├── Filament/
│   ├── Resources/
│   ├── Pages/
│   └── Widgets/
│
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   └── Requests/
│
├── Models/
│
├── Services/
│   └── Point/
│       └── PointService.php
│
├── Support/
│   └── Tenancy/
│
└── ...

config/
├── database.php
├── cache.php
├── queue.php
└── ...

database/
├── migrations/
├── seeders/
└── factories/

routes/
├── api.php
└── web.php
```

實際目錄與模組以目前專案程式碼為準。

---

# 📡 API Versioning

API 使用版本化路徑：

```text
/api/v1
```

例如：

```text
/api/v1/...
```

API 版本化的目的：

```text
v1
 │
 ├── Client A
 ├── Client B
 └── Mobile App
```

未來可以在不破壞既有 Client 的情況下增加：

```text
v2
```

---

# 📚 API Documentation

API 文件使用 Swagger / OpenAPI。

文件入口：

```text
/api/documentation
```

可透過 Swagger UI 查看：

- API Endpoint
- Request Parameters
- Authentication
- Response
- Error Response
- API Schema

---

# 🔄 API Error Handling

API 不應直接將內部 Exception 暴露給 Client。

概念：

```text
Business Exception
       │
       ▼
Exception Handler
       │
       ▼
Standard API Response
```

例如點數不足：

```json
{
    "success": false,
    "message": "點數餘額不足"
}
```

讓 API Client 不需要理解 Laravel 內部 Exception。

---

# 📈 Scalability Design

系統架構以水平擴充為目標：

```text
                Load Balancer
                     │
       ┌─────────────┼─────────────┐
       ▼             ▼             ▼
    App #1         App #2         App #3
       │             │             │
       └─────────────┼─────────────┘
                     │
                  Redis
                     │
                  MySQL
```

Application Server 不依賴 Local Memory 保存重要交易狀態。

因此可以：

```text
App #1
App #2
App #3
...
App #N
```

水平增加 Application Instance。

Redis 則負責跨 Application Instance 共用的：

- Distributed Lock
- Cache
- Future Idempotency Storage
- Queue-related Infrastructure

---

# ⚠️ Failure Scenarios

企業級系統必須考慮的不只是正常流程。

本專案設計時會考慮：

### Client Retry

```text
Request
   ↓
Server processing
   ↓
Network timeout
   ↓
Client retry
```

→ Idempotency

### Concurrent Requests

```text
Request A ─┐
Request B ─┤
Request C ─┤→ Same Point Account
Request D ─┘
```

→ Distributed Lock + Row-Level Lock

### Database Failure

```text
Update Account
      ↓
Create Transaction
      ↓
Database Error
```

→ DB Transaction Rollback

### Event Failure

```text
DB Commit ✓
Event ✗
```

→ Outbox Pattern

### Noisy Neighbor

```text
Tenant A
大量 Request
     ↓
影響 Tenant B
```

→ Tenant-aware Rate Limiting

---

# 🗺 Roadmap

本專案不是一次加入所有 Enterprise Pattern，而是逐階段強化。

## Phase 1 — Point Transaction Concurrency

**核心優先級：最高**

```text
✓ Redis Distributed Lock
✓ DB Transaction
✓ SELECT FOR UPDATE
✓ Balance Validation
✓ Point Ledger
✓ Concurrent Safety Tests
```

---

## Phase 2 — API Idempotency

```text
□ Idempotency-Key
□ Request Fingerprint
□ Duplicate Request Detection
□ Original Response Replay
□ Idempotency Expiration
```

目標：

> 同一個業務操作即使被 Client Retry，也不會重複扣點。

---

## Phase 3 — Point Lot / FIFO

```text
□ Point Lot
□ Expiration Date
□ FIFO Consumption
□ Partial Lot Consumption
□ Batch Expiration
□ Expiration Queue
```

目標：

> 正確處理不同批次、不同到期時間的點數。

---

## Phase 4 — Domain Events / Queue

```text
□ PointTransacted Event
□ Queue Job
□ Notification
□ Customer Tier Update
□ Analytics Processing
```

目標：

> 將核心交易與非核心工作解耦。

---

## Phase 5 — Outbox Pattern

```text
□ Outbox Events
□ Outbox Worker
□ Retry Mechanism
□ Failed Event Handling
□ Event Delivery Tracking
```

目標：

> 降低 Database Transaction 與 Event Delivery 之間的一致性風險。

---

## Phase 6 — Tenant-aware Rate Limiting

```text
□ Tenant Rate Limit
□ Plan-based Limit
□ Redis-based Counter
□ Noisy Neighbor Protection
```

目標：

> 避免單一 Tenant 的流量影響其他 Tenant。

---

# 🧠 Engineering Principles

本專案遵循以下原則：

### 1. Database 是最終資料一致性的依據

Redis Lock 是併發控制工具，不是資料庫 Transaction 的替代品。

```text
Redis Lock
     ↓
Concurrency Control

Database Transaction
     ↓
Data Consistency
```

### 2. Business Logic 集中於 Service Layer

例如點數交易：

```text
Controller
    ↓
PointService
    ↓
Model / Database
```

Controller 不直接處理：

```text
Balance Update
Ledger Creation
Concurrency Lock
```

避免 Business Logic 散落於 Controller。

### 3. 不為了架構而架構

只有在有明確問題時才導入：

```text
Redis Lock
Idempotency
Queue
Outbox
Rate Limiting
```

每一個 Pattern 都必須能回答：

> **「它解決了什麼實際問題？」**

### 4. 優先保證資料正確，再追求效能

點數系統：

```text
Correctness
    ↓
Consistency
    ↓
Concurrency Safety
    ↓
Performance
```

不能為了追求效能犧牲點數資料正確性。

---

# 🎯 Project Highlights

本專案最希望展示的能力：

```text
┌───────────────────────────────────────┐
│       Enterprise Loyalty API          │
├───────────────────────────────────────┤
│                                       │
│  Multi-Tenant Architecture            │
│                                       │
│  High Concurrency                     │
│                                       │
│  Distributed Lock                    │
│                                       │
│  Database Transaction                 │
│                                       │
│  Row-Level Lock                       │
│                                       │
│  Point Ledger                         │
│                                       │
│  Idempotent API                       │
│                                       │
│  FIFO Point Expiration                │
│                                       │
│  Event-Driven Architecture            │
│                                       │
│  Outbox Pattern                       │
│                                       │
│  Tenant-aware Rate Limiting           │
│                                       │
└───────────────────────────────────────┘
```

---

# 💡 Why This Project?

很多後端作品集可以完成：

```text
Login
CRUD
Role
Permission
API
Swagger
```

但真正進入 Production Environment 後，還會遇到：

```text
Concurrent Requests
       ↓
Race Condition

Network Retry
       ↓
Duplicate Request

Distributed Servers
       ↓
Shared State

Point Expiration
       ↓
Batch / FIFO

Async Processing
       ↓
Queue

Database / Event Failure
       ↓
Consistency Problem

Multiple Tenants
       ↓
Noisy Neighbor
```

因此本專案將這些問題作為主要工程練習。

---

# 🏁 Final Goal

最終希望將系統從：

```text
CRUD Application
```

逐步提升為：

```text
Enterprise-oriented Backend System
```

核心能力：

```text
Multi-Tenancy
      +
High Concurrency
      +
Data Consistency
      +
Idempotency
      +
Point Ledger
      +
FIFO Expiration
      +
Distributed Lock
      +
Event-Driven Architecture
      +
Outbox Pattern
      +
Tenant-aware Rate Limiting
```

最終目標不是堆疊技術名詞，而是讓每一個架構決策都能回答：

> **遇到什麼問題？**

> **為什麼選這個方案？**

> **如何保證正確？**

> **失敗時會發生什麼？**

> **如何透過測試證明設計有效？**

這也是本專案最核心的工程價值。
