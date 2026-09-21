# ADR-003: Point Transaction Locking

## Related ADRs

- [ADR-002: Shared Database Multi-Tenancy](ADR-002-shared-database-tenancy.md) - 多租戶隔離基礎
- [ADR-006: Idempotency Strategy](ADR-006-idempotency-strategy.md) - 冪等性保證，與鎖定機制協同工作
- [ADR-007: Point Lot & Expiration Strategy](ADR-007-point-lot-expiration-strategy.md) - 批次點數管理，依賴本文件的鎖定機制

## Context相關 ADR

- [ADR-002: Shared Database Multi-Tenancy](ADR-002-shared-database-tenancy.md) - 多租戶隔離基礎
- [ADR-006: Idempotency Strategy](ADR-006-idempotency-strategy.md) - 冪等性保證機制
- [ADR-007: Point Lot & Expiration Strategy](ADR-007-point-lot-expiration-strategy.md) - 批次點數與過期處理

## Context相關ADR

- [ADR-002: Shared Database Multi-Tenancy](ADR-002-shared-database-tenancy.md) - 多租戶隔離機制
- [ADR-006: Idempotency Strategy](ADR-006-idempotency-strategy.md) - 冪等性實現策略
- [ADR-007: Point Lot & Expiration Strategy](ADR-007-point-lot-expiration-strategy.md) - 批次點數與過期處理

## Context

點數交易面臨的核心一致性問題是並發更新導致的 Lost Update（丟失更新）或 Double Spend（重複消費）。隨著系統支持 PointLot（FIFO 批次點數）和更複雜的點數生命週期操作，需要更明確的鎖定策略來處理複雜場景。

### 經典場景

```text
Initial Balance = 100

Request A → Redeem 80
Request B → Redeem 80
```

如果僅使用簡單的更新語句：

```php
$account->balance = $account->balance - $amount;
$account->save();
```

兩個請求可能同時讀取到 balance = 100，都通過餘額驗證，最終餘額變成 20（100-80）而不是正確的負數（或阻止第二次交易），導致超賣點數。

## Decision

目前 [PointService](../../app/Services/Point/PointService.php) 採用多層次鎖定策略，補充完整的鎖獲取順序、TTL 策略、超時處理、降級行為以及 PointLot 專屬鎖定機制：

```text
Redis Distributed Lock (應用層排隊)
        ↓
DB Transaction (with 3 retries - 死鎖自動重試)
        ↓
鎖獲取順序：先鎖定主帳戶 → 再依次鎖定需要修改的 PointLot
        ↓
SELECT ... FOR UPDATE (行級鎖，串行化修改)
        ↓
餘額/批次有效性驗證
        ↓
批次點數扣減 (FIFO 順序)
        ↓
主帳戶餘額更新
        ↓
PointTransaction 日誌創建
        ↓
Commit
```

### 完整鎖定策略細節

#### 1. Redis 分散式鎖 (應用層第一關)

- **鎖定鍵**：`point_customer:{customer_id}:tenant:{tenant_id}`
- **TTL 策略**：20秒（從原10秒延長），確保最長交易完成時間內不會提前釋放
- **等待超時**：10秒，超過則返回「系統繁忙」錯誤
- **實現**：Laravel Cache::lock()->block() 自旋等待，自動處理鎖競爭
- **作用**：在應用層排隊同一客戶的並發請求，大幅降低資料庫層的鎖競爭壓力

#### 2. 鎖獲取順序 (預防死鎖的核心)

- **永遠按 ID 升序獲取鎖**：任何涉及多個資源的操作，永遠先鎖定 ID 較小的實體
- **單客戶操作標準順序**：先鎖定 PointAccount（主帳戶）→ 再按 `earned_at, id` 順序鎖定 PointLot
- **跨客戶批量操作**：永遠先按 customer_id 排序，依次處理每個客戶，同一時間只持有一個客戶的鎖
- **PointLot 游標式消費**：redeem 操作中，每次只鎖定當前需要消耗的一個批次，處理完立即釋放（而非一次性鎖定所有相關批次）

#### 3. TTL 與超時處理完整策略

| 鎖類型         | TTL    | 等待超時                                | 超時處理邏輯                   |
| -------------- | ------ | --------------------------------------- | ------------------------------ |
| Redis 分散式鎖 | 20秒   | 10秒                                    | 拋出異常，用戶端需重試         |
| DB 行級鎖      | 事務級 | 無（依賴innodb_lock_wait_timeout=50秒） | MySQL 自動檢測死鎖並中斷事務   |
| 事務整體超時   | -      | 15秒                                    | Laravel 事務超時機制，自動回滾 |

#### 4. Redis 不可用的降級策略

