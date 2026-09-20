# Multi-Tenant Transaction Processing Platform

一套專注於交易一致性、租戶隔離與可擴展性的會員忠誠度系統核心。本專案的工程重點在於處理真實世界的併發交易、資料一致性與多租戶架構挑戰，而非單純的 CRUD 應用程式。

---

# 1. Project Overview

**Multi-Tenant Loyalty & Point API Platform** 是一個以 API First 為核心開發的點數交易處理平台。不同於一般的會員系統，本專案專注於解決當多個系統（網站、手機App、POS、電商、CRM）同時存取同一會員點數時的複雜一致性問題。

系統的核心工程挑戰包括：

- 跨租戶的資料安全性與隔離性
- 高併發下的點數餘額一致性
- 用戶端重試機制下的冪等性保證
- 點數異動的完整追蹤與審計能力
- 多應用實例部署下的分散式一致性

---

# 2. Core Business Problems

本系統專注解決以下核心商業與技術問題：

1. **多租戶資料隔離**：不同企業租戶的客戶、點數、交易必須完全隔離，避免資料洩漏
2. **點數餘額一致性**：防止雙重扣點（Double Spend）或超發點數（Overissue）
3. **併發交易處理**：同一會員同時收到多個交易請求時的序列化作業
4. **重複請求防護**：用戶端網路超時重試時避免重複執行同一交易
5. **交易可追溯性**：每一筆點數異動都必須留下不可篡改的審計記錄
6. **系統擴展性**：隨著客戶與交易數量增長，系統能夠持續穩定運行

---

# 3. Architecture Overview

本專案採用 **Modular Monolith** 架構，在保持交易邊界簡單的同時，建立清晰的模組責任劃分。所有核心業務邏輯集中在 Service Layer，確保不論是 API 請求、後台操作或是排程任務都使用同一套一致性保證機制。

```text
外部系統
    ├─ Website
    ├─ Mobile App
    ├─ POS
    ├─ E-commerce
    └─ CRM
          │
          ▼
      API Gateway
          │
          ▼
   Middleware Stack
    (Auth/Tenant/Permission)
          │
          ▼
     Controllers
          │
          ▼
    FormRequests
          │
          ▼
      Services
    (Point/Reward/etc)
          │
          ▼
       Models
          │
          ▼
       MySQL
          │
     Redis (Cache/Lock)
```

---

# 4. Architecture Decisions

Detailed architecture decisions are documented under `/docs/adr`.

| ADR     | Decision                          | Status         |
| ------- | --------------------------------- | -------------- |
| ADR-001 | Modular Monolith                  | ✅ Implemented |
| ADR-002 | Shared Database Multi-Tenancy     | ✅ Implemented |
| ADR-003 | Redis + DB Transaction + Row Lock | ✅ Implemented |
| ADR-004 | JWT Authentication                | ✅ Implemented |
| ADR-005 | No Microservices Yet              | ✅ Implemented |

---

# 5. Multi-Tenant Isolation

本系統採用 **Shared Database / Shared Tables** 架構，透過 `tenant_id` 欄位區分所有租戶的資料。這是在當前系統規模下最合適的選擇，避免了維護多資料庫或多綱要的營運複雜性。

## 隔離機制堆疊

```text
應用層級保護
    ├─ TenantResolver：解析當前請求的租戶上下文
    ├─ TenantContext：儲存當前請求的租戶實例
    ├─ BelongsToTenant Trait：自動為所有模型套用全域租戶範圍
    ├─ Policies：每個資源的授權檢查
    └─ Middleware：請求進入時的租戶驗證
          │
資料庫層級保護
    ├─ 所有表的 tenant_id 外鍵約束
    └─ 唯一約束包含 tenant_id 防止跨租戶碰撞
```

## 核心風險

> **租戶隔離失敗不只是過濾問題，而是正確性與安全性問題**。缺少租戶範圍的查詢可能導致跨租戶資料洩漏，因此所有層級都必須持續驗證隔離機制的有效性。

---

# 6. Transaction Consistency

點數交易的一致性是本系統的核心設計目標。每一筆點數異動都必須保證：

- 餘額與交易明細永遠一致
- 餘額不會變為負數
- 同一筆交易不會被重複執行
- 所有異動都留下審計痕跡

## 交易流程

每筆點數交易都遵循嚴格的流程：

```text
API Request
    ↓
Controller 接收請求
    ↓
IdempotencyMiddleware 檢查重複請求
    ↓
PointService::executeInLock()
    ↓
Redis Lock 取得跨實例鎖 (block 最多 5 秒)
    ↓
DB::transaction() 開啟資料庫交易 (最多重試 3 次處理死鎖)
    ↓
PointAccount::lockForUpdate() 取得資料庫行鎖
    ↓
驗證租戶一致性與餘額合法性
    ↓
更新 PointAccount 餘額
    ↓
建立 PointTransaction 交易明細
    ↓
Commit 交易
    ↓
回傳交易結果給 Client
```

