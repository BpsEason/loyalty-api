# Multi-Tenant Transaction Processing Platform

一套專注於交易一致性、租戶隔離與可擴展性的會員忠誠度系統核心。本專案的工程重點在於處理真實世界的併發交易、資料一致性與多租戶架構挑戰，而非單純的 CRUD 應用程式。

---

# 1. Project Overview

**Multi-Tenant Loyalty & Point API Platform** 是一個以 API First 為核心開發的點數交易處理平台。不同於一般的會員系統，本專案專注於解決當多個系統（網站、手機App、POS、電商、CRM）同時存取同一會員點數時的複雜一致性問題。

---

# 2. 系統真正不能違反的規則（Domain Invariants）

本系統的所有設計都圍繞著保護以下幾個絕對不能被打破的不變量：

## 2.1 Point Balance Invariant
```text
PointAccount.balance >= 0
PointLot.remaining_points >= 0
```
這是系統的核心商業不變量，永遠不允許客戶點數餘額為負。

**保證機制**：
- 應用層事前檢查：redeem/adjust 操作前先驗證 `account->balance >= amount`
- 資料庫層條件更新：使用 `where('balance', '>=', $amount)->update()` 確保只有餘額足夠才會更新
- PointLot 強制約束：`remaining_points` 欄位設定為 `unsignedInteger`，MySQL 層級保證不會為負
- 事務行鎖保護：所有修改都在 `lockForUpdate()` 行鎖保護下進行，避免並發競爭

**驗證**：高併發測試驗證 100 初始餘額在 10 個併發 20 點兌換請求下，最終餘額精確歸零，不會出現負數。

## 2.2 Tenant Isolation Invariant
```text
所有資料存取永遠在正確的租戶上下文內
tenant_id 是安全性與正確性邊界，而非僅是 UI 過濾條件
```
跨租戶資料存取是系統最高等級的錯誤，必須在多層級防護下杜絕。

**保證機制**：
- 全域範圍自動套用：`BelongsToTenant` Trait 自動為所有查詢加上 `tenant_id` 過濾
- 建立時自動填充：非 Super Admin 建立資料時自動填入當前租戶 ID
- 外鍵約束：所有表的 `tenant_id` 都有 FOREIGN KEY 約束關聯到 tenants 表
- 複合唯一索引：所有唯一約束都包含 `tenant_id`，避免跨租戶鍵碰撞
- Policy 層級檢查：每個資源的授權政策都再次驗證租戶一致性

## 2.3 Point Ledger Atomicity Invariant
```text
PointAccount 餘額更新、PointLot 批次消耗、PointTransaction 交易記錄必須在同一事務中完成
要麼全部成功，要麼全部回滾
```
確保餘額、批次、交易記錄三者永遠一致，不會出現狀態分裂。

**實際流程**：
```text
DB::transaction()
    ↓
lockForUpdate() 鎖定 PointAccount
    ↓
更新 account.balance（帶餘額條件檢查）
    ↓
依序 lockForUpdate() 鎖定需要消耗的 PointLot
    ↓
更新每個 PointLot.remaining_points
    ↓
建立 PointTransaction 記錄所有異動
    ↓
Commit 交易
```

## 2.4 Idempotency Invariant
```text
同一租戶內的同一個 idempotency_key 永遠只會被處理一次
UNIQUE (tenant_id, idempotency_key)
```
用戶端重試不會導致重複交易，這是處理網路不穩定的核心保證。

**保證機制**：
- 資料庫唯一約束：`idempotency_keys` 表的 `(tenant_id, idempotency_key)` 複合唯一索引
- 狀態機制：processing → completed/failed 狀態機確保處理狀態可追蹤
- Redis 僅作優化：Redis 只用於快取已完成的響應，核心一致性永遠依賴資料庫
- 降級能力：Redis 不可用時，系統仍能依賴資料庫保證冪等性

---

# 3. Architecture Decision Summary

所有架構決策都記錄在 `/docs/adr/`，以下是核心決策的摘要與 trade-off 分析。

## 3.1 ADR-001: Modular Monolith
**Problem**：需要選擇一個架構來平衡開發速度、交易一致性與未來擴展性。
**Decision**：採用 Modular Monolith，所有模組都在同一應用程式內，但保持模組間的責任清晰。
**Why**：
  - 點數交易需要強一致性，單體架構下的 ACID 交易能最低成本地保證 `PointAccount/PointLot/PointTransaction` 的原子性
  - 避免分散式事務的複雜度，在當前系統規模下，單體的成本遠低於微服務
  - 團隊規模適合單體開發，不需要跨團隊協調多個服務的部署與版本
**Trade-off**：
  - 犧牲了獨立擴展某個模組的彈性（如單獨擴展優惠券系統）
  - 所有模組共享同一個資料庫連接池，資源隔離性較弱
**Exit Criteria**：當團隊規模增長到超過 10 人、單一應用部署無法應對流量、或需要獨立擴展某些模組時，重新評估架構拆分。