- Redis 連接故障時，自動跳過 Redis 鎖，完全依賴資料庫層的 `lockForUpdate()` 行鎖保證一致性
- 自動記錄 Redis 故障告警，觸發運維人員排查
- 降級期間性能下降，但一致性保證不降低，系統仍可安全運行
- 程式碼實現：[executeInLock 方法中的異常捕獲](../../app/Services/Point/PointService.php#L75-L85)

#### 5. PointLot 專屬鎖定機制

- **FIFO 順序保證**：redeem 操作中按 `earned_at ASC, id ASC` 排序獲取批次，確保先獲得的點數先消耗
- **批次級行鎖**：每次只鎖定當前需要消耗的一個 PointLot 行，降低鎖持有時間和死鎖概率
- **原子性更新**：批次剩餘點數更新與主帳戶餘額更新在同一事務中提交
- **程式碼實現**：[redeem 方法中的批次消耗邏輯](../../app/Services/Point/PointService.php#L180-L205)

#### 6. 資料庫事務與行鎖

- `DB::transaction()` 包裝所有資料庫操作，內建最多3次死鎖重試
- 在事務內執行 `$customer->pointAccount()->lockForUpdate()->first()` 鎖定主帳戶
- 所有 PointLot 修改都在同一事務中，確保要麼全部成功，要麼全部回滾

#### 7. 觀測性與監控指標

- 指標1：`redis_lock_wait_time_seconds` - Redis 鎖等待時間分佈
- 指標2：`db_lock_deadlock_count` - 資料庫死鎖次數
- 指標3：`redis_fallback_count` - Redis 故障降級次數
- 告警閾值：Redis 降級次數 > 0 立即告警；死鎖次數 > 5/分鐘 告警

### 程式碼實現證據

- [executeInLock 方法](../../app/Services/Point/PointService.php#L47-L88) 實現 Redis 鎖 + DB 事務 + 降級邏輯
- [getOrCreatePointAccount 方法](../../app/Services/Point/PointService.php#L90-L145) 使用 lockForUpdate()
- [redeem 方法中的 PointLot 處理](../../app/Services/Point/PointService.php#L178-L210) 實現批次級鎖定
- Laravel DB::transaction 內建死鎖重試機制，最多重試3次

## Alternatives Considered

### 1. Only DB Row Lock (SELECT FOR UPDATE)

- **優點**:
    - 簡單，MySQL 原生支援
    - 不需要依賴外部的 Redis
    - 實現成本低

- **缺點**:
    - 所有應用程式實例直接競爭資料庫鎖
    - 在高並發場景下資料庫壓力大
    - 應用層無法實現更靈活的排隊或限流策略
    - 鎖等待全部在資料庫層處理，容易積累

### 2. Only Redis Lock

- **優點**:
    - 分散式應用層協調，分攤資料庫壓力
    - 可以實現更複雜的鎖定策略
    - 緩解資料庫的並發壓力

- **缺點**:
    - Redis 不是事實的數據源，鎖釋放後仍可能發生競爭
    - 無法保證資料庫層級的一致性
    - 如果Redis鎖提前失效，仍然可能出現並發問題
    - 資料庫事務的一致性保證仍然需要行鎖來補充

### 3. Optimistic Lock (使用version欄位)

- **實現方式**: 添加 `version` 欄位，更新時 `WHERE version = current_version`
- **為什麼沒有採用**:
    - 樂觀鎖適用於寫衝突較少的場景
    - 點數交易是寫衝突概率較高的場景（同一客戶同時兌換）
    - 應用層需要處理更新失敗的重試邏輯
    - 用户體驗差，經常需要重試
    - 對於核心交易場景，悲觀鎖更可靠

### 4. Redis Lock + DB Row Lock (目前採用)

- **優點**:
    - 兩層鎖定，安全性最高
    - Redis 分攤大部分並發壓力，應用層先排队
    - 資料庫行鎖作為最後一道保險，防止Redis鎖失效的邊緣情況
    - 結合兩種方案的優點
    - 既分散了壓力，又保證了最終的一致性

- **缺點**:
    - 實現相對複雜
    - 需要依賴Redis的可用性
    - 兩層鎖定有死鎖的潛在風險（但已通過超時機制緩解）

## Consequences

### 正面影響

- 徹底防止了 Lost Update 和 Double Spend 問題
- 分佈式環境下的多實例部署安全
- Redis 分攤大部分並發壓力，保護資料庫
- 完整的交易日誌，便於問題追查
- 完善的鎖獲取順序和超時機制，死鎖風險極低
- Redis故障自動降級，系統可用性更高
- PointLot批次級鎖定，並發性能更好
- Laravel內建3次死鎖重試，進一步提升穩定性

### 風險與緩解

- **風險**: Redis 不可用導致性能下降
    - **緩解**: 自動降級到資料庫行鎖，一致性不受影響；Redis哨兵集群保證高可用；監控告警及時排查
- **風險**: 鎖等待時間過長導致使用者體驗差
    - **緩解**: 10秒的鎖等待超時；返回友好的"系統繁忙，請稍後再試"錯誤；監控鎖等待時間分佈
- **風險**: 死鎖仍然可能發生
    - **緩解**: 統一的鎖獲取順序（永遠按ID升序）；游標式批次消費減小鎖粒度；資料庫層的死鎖檢測和自動中斷；Laravel內建3次重試機制

## Exit Criteria

當系統滿足以下條件時，可考慮重新評估此鎖定策略：

1. 單租戶的日均點數交易超過500萬次，雙重鎖定的開銷成為性能瓶頸
2. 系統拆分為微服務架構，需要跨服務的分佈式事務協調
3. 引入了專用的分佈式鎖服務（如Redis Cluster + Redlock），可以簡化現有鎖定邏輯
4. 業務場景變更為寫衝突極低的場景，樂觀鎖成為更合適的選擇