---

# 7. Concurrency Control

為了解決並發交易可能導致的 Lost Update 問題，本系統採用多層次的鎖定策略：

## 雙重鎖定架構

```text
Redis 分散式鎖
    → 解決：多個應用實例之間的同步問題
    → 防止：跨實例的併發請求同時操作同一客戶

資料庫行鎖 (SELECT ... FOR UPDATE)
    → 解決：同一資料庫連接下的併發修改問題
    → 防止：多個交易同時讀取和修改同一行資料

資料庫交易重試
    → 解決：短暫死鎖的自動恢復
    → DB::transaction($callback, 3) 自動重試死鎖的交易
```

## 經典並發場景

```text
初始餘額 = 100

請求 A → 兌換 80
請求 B → 兌換 80
```

如果沒有適當的鎖定，兩個請求都可能通過餘額檢查，導致最終餘額為 -60。本系統的鎖定機制確保只有一個請求能夠成功，另一個會因餘額不足而失敗。

---

# 8. Idempotency

冪等性保證同一個用戶端請求不論執行多少次，都只會對伺服器端狀態產生一次改變。這對於處理網路超時後的用戶端重試至關重要。

## 實作機制

- **Idempotency-Key Header**：用戶端在每個 POST 請求中攜帶唯一的冪等性金鑰
- **租戶隔離的快取鍵**：`idempotency:{tenantId}:{idempotencyKey}` 避免跨租戶金鑰碰撞
- **Redis 快取成功回應**：成功的交易結果會被快取 24 小時，重複的相同金鑰請求會直接返回快取結果
- **僅快取成功回應**：只有 HTTP 200/201 的回應會被快取，失敗的請求允許重試

## 適用的 API 端點

- `POST /api/v1/customers/{customer}/point-transactions`
- `POST /api/v1/customers/{customer}/points/redeem`

---

# 9. Failure Scenarios

系統針對各種失敗場景都有相應的保護機制，詳細的失敗分析請參考 `/docs/failure-analysis.md`。

主要的失敗場景包括：

1. **雙重扣點 (Double Spend)**：透過 Redis Lock + DB Row Lock 防護
2. **重複退款 (Duplicate Refund)**：透過業務邏輯檢查與參考關聯防護
3. **租戶資料洩漏 (Tenant Data Leakage)**：透過全域範圍 + 授權政策防護
4. **資料庫死鎖 (Deadlock)**：透過交易自動重試機制處理
5. **Redis 不可用 (Redis Unavailable)**：資料庫行鎖仍能提供基本的一致性保證
6. **網路分區 (Network Partition)**：等待鎖自動釋放後重試

---

# 10. Database Design

## 實體關係圖

```text
Tenant
   │
   ├── Users (系統使用者：Super Admin / Tenant Admin / Staff)
   ├── Customers (會員客戶)
   │      │
   │      └── PointAccount (每個客戶一個點數帳戶)
   │              │
   │              └── PointTransaction (所有點數交易明細)
   │
   └── Campaigns (行銷活動)
          │
          └── CampaignRewards (活動可兌換獎勵)
                  │
                  └── RewardGrants (實際發放的獎勵記錄)
```

所有上層實體都有 `tenant_id`，下層實體透過關聯繼承租戶隔離，配合模型的全域範圍確保跨租戶資料無法存取。

## 資料庫索引設計

索引設計與實際查詢模式緊密綁定：

| 表                 | 索引欄位                                    | 查詢模式                                                                | 用途                                   |
| ------------------ | ------------------------------------------- | ----------------------------------------------------------------------- | -------------------------------------- |
| point_transactions | `(tenant_id, point_account_id, created_at)` | `WHERE tenant_id = ? AND point_account_id = ? ORDER BY created_at DESC` | 查詢特定客戶的交易歷史，按時間倒序排列 |
| point_transactions | `(reference_type, reference_id)`            | 多態關聯查詢                                                            | 追蹤交易的關聯實體                     |
| point_accounts     | `(tenant_id, customer_id)`                  | UNIQUE 約束                                                             | 確保同一租戶下每個客戶只有一個點數帳戶 |

---

# 11. Performance & Query Optimization

## Query Performance Verification

### Verification Status

EXPLAIN ANALYZE benchmark:
**Not measured yet**

詳細的效能基準模板請參考 `/docs/performance/query-benchmarks.md`，記錄每個重要查詢在不同資料集大小下的執行效能。

## Deadlock Handling

系統可能發生死鎖的場景：

```text
交易 A → 先鎖定帳戶 1，再嘗試鎖定帳戶 2
交易 B → 先鎖定帳戶 2，再嘗試鎖定帳戶 1
```

