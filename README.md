# Multi-Tenant Loyalty & Point API Platform

## 1. Project Identity

本專案是一套：

**Multi-Tenant Loyalty & Point API Platform**

核心目標是提供可被 Website、Mobile App、POS、E-commerce、CRM 等外部系統整合的會員、點數、優惠券與獎勵交易平台。

本專案不是單純 CRUD 系統，核心工程關注：

- Multi-Tenant Data Isolation
- Transaction Consistency
- Concurrency Control
- Idempotency
- Point Ledger / FIFO
- Coupon Transaction Integrity
- Auditability
- API First
- Horizontal Scalability

---

# 2. Technology Stack

| Category           | Technology                  |
| ------------------ | --------------------------- |
| Language           | PHP 8.2+                    |
| Framework          | Laravel 12                  |
| Admin Panel        | Filament 5.8                |
| UI Runtime         | Livewire 4.4                |
| Database           | MySQL 8                     |
| Cache / Lock       | Redis                       |
| Authentication     | JWT (API) / Session (Admin) |
| Queue              | Laravel Queue               |
| API Documentation  | L5-Swagger / OpenAPI        |
| Dependency Manager | Composer                    |

## Development Rule

除非使用者明確要求，AI 不得：

- 更換 Framework
- 更換 Database
- 更換 Authentication Strategy
- 更換 Multi-Tenant Strategy
- 引入 Microservices
- 新增不必要的 Repository / DTO / Service / Interface
- 重構現有架構

---

# 3. Source of Truth

AI 必須依照以下優先順序判斷系統行為：

```text
1. Actual Laravel Code
2. Database Schema / Migration
3. Automated Tests
4. ADR
5. Project Specification
6. README / Documentation
7. AI assumption
```

## Critical Rule

**Documentation 不可以凌駕於實際程式碼。**

如果文件描述：

```text
X 功能已完成
```

但實際程式碼沒有實作，AI 必須視為：

```text
Implementation not verified
```

而不是自行補齊不存在的實作。

如果文件與程式碼不一致：

```text
不要猜
不要自行選一個
先指出不一致
```

---

# 4. Verification Rule

任何涉及現有功能的修改，AI 必須先確認：

```text
Route
→ Controller
→ FormRequest
→ Service
→ Model
→ Migration
→ Test
```

實際存在什麼，再決定如何修改。

不得根據：

- class name
- method name
- README
- ADR
- 常見 Laravel 寫法
- 自己推測的 domain behavior

直接假設不存在的 implementation。

---

# 5. Architecture

本專案採用：

**Modular Monolith**

核心架構：

```text
External Systems
        ↓
API Routes
        ↓
Middleware
        ↓
Controller
        ↓
FormRequest
        ↓
Service
        ↓
Model
        ↓
MySQL
```

Redis 用於：

- Distributed Lock
- Cache

Queue 用於：

- 非同步工作
- 批次工作
- 不需要同步交易結果的背景處理

---

# 6. Business Logic Boundary

核心業務邏輯必須集中於 Service Layer。

例如：

```text
PointService
RewardService
CouponService
```

Controller 不應自行實作核心交易邏輯。

API、Filament、Queue、Command 如果執行相同業務操作，應共用相同 Service。

## Rule

不要為了「架構漂亮」建立第二套 business logic。

---

# 7. Multi-Tenancy

本專案採用：

**Shared Database / Shared Tables**

所有 Tenant-owned data 使用：

```text
tenant_id
```

進行租戶隔離。

核心隔離機制：

```text
Tenant Context
+
BelongsToTenant
+
Global Scope
+
Policies
+
Middleware
+
Database Constraints
```

## Tenant Rule

一般 Tenant User：

```text
只能存取自己的 tenant data
```

Super Admin：

```text
可以跨 tenant 查看及管理資料
```

## Critical Rule

任何涉及 Tenant-owned Model 的新增、修改、查詢，都必須先確認：

```text
tenant_id 從哪裡取得？
tenant_id 是否必填？
Super Admin 是否允許跨租戶？
Global Scope 是否會影響此查詢？
```

不得自行假設：

```php
auth()->user()->tenant_id
```

永遠存在。

---

# 8. Super Admin Rule

Super Admin 是特殊角色。

Super Admin：

```text
tenant_id 可以為 NULL
```

因此：

```text
不要假設 auth()->user()->tenant_id 一定存在
```

如果 Super Admin 建立 Tenant-owned record：

```text
必須明確取得目標 tenant
```

不得：

```php
$model->tenant_id = auth()->user()->tenant_id;
```

然後期待 Super Admin 自動產生 tenant_id。

## Required Behavior

如果操作需要指定 Tenant：

```text
Super Admin → 必須有明確 tenant context
Tenant User → 使用自己的 tenant context
```

如果目前 UI / API 沒有提供 tenant context：

