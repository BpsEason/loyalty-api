# Multi-Tenant Loyalty & Point API Platform

一套基於 **Laravel** 開發、以 **API First** 為核心的多租戶會員點數平台。

這個專案一開始可以很簡單：

```text
Customer
   ↓
Point Balance
```

但當系統真的開始被 Website、Mobile App、POS、CRM 或其他第三方服務同時呼叫後，真正困難的就不再是 CRUD。

問題會變成：

- 不同租戶的資料怎麼隔離？
- 同一個會員同時被多個請求扣點，怎麼避免 Race Condition？
- Client Timeout 後重新送出 Request，怎麼避免重複扣點？
- 點數餘額與交易紀錄怎麼保持一致？
- 發生問題時，怎麼追查每一筆點數異動？
- 核心交易完成後，怎麼可靠地通知其他系統？

因此，這個專案的重點不是「把 API 做出來」，而是逐步處理這些 **真實系統在高併發與多系統整合下會遇到的問題**。

---

## 🎯 Project Goal

這套系統希望成為一個可以被不同產品重複整合的 **Loyalty API Platform**。

例如：

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
                  │     CRM      │
                  └──────┬───────┘
                         │
                         ▼
              ┌─────────────────────┐
              │   Loyalty API       │
              │      /api/v1        │
              └──────────┬──────────┘
                         │
             ┌───────────┼───────────┐
             ▼           ▼           ▼
           MySQL       Redis       Queue
```

API 與前端 UI 分離，讓點數核心邏輯不依賴特定產品。

---

# 🧩 Core Problems

本專案目前主要圍繞四個問題發展。

### 1. Multi-Tenant Isolation

系統採用：

**Shared Database / Shared Tables**

透過 `tenant_id` 區分不同租戶。

核心原則：

```text
Tenant A
   │
   ├── Customers
   ├── Point Accounts
   └── Transactions

Tenant B
   │
   ├── Customers
   ├── Point Accounts
   └── Transactions
```

租戶隔離不是只依賴 Controller 判斷，而是由多個層級共同處理：

```text
Authentication
      ↓
Tenant Context
      ↓
Authorization
      ↓
Model Scope
      ↓
Database Constraints
```

同時避免直接信任 Client 傳入的 `tenant_id`。

---

# ⚡ Point System & Concurrency

點數是這套系統最需要保護資料一致性的部分。

目前支援：

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
Lock
   ↓
DB Transaction
   ↓
Lock Point Account Row
   ↓
Validate Balance
   ↓
Update Balance
   ↓
Create Transaction Ledger
```

---

## 🔒 Concurrent Redeem

假設會員目前有：

```text
Balance = 100
```

同一時間收到 10 個：

```text
Redeem 20
```

正確結果應該是：

```text
5 requests  → Success
5 requests  → Failed

Final Balance = 0
```

而不是：

```text
100
 ↓
Request A reads 100
Request B reads 100
Request C reads 100
...
 ↓
Concurrent Update
 ↓
Incorrect Balance
```

目前的 Point Service 使用多層保護：

### Redis Distributed Lock

限制同一會員的點數交易在短時間內互相競爭。

```text
Customer
   ↓
Redis Distributed Lock
```

這一層主要處理多 Application Instance 下的跨程序同步。

### Database Transaction

Balance 更新與 Ledger 寫入放在同一個 Transaction：

```text
BEGIN TRANSACTION

Update Point Account
        +
Create Point Transaction

COMMIT
```

任何一步失敗，都應該 Rollback。

### Database Row Lock

在 Transaction 中鎖定 Point Account：

```sql
SELECT ...
FOR UPDATE
```

讓資料庫本身成為最後一道一致性防線。

因此目前的核心設計是：

```text
Redis Lock
     ↓
DB Transaction
     ↓
SELECT ... FOR UPDATE
     ↓
Validate
     ↓
Update
     ↓
Ledger
```

這裡的重點不是單純「用了 Redis」，而是：

> **Redis 負責跨 Instance 的同步，Database Transaction 與 Row Lock 負責最終的資料一致性。**

---

# 📒 Point Transaction Ledger

點數不能只依賴目前的：

```text
balance = 100
```

因為當資料發生異常時，只看 Balance 很難回答：

> 「這 100 點到底是怎麼來的？」

因此每一次點數異動都建立 Transaction Ledger。

例如：