## 3.2 ADR-002: Shared Database Multi-Tenancy
**Problem**：選擇多租戶架構模式，在隔離性與營運複雜度間取得平衡。
**Decision**：採用 Shared Database / Shared Tables 模式，所有租戶資料存在同一組表中，透過 `tenant_id` 區分。
**Why**：
  - 避免維護多個資料庫或多個綱要的營運複雜度
  - 跨租戶的統計報表更容易實現
  - 遷移與結構更新只需執行一次
**Trade-off**：
  - 犧牲了租戶級別的資源隔離（無法為大租戶分配獨立硬體）
  - 需要更嚴格的應用層隔離保證，避免跨租戶資料洩漏
**Exit Criteria**：當需要為某些客戶提供隔離的資料庫部署、或租戶數量增長到單一資料庫無法承載時，重新評估。

## 3.3 ADR-005: No Microservices Yet
**Problem**：是否要一開始就拆分為微服務架構。
**Decision**：目前不拆分微服務，維持 Modular Monolith。
**Why**：
  - 點數、優惠券、獎勵系統之間的交易邊界緊密，都需要強一致性
  - 如果拆分為微服務，將需要處理分散式一致性問題（Saga、Outbox 等），複雜度大幅提升
  - 當前業務邊界清晰但仍在演進，過早拆分可能導致重構成本高昂
**Trade-off**：
  - 所有功能必須一起部署，無法獨立發布
  - 單一程式碼庫隨著功能增長可能越來越龐大
**Exit Criteria**：當業務邊界完全穩定、需要獨立擴展某些服務、或團隊足夠大可以維護多個服務時，考慮拆分。

---

# 4. Concurrency & Consistency

本系統採用雙層鎖定策略來處理高併發場景下的一致性問題，Redis Lock 與 DB Lock 承擔完全不同的責任：

## 4.1 Redis Lock（應用層優化）
**負責**：
- 降低資料庫鎖爭用：讓多應用實例的併發請求先在 Redis 排隊
- 減少死鎖概率：提早序列化對同一客戶的操作
- 僅是優化層，不是 correctness boundary

Redis Lock 失敗時（例如 Redis 連接中斷），系統自動降級，依賴下一層的資料庫行鎖繼續保證一致性。

## 4.2 Database Lock（最終正確性保證）
**負責**：
- 行級序列化：`lockForUpdate()` 確保同一時間只有一個交易能修改某行
- 避免 Lost Update：即使 Redis Lock 失效，資料庫層級的鎖依然能防止並發修改
- 死鎖自動重試：`DB::transaction($callback, 3)` 自動重試因死鎖失敗的交易（最多 3 次）

**完整流程**：
```text
Redis Lock (block 最多 10 秒)
    ↓
DB::transaction() (最多重試 3 次)
    ↓
PointAccount::lockForUpdate()
    ↓
依序鎖定需要修改的 PointLot
    ↓
執行所有更新
    ↓
Commit
```

---

# 4.3 Transactional Outbox & Asynchronous Event Processing

為了支援事件驅動架構，本系統實現了完整的 **Transactional Outbox Pattern**，確保領域事件（Domain Event）與業務資料的原子提交，避免事件丟失或狀態不一致。

## 1. Transactional Outbox
業務資料與 Outbox Event 在同一個 Database Transaction 中寫入：
```text
Business Transaction
        │
        ├── PointAccount
        ├── PointTransaction
        ├── PointLot
        └── OutboxEvent
              │
              └── 同一個 DB Transaction
```

> 業務資料成功提交時，Outbox Event 同時存在；如果 Transaction rollback，Outbox Event 也會 rollback。

**原子性保證流程**：
```text
DB::transaction()
    ↓
lockForUpdate() 鎖定 PointAccount
    ↓
更新 account.balance
    ↓
更新 PointLot.remaining_points
    ↓
建立 PointTransaction 交易記錄
    ↓
寫入領域事件到 outbox_events
    ↓
Commit 交易
```
只有當整個事務成功提交，業務資料更新與事件記錄才會同時生效，從根本上避免了「業務資料更新成功但事件發送失敗」的分布式一致性問題。

## 2. Outbox Dispatcher
使用 Artisan 命令掃描並處理待處理的 Outbox Event：
```bash
php artisan outbox:process-pending
```

此命令負責：
* 找出 `processed_at IS NULL` 的事件
* 排除已超過重試次數的事件
* 按 `occurred_at` 排序（先進先出）
* 每次最多處理 100 筆
* 將 `ProcessOutboxEvent` Job  dispatch 到專用的 `outbox` queue

## 3. Laravel Scheduler 整合
Outbox Dispatcher 已接入 Laravel Scheduler，自動定期執行：
```php
Schedule::command('outbox:process-pending')
    ->everyMinute()
    ->withoutOverlapping();
```

> Dispatcher 不再依賴人工執行，而是由 Scheduler 定期掃描待處理 Outbox Event。