```text
先指出問題
不要自行猜測應該使用哪個 tenant
```

---

# 9. Transaction Consistency

點數交易的核心不變量：

```text
PointAccount.balance >= 0
```

所有會修改點數餘額的操作必須：

```text
DB Transaction
+
PointAccount Row Lock
+
Balance Validation
+
PointTransaction Record
```

核心流程：

```text
Request
 ↓
Idempotency
 ↓
Distributed Lock (if required)
 ↓
DB Transaction
 ↓
lockForUpdate()
 ↓
Validate
 ↓
Update Balance
 ↓
Create Transaction
 ↓
Commit
```

---

# 10. Concurrency Control

本系統使用：

```text
Redis Distributed Lock
+
Database Row Lock
+
Database Transaction
+
Transaction Retry
```

用途必須區分清楚。

### Redis Lock

主要用於：

```text
跨 Application Instance 的同步
```

### Database Row Lock

主要用於：

```text
同一資料列的交易序列化
```

### DB Transaction

主要用於：

```text
保證多個資料修改的原子性
```

### Transaction Retry

主要用於：

```text
Deadlock Recovery
```

不要將上述機制描述成完全相同的功能。

---

# 11. Idempotency

Idempotency 的唯一權威來源：

**Database**

Redis 只能：

```text
Cache completed response
```

不能作為核心 Idempotency State Store。

Database unique constraint：

```text
tenant_id + idempotency_key
```

狀態：

```text
processing
completed
failed
```

AI 修改 Idempotency 相關程式時，不得把 Redis 改成主要狀態來源。

---

# 12. Point Ledger

PointTransaction 是點數異動的交易紀錄。

PointLot 用於：

```text
來源追蹤
有效期限
FIFO 消耗
```

PointLot：

```text
original_points
remaining_points
earned_at
expired_at
origin_transaction_id
```

核心規則：

```text
earn
→ create PointLot

redeem
→ FIFO consume PointLot

adjust+
→ create PointLot

adjust-
→ FIFO consume PointLot

refund
→ create refund PointLot

expire
→ consume expired PointLot
```

---

# 13. Coupon Domain

核心模型：

```text
CouponTemplate
    ↓
UserCoupon
    ↓
CouponRedemption
```

### CouponTemplate

定義優惠券規則。

### UserCoupon

代表會員實際持有的優惠券。

### CouponRedemption

代表優惠券實際核銷紀錄。

---

# 14. Mixed Payment

混合支付：

```text
Coupon
+
Points
```

必須在同一個 DB transaction 中完成。

核心原則：

```text
全部成功
OR
全部 rollback
```

不得產生：

```text
Coupon 已使用
+
Points 扣除失敗
```

之類的部分成功狀態。

---

# 15. Audit

Audit 必須記錄：

```text
tenant
user
action
auditable model
old values
new values
created_at
```

只有明確標記為 Auditable 的 Model 才會產生 Audit。

不要假設所有 Model 都會產生 Audit。

---

# 16. Database Rules

Database Constraint 是資料一致性的最後防線。

重要 Unique Constraint 必須考慮 Tenant Scope。

例如：

```text
tenant_id + code
tenant_id + customer_id
tenant_id + idempotency_key
```

但是：

**不要看到 tenant_id 就自行新增複合 unique。**

必須確認：

```text
Business Rule
+
Existing Query
+
Existing Migration
+
Existing Tests
```

---

# 17. Indexing Rules

Index 必須根據實際 Query Pattern 建立。

AI 新增 Index 前必須確認：

```text
1. 實際查詢是否存在
2. WHERE 條件
3. ORDER BY
4. JOIN
5. 查詢頻率
6. 現有 Index 是否已涵蓋
```

禁止：

```text
「未來可能會用到」
```

作為唯一新增 Index 的理由。

---

# 18. Performance Claims

以下內容只能在實際測量後宣稱：

```text
High Performance
Production Ready
Supports 300K Customers
Supports 30M Transactions
P95 < X ms
TPS = X
```

如果尚未測量，必須寫：

```text
Not Measured
Planning Target
Load Test Target
```

不得把 Capacity Planning Target 當成實際 Capacity。

---

# 19. Testing Rules

測試狀態必須區分：

```text
Implemented
In Progress
Not Implemented
Not Measured
```

不要因為存在 Unit Test 就宣稱：

```text
Production concurrency verified
```

尤其以下項目必須分開：

```text
Unit Test
Feature Test
Concurrency Test
Multi-Process Test
Load Test
Benchmark
```

---

# 20. Failure Handling

每個 Failure Scenario 必須對應實際 implementation。

格式：

```text
Failure:
Protection:
Implementation:
Test:
Status:
```

例如：

```text
Failure:
Double Spend

Protection:
PointAccount lockForUpdate()

Implementation:
PointService

Test:
Concurrent redeem test

Status:
Implemented
```

