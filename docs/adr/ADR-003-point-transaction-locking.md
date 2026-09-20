# ADR-003: Point Transaction Locking

## Problem

點數交易面臨的核心一致性問題是並發更新導致的 Lost Update（丟失更新）或 Double Spend（重複消費）。

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

## Current Solution (Decision)

目前 [PointService](file:///c:/laragon/www/loyalty-api/.kilo/worktrees/gilded-relative/app/Services/Point/PointService.php) 採用多層次鎖定策略：

```text
Redis Distributed Lock
        ↓
DB Transaction (with 3 retries)
        ↓
SELECT ... FOR UPDATE (行級鎖)
        ↓
Balance Validation
        ↓
Balance Update
        ↓
PointTransaction Ledger Creation
        ↓
Commit
```

### 層次解釋

1. **Redis 分散式鎖**
    - 鎖定鍵：`point_customer:{customer_id}:tenant:{tenant_id}`
    - 鎖定TTL：10秒
    - 等待超時：5秒
    - 使用 Laravel Cache::lock() 實現，預設使用 Redis 作為快取驅動

2. **資料庫事務**
    - `DB::transaction()` 包裝所有資料庫操作
    - 支援死鎖重試，Laravel 內建最多重試3次
    - 確保要麼所有操作成功，要麼全部回滾

3. **SELECT ... FOR UPDATE 行鎖**
    - 在事務內執行 `$customer->pointAccount()->lockForUpdate()->first()`
    - 阻止其他事務同時修改同一行數據
    - 確保餘額讀取和更新的串行化

4. **餘額驗證**
    - 在持有資料庫行鎖的情況下驗證餘額充足
    - 保證只有一個請求能通過驗證

5. **交易記錄持久化**
    - 每次餘額變更都創建對應的 PointTransaction 記錄
    - 維護完整的審計日誌
    - 與餘額更新在同一事務中提交

### 程式碼實現證據

- [executeInLock 方法](file:///c:/laragon/www/loyalty-api/.kilo/worktrees/gilded-relative/app/Services/Point/PointService.php#L47-L66) 實現 Redis 鎖 + DB 事務
- [getOrCreatePointAccount 方法](file:///c:/laragon/www/loyalty-api/.kilo/worktrees/gilded-relative/app/Services/Point/PointService.php#L71-L95) 使用 lockForUpdate()
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
- Redis 分攤並發壓力，保護資料庫
- 完整的交易日誌，便於問題追查
- 死鎖重試機制處理潛在的鎖排序問題

### 風險與緩解

- **風險**: Redis 不可用導致所有點數交易失敗
    - **緩解**: Redis 的高可用架構（哨兵或集群）；監控告警
- **風險**: 鎖等待時間過長導致使用者體驗差
    - **緩解**: 5秒的鎖等待超時；返回友好的"系統繁忙，請稍後再試"錯誤
- **風險**: 死鎖仍然可能發生
    - **緩解**: 資料庫層的死鎖檢測和自動中斷；Laravel 自動重試機制；統一的鎖獲取順序（永遠先鎖定客戶ID較小的帳戶）
