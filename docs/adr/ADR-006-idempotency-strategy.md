# ADR-006: Idempotency Strategy

## Related ADRs

- [ADR-002: Shared Database Multi-Tenancy](ADR-002-shared-database-tenancy.md) - 多租戶隔離基礎
- [ADR-003: Point Transaction Locking](ADR-003-point-transaction-locking.md) - 交易鎖定機制，本文件的冪等性檢查發生在其之前
- [ADR-007: Point Lot & Expiration Strategy](ADR-007-point-lot-expiration-strategy.md) - 批次點數管理，同樣依賴冪等性保證

## Context

系統需要保證API請求的冪等性，防止用戶端重試導致重複交易（如重複加點、重複扣點）。之前的文件中對冪等性實現存在描述不一致：部分內容提到使用Redis快取存儲冪等性鍵，部分提到使用資料庫唯一約束。需要明確標準實現策略。

目前系統面臨的冪等性挑戰：

1. 移動端網絡不穩定，用戶端可能重複發送同一請求
2. 負載均衡下多實例部署，需要跨實例的冪等性保證
3. 多租戶架構下，冪等性鍵必須按租戶隔離
4. 請求處理狀態需要持久化，即使應用重啟也不會丟失
5. 需要支援長時間運行的請求（如批量操作）的狀態追蹤

## Decision

採用**資料庫為唯一權威的冪等性存儲策略**，輔以Redis做為效能優化層，但永不將Redis作為唯一的狀態依據。完整實現方案：

### 核心架構

```text
Client Request (with Idempotency-Key header)
        ↓
DatabaseIdempotencyMiddleware
        ↓
檢查資料庫中是否存在該(tenant_id, idempotency_key)記錄
        ├─ 存在且COMPLETED → 直接返回緩存的響應
        ├─ 存在且PROCESSING → 返回409 Conflict提示處理中
        └─ 不存在 → 創建PROCESSING狀態記錄，繼續處理請求
                ↓
請求處理完成 → 更新記錄為COMPLETED，存儲響應內容
                ↓
返回響應給用戶端
```

### 資料庫層實現細節

1. **唯一約束**：`idempotency_keys`表建立`unique(tenant_id, idempotency_key)`複合唯一索引，從資料庫層級防止重複插入
2. **行鎖保護**：查詢冪等性記錄時使用`lockForUpdate()`，防止並發場景下的競爭條件
3. **狀態機制**：
    - `processing`：請求正在處理中
    - `completed`：請求處理成功完成
    - `failed`：請求處理失敗，可重試
4. **過期清理**：定時任務清理7天前的completed記錄，以及5分鐘以上的stale processing記錄

### Redis 輔助缓存策略

- 僅用於快取completed狀態的響應，降低資料庫查詢壓力
- 快取TTL設置為24小時，最終一致性依賴資料庫
- 當Redis不可用時，自動降級為直接查詢資料庫，不影響核心一致性
- 絕不依賴Redis存儲processing狀態，避免緩存丟失導致重複執行

### 程式碼實現證據

- [DatabaseIdempotencyMiddleware](../../app/Http/Middleware/DatabaseIdempotencyMiddleware.php) - 完整的中間件實現
- [IdempotencyKey模型](../../app/Models/IdempotencyKey.php) - 狀態定義與輔助方法
- [idempotency_keys遷移文件](../../database/migrations/2026_09_20_000001_create_idempotency_keys_table.php) - 唯一約束定義

## Alternatives Considered

### 1. 僅用資料庫存儲（目前採用的核心策略）

- **優點**：
    - 一致性最高，依靠資料庫事務和唯一約束保證原子性
    - 支持複雜的狀態追蹤，持久化不丟失
    - 與現有交易事務集成容易
    - 多租戶隔離簡單，複合鍵天然支持
- **缺點**：
    - 相比純Redis方案，讀取性能略低
    - 需要定期清理歷史數據，避免表體積過大

### 2. 僅用Redis存儲冪等性鍵

- **優點**：
    - 讀寫性能高
    - 自動過期機制原生支持，不需要定時清理
- **缺點**：
    - 無法可靠處理processing狀態，緩存丟失會導致重複執行
    - 分布式環境下Redis故障會導致冪等性失效
    - 無法支持長時間運行的請求狀態追蹤
    - 不適合作為關鍵交易系統的唯一依據

### 3. Redis + 資料庫雙寫（混合模式）

- **優點**：
    - 兼顧性能和一致性
    - 讀請求先查Redis，降低資料庫壓力
- **缺點**：
    - 實現複雜，需要處理雙寫一致性問題
    - 維護成本高，需要處理Redis故障降級邏輯
- **為什麼仍部分採用**：作為性能優化層，只緩存completed狀態，不影響核心一致性

## Consequences

### 正面影響

- 徹底解決文件中描述不一致的問題，統一標準實現
- 核心一致性完全依賴資料庫，可靠性最高
- Redis僅作為優化層，故障時不影響核心功能
- 支援複雜的狀態追蹤，適合長時間運行的批量操作
- 多租戶隔離通過資料庫約束保證，不會發生跨租戶冪等性鍵衝突

### 風險與緩解

- **風險**：資料庫成為冪等性查詢的瓶頸
    - **緩解**：Redis緩存熱點數據；建立正確的索引；定期清理歷史數據
- **風險**：idempotency_keys表體積無限增長
    - **緩解**：定時任務自動清理7天前的完成記錄；監控表增長率
- **風險**：stale processing記錄阻塞後續請求
    - **緩解**：定時任務檢查並標記5分鐘以上的processing記錄為failed，允許重試

## Exit Criteria

當系統滿足以下條件時，可考慮重新評估此策略：

1. 單租戶日均冪等性請求超過1000萬次，資料庫無法承載讀取壓力
2. 引入了獨立的分佈式緩存集群，能夠保證99.99%的可用性
3. 需要支援跨地域部署的多活架構，單一資料庫無法滿足延遲要求