## 4. Redis Queue 設定
本系統使用 Redis 作為 Queue 連接，確保非同步事件處理的可靠性：
```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

Outbox Job 使用獨立的 `outbox` queue，需啟用專屬的 Worker：
```bash
php artisan queue:work --queue=outbox
```

Worker 負責真正執行 `ProcessOutboxEvent`，處理每個領域事件的分發。

## 5. Event Processing Pipeline
完整的事件處理流程：
```text
Application Service
        │
        ▼
Database Transaction
        │
        ├── Business Data
        │
        └── OutboxEvent
                │
                ▼
        Laravel Scheduler
                │
                ▼
    outbox:process-pending
                │
                ▼
        Redis Queue
                │
                ▼
      ProcessOutboxEvent
                │
        ┌───────┴───────┐
        │               │
   Redis Lock      IdempotencyKey
        │               │
        └───────┬───────┘
                ▼
       Domain Event
                │
                ▼
          Event Listener
```

## 6. 已實作的領域事件
系統目前支援以下領域事件的 Outbox 處理：
* `PointEarned`
* `PointRedeemed`
* `CouponClaimed`
* `CouponRedeemed`
* `RewardGranted`
* `LogPointEarnedEvent`

## 7. Reliability Mechanisms
本系統實現了多層可靠性機制確保事件處理的穩定性：

| 機制                   | 用途                      |
| -------------------- | ----------------------- |
| Transactional Outbox | 確保業務資料與事件原子寫入           |
| Redis Queue          | 非同步處理事件                 |
| Redis Lock           | 避免同一 Outbox Event 被並發處理 |
| Idempotency Key      | 降低重複處理風險                |
| Queue Retry          | 處理暫時性失敗                 |
| `attempts`           | 記錄處理嘗試次數                |
| `last_error`         | 保存最後一次失敗原因              |
| `processed_at`       | 記錄事件是否成功處理              |

## 8. At-Least-Once Delivery
> Outbox Processing 採 At-Least-Once Delivery 思維設計。Queue / Worker 可能因 retry、worker failure 或 process crash 而重新執行，因此事件處理必須具備冪等性。

## 9. Runtime Requirements
Outbox 功能需要以下服務才能正常運作：
```text
Redis
Laravel Scheduler
Queue Worker
```

必須啟用兩個關鍵程序：
```bash
# Scheduler：定期將 pending Outbox Event dispatch 到 Queue
php artisan schedule:work

# Queue Worker：實際執行 ProcessOutboxEvent
php artisan queue:work --queue=outbox
```

兩者責任不同，缺一不可。

---

# 5. Database-First Idempotency

本系統的冪等性策略遵循「資料庫是唯一權威」的核心原則，Redis 僅用於快取優化。

## 5.1 核心保證
- **唯一約束**：`UNIQUE (tenant_id, idempotency_key)` 資料庫層級保證同一鍵不會被處理兩次
- **狀態機**：
  - `processing`：請求正在處理中
  - `completed`：請求成功完成
  - `failed`：請求處理失敗，可重試
- **陳舊清理**：定時任務清理 7 天前的 completed 記錄，以及 5 分鐘以上的 stale processing 記錄

## 5.2 為什麼不只用 Redis？
- Redis 可能會丟失數據（持久化故障、內存淘汰）
- Redis 故障轉移期間可能出現一致性窗口
- 資料庫的唯一約束是最可靠的防線，即使所有上層機制都失效，依然能防止重複執行

---

# 6. Multi-Tenant Isolation 深度解析

租戶隔離是系統的安全性邊界，不是功能需求。本系統實現了多層級的防護：

```text
應用層級保護
    ├─ TenantResolver：解析當前請求的租戶上下文
    ├─ BelongsToTenant Trait：自動為所有模型套用全域租戶範圍
    ├─ Policies：每個資源的授權檢查再次驗證租戶
    └─ Middleware：請求進入時的租戶驗證
          │
資料庫層級保護
    ├─ 所有表的 tenant_id 外鍵約束
    └─ 唯一約束包含 tenant_id 防止跨租戶碰撞