這種不同的鎖定順序可能導致死鎖。本系統透過 Laravel 內建的交易重試機制處理這種狀況：`DB::transaction($callback, 3)` 會在死鎖發生時自動重試交易最多 3 次。

### 實作狀態

- ✅ 死鎖處理機制已實作
- ⚠️ 死鎖重現測試：尚未實作
- ⚠️ 死鎖效能基準：尚未測量

## Transaction Isolation

MySQL 的預設交易隔離級別為 **REPEATABLE READ**。本系統依賴 `SELECT ... FOR UPDATE` 來解決在 REPEATABLE READ 隔離級別下仍可能發生的並發修改問題。行鎖確保在同一交易內，對點數帳戶的讀取和修改是串行化的。

> `SELECT ... FOR UPDATE` 解決的是交易內對鎖定資料列的並發存取問題，它不能防止所有的競爭條件，必須與應用層級的分散式鎖配合使用。

---

# 12. Scalability & Capacity Planning

## Capacity Planning Target

```text
300,000 Customers
10M–30M Point Transactions
```

**狀態：Planning / Load Test Target**（此為系統設計目標，尚未在生產環境驗證）

## 真正的擴展挑戰

300K 客戶本身不是主要的技術挑戰，真正需要關注的是：

```text
客戶數量增長
        +
交易數量增長
        +
併發請求數量
        +
儀表盤聚合查詢
        +
匯入匯出操作
        +
資料庫連接數
        +
佇列處理吞吐量
```

其中，30M 點數交易記錄才是對資料庫查詢和儲存的真正壓力來源，需要考慮分庫分表、歷史資料歸檔等長期擴展策略。

## Load Testing

### 負載測試目標

```text
目標資料集
Customers: 300,000
Point Transactions: 10,000,000+

併發場景
Concurrent Redeem: 100 / 500 / 1,000

效能指標
P50 (延遲)
P95 (延遲)
P99 (延遲)
吞吐量 (TPS)
錯誤率
DB CPU 使用率
DB 連接數
鎖等待時間
死鎖發生次數
Redis 延遲
Queue 延遲
```

**Benchmark Status: Not measured yet**

---

# 13. Testing & Verification

系統的測試覆蓋分為以下幾個領域，每個領域的實作狀態：

| 分類            | 測試項目               | 狀態               |
| --------------- | ---------------------- | ------------------ |
| **Correctness** | 點數計算正確性         | ✅ Implemented     |
|                 | 餘額驗證邏輯           | ✅ Implemented     |
|                 | 退款邏輯               | ✅ Implemented     |
|                 | 點數過期邏輯           | 🚧 In Progress     |
|                 | 點數調整邏輯           | ✅ Implemented     |
| **Isolation**   | 租戶隔離測試           | ✅ Implemented     |
|                 | Super Admin 跨租戶存取 | ✅ Implemented     |
|                 | 租戶管理員權限         | ✅ Implemented     |
| **Concurrency** | 鎖定行為測試           | ✅ Implemented     |
|                 | 序列壓力測試           | ✅ Implemented     |
|                 | 真實多程序併發測試     | ❌ Not Implemented |
| **Failure**     | 死鎖重試機制           | ✅ Implemented     |
|                 | 重複退款防護           | ✅ Implemented     |
|                 | 冪等性中間件           | ✅ Implemented     |
| **Performance** | EXPLAIN ANALYZE 驗證   | ❌ Not Measured    |
|                 | 負載測試               | ❌ Not Measured    |
|                 | 大資料集基準測試       | ❌ Not Measured    |

---

# 14. Domain Invariants

本系統的業務不變量（Domain Invariants）是必須永遠成立的條件，這些都已在程式碼中實作保護：

### 點數餘額不為負

```text
PointAccount.balance must never be negative
```

- 程式碼保護：redeem 前的餘額檢查 + 更新時的 SQL 條件 `WHERE balance >= amount`

### 租戶資料隔離

```text
A tenant-scoped user must not access another tenant's data
```

- 程式碼保護：全域租戶範圍 + 授權 Policies + 中間件檢查

### 交易記錄完整性

```text
All point balance changes must have corresponding transaction records
```

- 程式碼保護：餘額更新與交易記錄建立在同一資料庫交易內

### 不可重複退款

```text
The same redeem transaction must not be refunded twice
```

- 程式碼保護：退款時的來源交易檢查與參考關聯

---

# 15. Technology Stack

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

---

# 16. API Documentation

API 使用 L5-Swagger / OpenAPI 自動生成文件，可透過 `/api/documentation` 存取。

---

# 17. Admin Panel

後台管理介面使用 Filament 5.8 + Livewire 4.4 建構，主要用於：

- 租戶管理
- 客戶管理
- 點數帳戶與交易查詢
- 使用者與權限管理
- 行銷活動管理

核心業務邏輯（點數交易）仍集中在 Service Layer，不論是 API 還是後台操作都使用同一套一致性保證機制。

---
