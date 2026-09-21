# ADR-007: Point Lot & Expiration Strategy

## Related ADRs

- [ADR-002: Shared Database Multi-Tenancy](ADR-002-shared-database-tenancy.md) - 多租戶隔離基礎，批次數據按租戶隔離
- [ADR-003: Point Transaction Locking](ADR-003-point-transaction-locking.md) - 交易鎖定機制，過期處理遵循相同的鎖獲取規則
- [ADR-006: Idempotency Strategy](ADR-006-idempotency-strategy.md) - 冪等性保證，防止過期任務重複執行

## Context

系統需要支援點數的批次管理與自動過期機制，以滿足不同行銷活動的點數有效期要求。批次點數（PointLot）要求實現FIFO（先進先出）的消耗順序，確保最早獲得的點數先被使用，從而正確處理過期邏輯。

需要解決的核心問題：

1. 如何保證點數消耗嚴格按照FIFO順序
2. 自動過期處理如何與餘額一致性協調
3. 過期操作如何避免與用戶主動兌換發生死鎖
4. 批次點數的剩餘量與主帳戶餘額如何保持一致

## Decision

採用**以PointLot為核心的批次生命週期管理策略**，結合定時任務處理過期，並通過嚴格的鎖定順序保證一致性。

### FIFO 扣點實現細節

1. **批次創建**：每當用戶獲得點數（earn操作），自動創建一個新的PointLot記錄，記錄原始點數、剩餘點數、獲得時間、過期時間
2. **消耗排序**：兌換點數時，永遠按`earned_at ASC, id ASC`排序獲取批次，保證先獲得的點數先消耗
3. **游標式消費**：redeem操作中每次只鎖定並處理一個批次，處理完立即釋放，降低鎖持有時間
4. **原子性更新**：批次剩餘點數更新與主帳戶餘額更新在同一資料庫事務中提交，中間任何失敗都會全量回滾

### 過期處理完整流程

1. **過期標記排程**：每日凌晨執行定時任務，掃描所有`expired_at <= NOW() AND remaining_points > 0 AND expired_at IS NOT NULL`的批次
2. **單客戶串行處理**：對於每個需要處理過期的客戶，首先獲取該客戶的Redis分散式鎖，再進入資料庫事務
3. **鎖定順序**：先鎖定PointAccount主帳戶，再按順序鎖定需要標記過期的PointLot
4. **競爭處理保證**：到期排程與用戶兌換使用完全相同的鎖鍵與取得順序，確保不會發生死鎖與餘額不一致問題
5. **過期原子操作**：
    ```php
    // 在同一事務中
    $account->lockForUpdate()->first();
    foreach ($expiredLots as $lot) {
        $lot->lockForUpdate()->first();
        $expireAmount = $lot->remaining_points;
        $lot->update(['remaining_points' => 0]);
        $totalExpired += $expireAmount;
    }
    $account->decrement('balance', $totalExpired);
    ```
6. **過期交易記錄**：為每筆過期操作創建對應的PointTransaction記錄，類型為`EXPIRE`，維護完整審計軌跡

### 一致性保證機制

1. **雙重餘額校驗**：
    - 批次扣減前校驗`lot.remaining_points >= 要扣除的數量`
    - 主帳戶更新前校驗`account.balance >= 要扣除的總數量`
    - 兩個校驗都在持有資料庫行鎖的情況下執行
2. **觸發器補強（可選）**：可通過資料庫觸發器校驗所有PointLot的remaining_points之和等於PointAccount的balance，作為最後一道防線
3. **每日校驗任務**：定時任務每日校驗所有帳戶的批次剩餘點數之和與主帳戶餘額是否一致，不一致則觸發告警

### 觀測性與監控

- 指標1：`point_expired_daily_total` - 每日過期點數總量
- 指標2：`point_lot_consumption_latency_seconds` - 批次從創建到完全消耗的平均時間
- 指標3：`balance_inconsistency_count` - 主帳戶餘額與批次總和不一致的次數
- 告警閾值：balance_inconsistency_count > 0 立即告警

### 程式碼實現證據

- [earn方法中的PointLot創建](../../app/Services/Point/PointService.php#L147-L170) - 獲得點數時創建批次
- [redeem方法中的FIFO消耗](../../app/Services/Point/PointService.php#L178-L210) - 按順序消耗批次點數
- [PointLot模型](../../app/Models/PointLot.php) - 批次模型定義
- [RedeemPointsCommand](../../app/Console/Commands/RedeemPointsCommand.php) - 過期處理排程基礎

## Alternatives Considered

### 1. PointLot + 定時過期（目前採用）

- **優點**：
    - FIFO順序嚴格保證，過期邏輯簡單正確
    - 鎖粒度小，並發性能好
    - 審計日誌完整，每筆點數流轉都有記錄
    - 一致性保證完善，多重校驗防止不一致
- **缺點**：
    - 實現相對複雜，需要維護主帳戶和批次兩份數據
    - 每次兌換都需要迭代更新批次，小批量扣點時有少量額外開銷

### 2. 僅維護主帳戶餘額，過期直接扣減

- **優點**：
    - 實現簡單，不需要維護批次數據
    - 交易性能高，只需更新主帳戶
- **缺點**：
    - 無法實現精準的FIFO扣點，不同有效期的點數無法區分
    - 過期邏輯粗糙，可能導致可用點數先過期
    - 審計能力不足，無法追溯點數的來源和去向
    - 無法支援不同行銷活動的點數獨立有效期要求

### 3. 使用Redis排序集維護批次順序

- **優點**：
    - 讀取性能高，Redis原生支持排序
    - 分散式環境下查詢速度快
- **缺點**：
    - 需要維護Redis和資料庫的雙寫一致性
    - Redis故障可能導致批次順序混亂
    - 增加系統複雜性和依賴
    - 對於目前的業務規模，資料庫層的排序足夠應對

## Consequences

### 正面影響

- 完整支持複雜的點數有效期管理，不同批次可以設置不同的過期時間
- FIFO順序嚴格保證，最早獲得的點數先消耗，符合用戶直覺和業務規則
- 過期處理與用戶主動操作的鎖定順序一致，死鎖風險極低
- 多重一致性校驗，基本杜絕主帳戶餘額與批次總和不一致的情況
- 完整的審計日誌，所有點數變更都可追溯

### 風險與緩解

- **風險**：大數量過期批次處理時，定時任務執行時間過長
    - **緩解**：分頁處理，每批處理100個客戶；錯峰執行（凌晨）；可以拆分為多進程並行處理（但每個客戶只由一個進程處理）
- **風險**：過期處理與用戶兌換發生鎖競爭
    - **緩解**：兩者使用相同的Redis鎖和鎖獲取順序；Redis鎖的排隊機制保證串行執行
- **風險**：point_lots表數量增長過快
    - **緩解**：定期歸檔或刪除remaining_points=0且created_at超過180天的批次；建立合適的索引優化查詢性能

## Exit Criteria

當系統滿足以下條件時，可考慮重新評估此策略：

1. 單租戶的PointLot記錄超過1000萬條，資料庫查詢性能無法滿足
2. 需要支援更複雜的點數消耗順序（如LIFO或優先級消耗），FIFO無法滿足業務需求
3. 引入了獨立的數據倉庫用於審計和報表，線上業務需要進一步簡化