```

## Super Admin 例外機制
只有超級管理員可以跳過全域租戶範圍，查看所有租戶的資料。這是唯一的例外，且在程式碼中明確標註，所有其他使用者都必須在租戶上下文內操作。

---

# 7. PointLot FIFO 消費策略

## 7.1 為什麼需要 PointLot？
- 支援點數過期：不同時間賺取的點數可以有不同的過期時間
- 精確的會計追蹤：每一筆點數的來源與去向都可追蹤
- FIFO 保證：先賺取的點數先被消耗，確保過期邏輯正確

## 7.2 FIFO 如何維持？
所有消耗操作都按照嚴格的順序鎖定與消耗 PointLot：
```php
->orderBy('earned_at', 'asc')
->orderBy('id', 'asc')
->lockForUpdate()
```
先按獲得時間排序，時間相同時按 ID 排序，保證完全確定性的消耗順序。

---

# 8. Failure Analysis 摘要

本系統的設計是在真實的失敗場景中演進而來的，關鍵的設計修正歷程請參考 `/docs/failure-analysis.md`。

## 曾經發生並修復的關鍵問題
1. **高併發下的雙重扣點**：透過新增 Customer 級別的 Redis Lock + DB 行鎖解決（Git 提交 `cfee6fd`）
2. **帳戶建立競態條件**：依賴 `(tenant_id, customer_id)` 唯一約束 + 併發捕獲重試邏輯解決
3. **PointLot 鎖定順序導致死鎖**：統一所有操作的鎖獲得順序（先鎖帳戶，再鎖批次）解決
4. **Redis 故障導致服務中斷**：新增 Redis 故障降級邏輯，依賴資料庫行鎖繼續運作

---

# 9. Engineering Principles

所有設計都遵循以下工程原則：

```text
Correctness before optimization          正確性優先於效能
Database constraints before assumptions  資料庫約束優先於應用假設
Transaction boundaries before distribution 交易邊界優先於分散式架構
Tenant isolation before convenience      租戶隔離優先於開發便利性
Idempotency before retryability          冪等性優先於可重試性
Observable failure before hidden recovery 可觀察的失敗優先於隱藏的恢復
```

每一項原則都有對應的程式碼、遷移或測試作為支撐。

---

# 10. Architecture Overview

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
          ├─ outbox_events 儲存待處理的領域事件
          └─ 業務資料表
          │
     Redis (Cache/Lock)
          │
          ▼
   Event Processor (異步處理Outbox事件)
          │
          ▼
    Message Queue / External Systems
```

---

# 11. Architecture Decisions

Detailed architecture decisions are documented under `/docs/adr`.

| ADR     | Decision                          | Status         |
| ------- | --------------------------------- | -------------- |
| ADR-001 | Modular Monolith                  | ✅ Implemented |
| ADR-002 | Shared Database Multi-Tenancy     | ✅ Implemented |
| ADR-003 | Redis + DB Transaction + Row Lock | ✅ Implemented |
| ADR-004 | JWT Authentication                | ✅ Implemented |
| ADR-005 | No Microservices Yet              | ✅ Implemented |
| ADR-006 | Idempotency Strategy              | ✅ Implemented |
| ADR-007 | Point Lot & Expiration Strategy   | ✅ Implemented |
| ADR-008 | Coupon System                     | ✅ Implemented |
| ADR-009 | Coupon / Reward API Boundary      | ✅ Implemented |
| ADR-010 | Membership Tier System            | ✅ Implemented |
| ADR-011 | Campaign Rule Engine              | ✅ Implemented |
| ADR-012 | Audit Logging System              | ✅ Implemented |
| ADR-013 | Outbox Pattern for Domain Events  | ✅ Implemented |

---

# 12. Core Features

本系統已實作以下核心業務功能：

## 會員等級與權益系統

- 會員可依累積消費或累積點數自動判定並升級會員等級
- 每個租戶可獨立設定自己的會員等級體系與升級門檻
- 支援等級權益設定（點數倍增、折扣率、免運費等欄位已預留）
- 與客戶資料、點數帳戶等核心業務流程深度整合
- 限制：目前權益欄位僅完成資料層設定，尚未整合到實際交易結算流程中

## Campaign 規則引擎

- 支援兩種活動規則類型：消費滿額自動給點、指定商品購買給點
- 規則已與既有PointService / RewardService點數發放流程整合
- 內建冪等性機制，防止同一筆交易重複發放獎勵
- 每個活動可設定多個規則，按優先級依次處理
- 說明：目前為針對特定場景實作的規則系統，而非通用型規則引擎

## 操作審計日誌

- 自動記錄後台所有管理操作，包含操作人、操作時間、操作對象
- 完整記錄資料變更前後的old_values與new_values，追蹤每一次修改
- 支援租戶隔離：超級管理員可查看所有租戶記錄，一般使用者僅能查看所屬租戶的操作記錄
- Filament後台提供審計日誌查詢、篩選功能
- 支援Excel匯出，可下載完整的操作記錄進行離線分析
- 僅有標記為Auditable的模型會產生審計記錄

---

# 13. API Documentation

本系統提供完整的 RESTful API，所有客戶端API都位於 `/api/v1/` 前綴下。完整的互動式API文檔可通過以下地址訪問：

**Swagger UI**: `/api/documentation`

## API 概覽表格