如果沒有測試：

```text
Test:
Not Implemented
```

不要寫成：

```text
Fully Protected
```

---

# 21. API Documentation Rules

API documentation 必須以實際 Route 為準。

每個 Endpoint 必須確認：

```text
Method
Path
Authentication
Tenant Requirement
Idempotency Requirement
Controller
```

不要只根據 README 宣稱 API 存在。

如果 README 有 endpoint，但 Route 不存在：

```text
Documentation mismatch
```

不得自行新增 Route。

---

# 22. API Boundary

公開 API：

```text
/api/v1/*
```

Authentication：

```text
JWT
```

Admin：

```text
Filament Session Authentication
```

Campaign / CampaignReward 等後台管理功能是否提供 API：

```text
以實際 Route + ADR 為準
```

不得因為 Model 存在就假設必須有 API。

---

# 23. Documentation Rules

文件中的每一項功能必須標示狀態：

```text
Implemented
Partial
In Progress
Planned
Not Implemented
Not Measured
```

避免使用模糊描述：

```text
支援
完整
穩定
高效能
Production Ready
```

除非有實際程式碼、測試或 benchmark 支持。

---

# 24. AI Modification Protocol

任何 AI 修改程式碼前，必須依序執行：

```text
Step 1
確認需求

Step 2
搜尋現有實作

Step 3
確認 Route / Controller / Service / Model / Migration / Test

Step 4
找出真正造成問題的程式碼

Step 5
提出最小必要修改

Step 6
修改

Step 7
執行相關測試

Step 8
回報修改結果
```

---

# 25. No Guessing Rule

AI **禁止自行猜測**以下內容：

```text
tenant_id 的來源
Super Admin 的 tenant behavior
Business Rule
Coupon claim limit
Point expiration behavior
Refund semantics
Idempotency semantics
Lock ordering
API endpoint
Database relationship
Model relationship
Existing index purpose
Permission behavior
Filament tenancy behavior
```

如果資訊不足：

```text
先檢查 Code
```

如果 Code 仍無法確認：

```text
列出缺少的資訊
```

不要自行設計新的行為。

---

# 26. Minimal Change Rule

修改需求時：

```text
只修改必要檔案
只修改必要程式碼
保持既有命名
保持既有架構
保持既有 API contract
```

不要因為發現其他「可以改善」的地方而順手：

```text
重構
改名
抽 Service
改 Repository
改 Schema
改 API
改 Migration
改 Response Format
```

除非需求明確要求。

---

# 27. Code Style Consistency

AI 必須優先沿用現有專案寫法。

例如：

```text
Existing Service → 繼續使用 Service
Existing FormRequest → 繼續使用 FormRequest
Existing Resource → 繼續使用 Resource
Existing Trait → 優先使用既有 Trait
Existing API Response → 使用既有 ApiResponse
```

不要同一個專案同時建立多種風格：

```text
Service + Action + Handler + UseCase
```

除非現有架構已經明確採用。

---

# 28. Change Report Format

每次修改完成後，AI 必須使用以下格式：

```text
## Modified

- File:
- Change:
- Reason:

## Verification

- Test:
- Result:

## Not Changed

- Item:
- Reason:

## Remaining Issue

- None

或

- File:
- Issue:
- Reason:
```

不要輸出與此次修改無關的大量架構建議。

---

# 29. Documentation Consistency

README、ADR、Migration、Model、Service、Controller、Test 如果描述同一功能：

```text
Code = Implementation Source
ADR = Architecture Decision
README = Project Overview
Test = Verification Evidence
```

各文件職責：

```text
README
→ 說明「系統是什麼」

ADR
→ 說明「為什麼這樣設計」

Code
→ 定義「實際怎麼運作」

Test
→ 證明「目前驗證到什麼程度」

Benchmark
→ 證明「實際效能是多少」
```

不要把所有內容全部塞進 README。

---

# 30. Current Verification Status

以下項目如果尚未實際驗證，必須保持：

```text
EXPLAIN ANALYZE
→ Not Measured

Load Test
→ Not Measured

Real Multi-Process Concurrency
→ Not Implemented

Point Expiration Scheduler
→ In Progress / Not Implemented

Disaster Recovery
→ Verification Required
```

實際狀態必須以 Code / Test 為準。

---

# 31. Final Development Principle

本專案遵循：

```text
Correctness First
+
Tenant Isolation First
+
Database as Source of Truth
+
Explicit Business Rules
+
Minimal Change
+
Evidence Before Claims
+
No Guessing
```

最重要的 AI 開發規則：

> **先確認現有程式碼，再修改。**
>
> **不知道就查，不確定就指出，不可以自行猜測。**
>
> **文件可以描述設計，但只有實際 Code + Test 才能證明功能存在。**