```text
Balance: 100

       ↓ Redeem 30

Transaction
────────────────────
Type:          REDEEM
Amount:        -30
Before:        100
After:          70
Customer:        123
Created At:   ...
────────────────────

Balance: 70
```

Ledger 提供：

- Transaction Traceability
- Auditability
- Balance Change History
- 問題追查依據

Point Account 負責目前狀態：

```text
Point Account
    ↓
Current Balance
```

Point Transaction 負責異動歷史：

```text
Point Transaction
    ↓
What happened?
```

兩者各自負責不同角色。

---

# 🏗️ Architecture

目前採用：

## Modular Monolith

沒有一開始就為了「看起來像大型系統」而拆成 Microservices。

目前更重要的是先建立清楚的模組與責任邊界：

```text
                    API
                     │
                     ▼
              ┌─────────────┐
              │ Middleware  │
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

Controller 不負責點數交易規則。

核心業務邏輯集中在 Service Layer。

例如：

```text
PointController
      ↓
PointService
      ↓
PointAccount
      +
PointTransaction
```

這樣未來即使 API、Queue 或其他入口需要執行點數交易，也不需要重新複製核心邏輯。

---

# 🛠️ Technology Stack

| Category          | Technology           |
| ----------------- | -------------------- |
| Language          | PHP 8.2+             |
| Framework         | Laravel 12           |
| Admin Panel       | Filament 5.8         |
| UI Runtime        | Livewire 4.4         |
| Database          | MySQL 8              |
| Cache / Lock      | Redis                |
| Authentication    | JWT                  |
| Queue             | Laravel Queue        |
| API Documentation | L5-Swagger / OpenAPI |

---

# 🔐 Authentication

API 與後台使用不同的 Authentication Context。

```text
API Client
    ↓
JWT
    ↓