| API Domain              | Endpoint                                                             | Method | Purpose                                          | Auth Required | Tenant Required | Idempotent |
| ----------------------- | -------------------------------------------------------------------- | ------ | ------------------------------------------------ | ------------- | --------------- | ---------- |
| **Authentication**      |                                                                      |        |                                                  |               |                 |            |
| Auth                    | `/api/v1/auth/login`                                                 | POST   | 用戶登錄獲取JWT令牌                              | ❌            | ❌              | ❌         |
| Auth                    | `/api/v1/auth/logout`                                                | POST   | 登出並失效當前令牌                               | ✅            | ❌              | ❌         |
| Auth                    | `/api/v1/auth/refresh`                                               | POST   | 刷新JWT訪問令牌                                  | ✅            | ❌              | ❌         |
| Auth                    | `/api/v1/auth/me`                                                    | GET    | 獲取當前認證用戶信息                             | ✅            | ✅              | ❌         |
| **Customer Management** |                                                                      |        |                                                  |               |                 |            |
| Customer                | `/api/v1/customers`                                                  | GET    | 獲取租戶下的會員列表                             | ✅            | ✅              | ❌         |
| Customer                | `/api/v1/customers/{customer}`                                       | GET    | 獲取單個會員詳情                                 | ✅            | ✅              | ❌         |
| Customer                | `/api/v1/customers`                                                  | POST   | 創建新會員                                       | ✅            | ✅              | ❌         |
| Customer                | `/api/v1/customers/{customer}`                                       | PUT    | 更新會員資料                                     | ✅            | ✅              | ❌         |
| Customer                | `/api/v1/customers/{customer}`                                       | DELETE | 刪除會員                                         | ✅            | ✅              | ❌         |
| Customer                | `/api/v1/customers/{customer}/qr-code`                               | GET    | 獲取會員QR碼（用於POS掃描）                      | ✅            | ✅              | ❌         |
| Customer                | `/api/v1/customers/identify`                                         | POST   | 通過QR token識別會員                             | ✅            | ✅              | ❌         |
| **Points System**       |                                                                      |        |                                                  |               |                 |            |
| Points                  | `/api/v1/customers/{customer}/points`                                | GET    | 查詢會員當前點數餘額                             | ✅            | ✅              | ❌         |
| Point Transactions      | `/api/v1/customers/{customer}/point-transactions`                    | GET    | 獲取點數交易歷史                                 | ✅            | ✅              | ❌         |
| Point Transactions      | `/api/v1/customers/{customer}/point-transactions/expiring`           | GET    | 獲取即將過期的點數明細                           | ✅            | ✅              | ❌         |
| Point Transactions      | `/api/v1/customers/{customer}/point-transactions/{pointTransaction}` | GET    | 獲取單筆交易明細                                 | ✅            | ✅              | ❌         |
| Point Transactions      | `/api/v1/customers/{customer}/point-transactions`                    | POST   | 通用點數異動（earn/redeem/adjust/refund/expire） | ✅            | ✅              | ✅         |
| Points                  | `/api/v1/customers/{customer}/points/redeem`                         | POST   | POS專用點數兌換語意捷徑                          | ✅            | ✅              | ✅         |
| **Coupon System**       |                                                                      |        |                                                  |               |                 |            |
| Coupons                 | `/api/v1/customers/{customer}/coupons`                               | GET    | 獲取會員持有的優惠券列表                         | ✅            | ✅              | ❌         |
| Coupons                 | `/api/v1/customers/{customer}/coupons/{userCoupon}`                  | GET    | 獲取單張優惠券詳情                               | ✅            | ✅              | ❌         |
| Coupons                 | `/api/v1/customers/{customer}/coupons/claim`                         | POST   | 領取優惠券                                       | ✅            | ✅              | ✅         |
| Coupons                 | `/api/v1/customers/{customer}/coupons/{userCoupon}/redeem`           | POST   | 核銷優惠券                                       | ✅            | ✅              | ✅         |
| Coupons                 | `/api/v1/customers/{customer}/coupon-redemptions`                    | GET    | 查詢優惠券核銷歷史                               | ✅            | ✅              | ❌         |
| Mixed Payment           | `/api/v1/customers/{customer}/mixed-payment`                         | POST   | 混合支付（優惠券+點數）                          | ✅            | ✅              | ✅         |
| **Reward System**       |                                                                      |        |                                                  |               |                 |            |
| Rewards                 | `/api/v1/customers/{customer}/reward-grants`                         | GET    | 獲取獎勵發放記錄                                 | ✅            | ✅              | ❌         |
| Rewards                 | `/api/v1/customers/{customer}/rewards/grant`                         | POST   | 手動發放獎勵                                     | ✅            | ✅              | ✅         |

### 所有寫入API的冪等性要求

標記為 `Idempotent = ✅` 的API必須在請求頭中攜帶 `Idempotency-Key: <unique-key>`，確保網路重試不會導致重複交易。詳見 [ADR-006: Idempotency Strategy](docs/adr/ADR-006-idempotency-strategy.md)。

### 租戶解析機制

所有需要 `Tenant Required = ✅` 的API都會自動從認證的用戶中解析出所屬租戶，並通過全域作用域確保租戶資料隔離。詳見 [ADR-002: Shared Database Multi-Tenancy](docs/adr/ADR-002-shared-database-tenancy.md)。

