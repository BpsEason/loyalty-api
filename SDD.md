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

| Component                | Technology           |
| ------------------------ | -------------------- |
| Language                 | PHP 8.2+             |
| Framework                | Laravel 12           |
| Admin Panel              | Filament 5.8         |
| Live UI                  | Livewire 4.4         |
| Database                 | MySQL 8              |
| Cache / Distributed Lock | Redis                |
| API Authentication       | JWT                  |
| Queue                    | Laravel Queue        |
| API Documentation        | L5-Swagger / OpenAPI |

實際版本應以專案 `composer.json` 與 runtime 為準。

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

# 16. Concurrency Control

目前 Point Service 使用多層一致性策略。

```text
Request
   │
   ▼
Redis Distributed Lock
   │
   ▼
Database Transaction
   │
   ▼
SELECT FOR UPDATE
   │
   ▼
Validate Balance
   │
   ▼
Update Account
   │
   ▼
Create Transaction
   │
   ▼
Commit
```

---

## 16.1 Redis Distributed Lock

Lock key 應針對 Customer / Tenant 的點數操作建立。

目的：

> 避免多個 Application Instance 同時修改同一 Customer 的點數。

---

## 16.2 Database Transaction

Point Account 更新與 Point Transaction 建立必須位於同一 Database Transaction。

如果 Transaction rollback：

```text
Point Account update
+
Point Transaction
```

都必須 rollback。

---

## 16.3 Row Lock

在 Transaction 中使用：

```text
SELECT ... FOR UPDATE
```

鎖定 Point Account。

目的：

> 即使存在 concurrent request，也不能讓多個 transaction 同時修改相同 row。

---

## 16.4 Database Constraint

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
Planned
```

Idempotency 尚未視為目前已完成能力，除非實際程式碼與測試已證明。

---

## 18.1 Problem

外部系統可能：

```text
Request
   ↓
Timeout
   ↓
Client Retry
   ↓
Same Request
```

如果 Point API 沒有 Idempotency：

```text
Earn 100
+
Retry
+
Earn 100
=
Earn 200
```

---

## 18.2 Proposed Design

Client：

```http
Idempotency-Key: unique-request-id
```

Server：

```text
Idempotency-Key
       ↓
Tenant
       ↓
Endpoint / Operation
       ↓
Stored Result
```

相同 Key 的相同 Business Request 應返回原始結果，而不是再次執行。

---

## 18.3 Priority

優先加入：

```text
Earn
Redeem
Refund
Adjust
```

這些會直接改變點數的 API。

---

# 19. Point Lot / FIFO Architecture

## Status

```text
Planned
```

目前 Point Account Balance 不應被描述成已具備完整 Point Lot / FIFO 到期能力。

---

## 19.1 Problem

假設：

```text
Lot A
100 points
expires 2026-12-01

Lot B
200 points
expires 2027-01-01
```

Customer：

```text
Balance = 300
```

如果只保存 Balance：

```text
300
```

無法知道哪些點數先到期。

---

## 19.2 Proposed Model

未來可建立：

```text
Customer
    │
    ▼
Point Account
    │
    ├── Point Lot A
    ├── Point Lot B
    └── Point Lot C
```

每個 Lot 保存：

* Original Amount
* Remaining Amount
* Earned At
* Expires At
* Source
* Reference

---

## 19.3 FIFO

Redeem / Expire 時：

```text
Oldest valid Point Lot
        ↓
Consume
        ↓
Next Point Lot
        ↓
Continue
```

目的：

> 優先消耗最早到期的點數。

---

# 20. Domain Events

## Status

```text
Planned
```

未來 Point Domain 可以發布 Domain Events。

例如：

```text
PointEarned
PointRedeemed
PointRefunded
PointAdjusted
PointExpired
```

---

## 20.1 Event Flow

```text
Point Service
      │
      ▼
Domain Event
      │
      ▼
Queue
      │
      ├── Notification
      ├── Webhook
      ├── Audit
      └── Analytics
```

核心 Point Transaction 不應依賴非核心的 asynchronous processing 才能完成。

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
Planned
```

當外部 Event 必須具備可靠投遞能力時，可採用 Outbox Pattern。

架構：

```text
                    Database Transaction
                           │
                ┌──────────┴──────────┐
                ▼                     ▼
          Point Account        Outbox Record
                │                     │
                └──────────┬──────────┘
                           ▼
                         Commit
                           │
                           ▼
                       Worker
                           │
                           ▼
                    External System
```

目的：

避免：

```text
Database Commit Success
+
Event Publish Failed
```

造成資料與外部事件不一致。

---

# 23. Webhook / External Integration

## Status

```text
Planned
```

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
```