/api/v1/*
```

後台：

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

# 🌐 API Design

API 採用版本化：

```text
/api/v1
```

主要 API 領域包含：

```text
Authentication
Customers
Points
Tenants
Users
```

API 設計以外部系統可以直接使用為前提，而不是針對單一前端畫面設計。

---

# 📖 API Documentation

Swagger / OpenAPI 文件：

```text
/api/documentation
```

開發 API 時，文件會同步描述 Request、Response 與 Endpoint。

---

# 🖥️ Admin Panel

後台使用：

```text
Filament 5.8
+
Livewire 4.4
```

主要用於：

- Tenant Management
- Customer Management
- Point Account Management
- Point Transaction Management
- User / Permission Management

Admin Panel 的角色是管理與操作介面。

點數核心規則仍由 Service Layer 負責。

---

# 🚧 Reliability Roadmap

這個專案不追求一次把所有企業級 Pattern 塞進去，而是按照實際問題逐步演進。

| Phase   | Focus                                                                                    | Status          |
| ------- | ---------------------------------------------------------------------------------------- | --------------- |
| Phase 1 | Multi-Tenant / JWT / Customer / Point Account / Ledger / Base API                        | **Implemented** |
| Phase 2 | Redis Distributed Lock / DB Row Lock / Transaction / Idempotency-Key / Concurrency Tests | **In Progress** |
| Phase 3 | Point Lot / FIFO / Point Expiration / Batch Job                                          | **Planned**     |
| Phase 4 | Domain Events / Queue / Event-Driven Processing                                          | **Planned**     |
| Phase 5 | Outbox Pattern / Webhook / Retry / Signature Verification                                | **Planned**     |
| Phase 6 | Tenant-aware Rate Limiting / Observability / Scalability                                 | **Planned**     |

其中一個重要原則：

> **Implemented 才代表目前 Code 已經具備。Planned 只代表設計方向，不代表功能已經完成。**

---

# 🔁 Idempotency

下一階段會處理 API Retry 問題。

例如 Client：

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

如果沒有 Idempotency：

```text
Redeem 100
     +
Redeem 100
```

同一筆操作可能被執行兩次。

預計透過：

```text
Idempotency-Key
```

讓同一個業務請求可以安全 Retry。

例如：

```http
Idempotency-Key: 8f3a...
```

這會與目前的 Concurrency Control 分開處理：

```text
Concurrency
→ 防止同時交易造成 Race Condition

Idempotency
→ 防止同一個 Request 被重複執行
```

兩者解決的是不同問題。

---

# 🧮 Point Lifecycle

下一階段會把目前單純的 Balance 模型進一步延伸成 Point Lot。

概念：

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

Redeem 時：

```text
Available Lots
      ↓
FIFO
      ↓
Lot A
      ↓
Lot B
```

讓系統可以進一步處理：

- 不同批次點數
- 點數到期日
- FIFO 扣點
- 批次過期
- 大量會員點數過期 Job

---

# 📡 Event-Driven Architecture

當核心交易越來越複雜後，不希望：

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

全部塞在同一個 HTTP Request。

未來會逐步導入：

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

核心交易完成後，再由非同步 Worker 處理其他工作。

---

# 📦 Outbox Pattern

如果未來使用 Event / Queue / Webhook，還會遇到另一個問題：

```text
DB Transaction
      ↓
COMMIT
      ↓
Queue publish failed
```

資料已經成功寫入，但 Event 沒有送出去。

因此後續會評估 Outbox Pattern：

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

讓「資料更新」與「待發送事件」可以在同一個 Database Transaction 中建立。

---

# 🚦 Tenant-aware Rate Limiting

多租戶系統除了資料隔離，也需要避免某一個 Tenant 大量消耗系統資源。

未來會加入 Tenant-aware Rate Limiting：

```text
Tenant A
1000 req/min
      ↓
Allowed

Tenant B
100000 req/min
      ↓
Rate Limited
```

重點不是單純限制 IP，而是讓不同 Tenant 可以有不同的資源使用策略。

---

# 🧪 Testing Strategy

這個專案的測試重點不只放在 CRUD。

尤其會驗證：

### Point Correctness

```text
Earn
Redeem
Refund
Adjust
Expire
```

### Transaction Safety

```text
Update Balance
+
Create Ledger
```

其中任何一步失敗，都應該 Rollback。

### Concurrency

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

### Tenant Isolation

驗證：

```text
Tenant A
   X
Tenant B Data
```

任何跨租戶資料存取都應該被阻止。

---

# 🧠 Development Philosophy

## 1. Correctness First

點數系統最重要的不是快，而是不能算錯。

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

## 2. API First

API 的使用者不是只有自己的前端。

設計時會假設：

```text
Website
Mobile App
POS
CRM
Third-party System
```

都可能成為 Client。

---

## 3. Minimal Change

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

不因為一個 Bug 就重寫整個架構。

---

## 4. Do Not Guess

這是這個專案非常重要的開發原則。

不根據：

```text
「應該是這樣」
「Laravel 通常會這樣」
「我猜問題在這裡」
```

直接修改 Code。

而是：

```text
Inspect actual code
        ↓
Trace actual execution
        ↓
Verify actual behavior
        ↓
Modify
        ↓
Run tests
```

**Code、Database、Runtime、Logs 才是判斷依據。**

---

## 5. Documentation Must Match Reality

README 不應該比程式碼更先進。

```text
Implemented
→ Code 已存在並經過驗證

In Progress
→ 正在實作

Planned
→ 設計方向，尚未實作
```

因此這份 README 會隨實際開發進度更新，而不是先把所有架構都寫成「已完成」。

---

# 📁 Project Structure

目前專案以 Laravel 標準結構與業務責任分層：

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

實際目錄與模組以目前 Repository 為準，不額外假設不存在的資料夾或文件。

---

# 🚀 Development Direction

這個專案接下來的重點不是增加更多 CRUD，而是逐步把核心交易能力做深：

```text
                    Loyalty Platform
                           │
             ┌─────────────┼─────────────┐
             │             │             │
        Multi-Tenant   Point Engine   API Reliability
             │             │             │
             │             │             ├── Idempotency
             │             │             └── Rate Limit
             │             │
             │             ├── Concurrency
             │             ├── Ledger
             │             └── Point Lot
             │
             └── Tenant Isolation

                           ↓

                    Event / Queue
                           ↓
                    External Systems
```

最終希望建立的不是一個「功能很多的 CRUD 專案」，而是一個可以持續演進、能處理實際交易一致性問題，並且方便其他系統整合的 **Loyalty API Platform**。

---

## 📌 Project Status

目前專案已建立：

- Multi-Tenant 基礎架構
- JWT API Authentication
- Filament Admin Panel
- Versioned REST API
- Customer / Point Account / Point Transaction
- Point Ledger
- Redis Distributed Lock
- Database Transaction
- Database Row Lock
- Tenant Isolation
- API Documentation

接下來的核心工作：

```text
Concurrency
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

這些能力會以實際 Code、測試與驗證結果逐步加入，而不是只停留在架構圖上。