---

# 11. Failure Scenarios

系統針對各種失敗場景都有相應的保護機制，詳細的失敗分析請參考 `/docs/failure-analysis.md`。

主要的失敗場景包括：

1. **雙重扣點 (Double Spend)**：透過 Redis Lock + DB Row Lock 防護
2. **重複退款 (Duplicate Refund)**：透過業務邏輯檢查與參考關聯防護
3. **租戶資料洩漏 (Tenant Data Leakage)**：透過全域範圍 + 授權政策防護
4. **資料庫死鎖 (Deadlock)**：透過交易自動重試機制處理
5. **Redis 不可用 (Redis Unavailable)**：資料庫行鎖仍能提供基本的一致性保證
6. **網路分區 (Network Partition)**：等待鎖自動釋放後重試
7. **優惠券超發 (Coupon Overissue)**：透過模板級Redis鎖 + CouponTemplate行鎖 + used_count原子更新防護
8. **優惠券重複核銷 (Coupon Double Redeem)**：透過UserCoupon行鎖 + redemption_id唯一約束 + 狀態機約束防護
9. **混合支付狀態不一致 (Hybrid Payment Inconsistency)**：透過同一資料庫事務包裝所有操作，要麼全部成功要麼全部回滾
10. **過期優惠券被使用 (Expired Coupon Redemption)**：透過核銷前強檢查 + 列表查詢即時過濾 + 定時任務批量處理防護

---

# 12. Database Design

## 實體關係圖

```text
Tenant
   │
   ├── Users (系統使用者：Super Admin / Tenant Admin / Staff)
   ├── Customers (會員客戶)
   │      │
   │      ├── PointAccount (每個客戶一個點數帳戶)
   │      │        │
   │      │        └── PointTransaction (所有點數交易明細)
   │      │
   │      └── UserCoupon (會員持有的優惠券)
   │               │
   │               └── CouponRedemption (優惠券核銷記錄)
   │
   ├── CouponTemplates (優惠券模板)
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

| 表                 | 索引欄位                                    | 查詢模式                                                                              | 用途                                                    |
| ------------------ | ------------------------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| point_transactions | `(tenant_id, point_account_id, created_at)` | `WHERE tenant_id = ? AND point_account_id = ? ORDER BY created_at DESC`               | 查詢特定客戶的交易歷史，按時間倒序排列                  |
| point_transactions | `(reference_type, reference_id)`            | 多態關聯查詢                                                                          | 追蹤交易的關聯實體                                      |
| point_accounts     | `(tenant_id, customer_id)`                  | UNIQUE 約束                                                                           | 確保同一租戶下每個客戶只有一個點數帳戶                  |
| point_lots         | `(point_account_id, expired_at, id)`        | `WHERE point_account_id = ? AND remaining_points > 0 ORDER BY expired_at ASC, id ASC` | Point Lot FIFO 覆蓋索引，快速取得最早過期的有效點數批次 |
| point_lots         | `(tenant_id, customer_id)`                  | 查詢特定租戶客戶的所有點數批次                                                        | 後台統計與查詢優化                                      |

---

# 13. Performance & Query Optimization

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

# 14. Scalability & Capacity Planning

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

# 15. Testing & Verification

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

# 16. Domain Invariants

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

# 17. Technology Stack

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

# 18. API Documentation

API 使用 L5-Swagger / OpenAPI 自動生成文件，可透過 `/api/documentation` 存取。

## 18.1 API 端點總覽

所有端點均以 `/api/v1` 為前綴。除 `POST /auth/login` 外，所有端點均需要 `Authorization: Bearer <JWT>` header。

### Authentication

| Method | Path            | 說明                   | Auth | Tenant | Idempotent |
| ------ | --------------- | ---------------------- | ---- | ------ | ---------- |
| POST   | `/auth/login`   | 取得 JWT Token         | ❌   | ❌     | ❌         |
| POST   | `/auth/logout`  | 登出並使 Token 失效    | ✅   | ❌     | ❌         |
| POST   | `/auth/refresh` | 刷新 JWT Token         | ✅   | ❌     | ❌         |
| GET    | `/auth/me`      | 取得目前登入使用者資訊 | ✅   | ✅     | ❌         |

### Customer

| Method    | Path                            | 說明                               | Auth | Tenant | Idempotent |
| --------- | ------------------------------- | ---------------------------------- | ---- | ------ | ---------- |
| GET       | `/customers`                    | 列出客戶（分頁）                   | ✅   | ✅     | ❌         |
| POST      | `/customers`                    | 新增客戶                           | ✅   | ✅     | ❌         |
| GET       | `/customers/{customer}`         | 取得指定客戶                       | ✅   | ✅     | ❌         |
| PUT/PATCH | `/customers/{customer}`         | 更新客戶資料                       | ✅   | ✅     | ❌         |
| DELETE    | `/customers/{customer}`         | 刪除客戶                           | ✅   | ✅     | ❌         |
| GET       | `/customers/{customer}/qr-code` | 取得客戶 QR Code                   | ✅   | ✅     | ❌         |
| POST      | `/customers/identify`           | 透過 QR Token 識別客戶（POS 掃碼） | ✅   | ✅     | ❌         |

### Points

| Method | Path                                                          | 說明                                                       | Auth | Tenant | Idempotent |
| ------ | ------------------------------------------------------------- | ---------------------------------------------------------- | ---- | ------ | ---------- |
| GET    | `/customers/{customer}/points`                                | 取得點數帳戶餘額                                           | ✅   | ✅     | ❌         |
| GET    | `/customers/{customer}/point-transactions`                    | 查詢點數交易記錄（分頁、篩選）                             | ✅   | ✅     | ❌         |
| GET    | `/customers/{customer}/point-transactions/expiring`           | 查詢即將過期的點數                                         | ✅   | ✅     | ❌         |
| GET    | `/customers/{customer}/point-transactions/{pointTransaction}` | 取得單筆交易明細                                           | ✅   | ✅     | ❌         |
| POST   | `/customers/{customer}/point-transactions`                    | 點數異動（type: earn / redeem / adjust / refund / expire） | ✅   | ✅     | ✅         |
| POST   | `/customers/{customer}/points/redeem`                         | POS 點數兌換（語意捷徑）                                   | ✅   | ✅     | ✅         |

### Coupon

| Method | Path                                                | 說明                         | Auth | Tenant | Idempotent |
| ------ | --------------------------------------------------- | ---------------------------- | ---- | ------ | ---------- |
| GET    | `/customers/{customer}/coupons`                     | 列出客戶持有的優惠券（分頁） | ✅   | ✅     | ❌         |
| GET    | `/customers/{customer}/coupons/{userCoupon}`        | 取得單張優惠券               | ✅   | ✅     | ❌         |
| POST   | `/customers/{customer}/coupons/claim`               | 客戶領取優惠券（輸入 code）  | ✅   | ✅     | ✅         |
| POST   | `/customers/{customer}/coupons/{userCoupon}/redeem` | 核銷優惠券                   | ✅   | ✅     | ✅         |
| GET    | `/customers/{customer}/coupon-redemptions`          | 查詢優惠券核銷歷史（分頁）   | ✅   | ✅     | ❌         |

### Mixed Payment

| Method | Path                                  | 說明                              | Auth | Tenant | Idempotent |
| ------ | ------------------------------------- | --------------------------------- | ---- | ------ | ---------- |
| POST   | `/customers/{customer}/mixed-payment` | 混合支付（優惠券 + 點數同時使用） | ✅   | ✅     | ✅         |

### Reward

| Method | Path                                  | 說明                         | Auth | Tenant | Idempotent |
| ------ | ------------------------------------- | ---------------------------- | ---- | ------ | ---------- |
| GET    | `/customers/{customer}/reward-grants` | 查詢客戶獎勵發放歷史（分頁） | ✅   | ✅     | ❌         |
| POST   | `/customers/{customer}/rewards/grant` | 對客戶發放指定活動獎勵       | ✅   | ✅     | ✅         |

> **注意**：Campaign（行銷活動）與 CampaignReward（活動獎勵設定）屬於後台管理功能，透過 Filament Admin Panel 管理，不提供公開 API。詳見 [ADR-009](docs/adr/ADR-009-reward-api-boundary.md)。

---

# 19. Admin Panel

後台管理介面使用 Filament 5.8 + Livewire 4.4 建構，主要用於：

- 租戶管理
- 客戶管理
- 點數帳戶與交易查詢
- 使用者與權限管理
- 行銷活動管理
- 優惠券管理

核心業務邏輯（點數交易）仍集中在 Service Layer，不論是 API 還是後台操作都使用同一套一致性保證機制。

## 20. Point Ledger & Point Lot

Point Lot 是本系統用於實現精確點數追溯的核心機制，每一批點數都以 Lot 形式管理，確保點數的來源、有效期與消耗順序都可完整追蹤。

### 核心模型

```text
PointLot
├── tenant_id: 租戶隔離
├── customer_id: 關聯會員
├── point_account_id: 關聯點數帳戶
├── original_points: 批次原始點數
├── remaining_points: 剩餘可用點數
├── earned_at: 獲得時間
├── expired_at: 過期時間
└── origin_transaction_id: 來源交易
```

### 實作狀態

#### ✅ Implemented

- `earn`: 建立新的點數批次
- `redeem`: FIFO 順序消耗點數
- `adjust+`: 新增點數批次
- `adjust-`: FIFO 順序消耗調整
- `refund`: 建立退款點數批次
- `expire`: FIFO 順序處理過期點數

#### 🚧 Remaining Work

- 批次合併優化
- 歷史批次歸檔機制

---

# 21. Coupon Domain

優惠券系統由三層核心模型組成，負責從規則定義到實際核銷的完整生命週期管理，完整設計遵循[ADR-008: Coupon System](../docs/adr/ADR-008-coupon-system.md)。

```text
CouponTemplate
    ↓
UserCoupon
    ↓
CouponRedemption
```

### 支援的台灣常見券種

系統優先支援台灣電商與實體零售的主流場景：

- 金額折扣券（如NT$100折價券）
- 比例折扣券（如全館85折）
- 滿額減免券（如滿NT$500減NT$50）
- 滿件折扣券（如買3件第2件半價）
- 買一送一券
- 免運費券
- 點數加成券（消費獲得多倍點數）

### 核心模型職責

- **CouponTemplate**: 優惠券規則定義，包含折扣類型、有效期、發行數量、單用戶領取上限等配置
- **UserCoupon**: 會員持有的具體優惠券實體，記錄領取時間、狀態（available/used/expired/cancelled）與過期時間
- **CouponRedemption**: 優惠券核銷記錄，保存實際使用時的交易資訊、折扣金額、關聯訂單ID

### 與點數系統的整合原則

當交易同時使用優惠券和點數時，嚴格遵循以下規則：

1. **計算順序**：先套用優惠券折扣，再基於折扣後的金額扣減點數
2. **原子性保證**：優惠券狀態變更與點數扣減必須在同一資料庫事務中完成，要麼全部成功，要麼全部回滾
3. **鎖定順序**：與ADR-003完全對齊，嚴格按ID升序獲取鎖，避免死鎖：先鎖定UserCoupon，再鎖定PointAccount，最後鎖定需要修改的PointLot
4. **一致性模型**：共用同一套冪等性、分散式鎖、行鎖機制，確保優惠券與點數系統的一致性保證等級完全一致

### 多層防護機制

- **超發防護**：模板級Redis鎖 + 資料庫行鎖 + used_count原子更新 + 每日校驗任務
- **重複核銷防護**：UserCoupon行鎖 + redemption_id唯一約束 + 狀態機約束 + 冪等性中間件
- **過期處理**：每日定時任務批量處理 + 列表查詢即時過濾 + 核銷前強檢查

完整的優惠券系統設計文件請參考：`docs/design/coupon.md`

---

# 22. Documentation Structure

本專案採用分層文件架構，將不同性質的技術文件歸類到對應目錄，保持 README 作為專案入口的簡潔性：

```text
docs/
├── adr/                # Architecture Decision Records
│   ├── ADR-001-modular-monolith.md
│   ├── ADR-002-shared-database-tenancy.md
│   ├── ADR-003-point-transaction-locking.md
│   ├── ADR-004-jwt-authentication.md
│   ├── ADR-005-no-microservices-yet.md
│   ├── ADR-006-idempotency-strategy.md
│   ├── ADR-007-point-lot-expiration-strategy.md
│   ├── ADR-008-coupon-system.md
│   ├── ADR-009-reward-api-boundary.md
│   ├── ADR-010-membership-tier-system.md
│   ├── ADR-011-campaign-rule-engine.md
│   └── ADR-012-audit-logging-system.md
│
└── failure-analysis.md # 失敗場景與容錯設計
```

---

# 23. Current Status

## 系統邊界說明

> 會員等級權益（points_multiplier、discount_rate）目前僅完成資料模型，尚未自動套用至 earn / redeem 流程。

## 核心功能完成度

| 領域                    | 完成度 | 狀態        |
| ----------------------- | ------ | ----------- |
| Transaction Consistency | 95%    | ✅ 穩定運行 |
| Concurrency Control     | 90%    | ✅ 穩定運行 |
| Multi-Tenant Isolation  | 100%   | ✅ 完整實作 |
| Point Ledger            | 100%   | ✅ 穩定運行 |
| Point Lot FIFO          | 95%    | 🚧 完善中   |
| Idempotency             | 100%   | ✅ 完整實作 |
| Coupon System           | 100%   | ✅ 穩定運行 |
| Reward Grant API        | 100%   | ✅ 穩定運行 |

## 生產環境就緒度

- ✅ 核心交易流程穩定
- ✅ 租戶隔離機制完整
- ✅ 併發保護機制到位
- ⚠️ 效能基準測試進行中
- ⚠️ 災難回復流程驗證中

---

# 24. Development Philosophy

本專案的開發遵循以下核心原則：

1. **Correctness First**: 正確性永遠優先於效能，交易一致性是不可妥協的底線
2. **Defensive Programming**: 每一層都做驗證，確保壞的狀態無法進入系統
3. **Auditable Everything**: 所有關鍵狀態變更都留下不可篡改的記錄
4. **Simple Over Easy**: 理解簡單的複雜，勝過理解複雜的簡單
5. **Single Source of Truth**: 核心業務邏輯只實作一次，不論是 API 還是後台操作都使用同一套機制

---

# API Documentation

完整的 API 使用文件由 OpenAPI/Swagger 自動生成，請訪問 `/api/documentation` 查看。