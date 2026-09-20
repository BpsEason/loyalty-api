# 失敗場景分析與防護機制

> 最後更新：2026-09-20

## 雙重消費 (Double Spend)

### 場景

同一帳戶同時發起多筆兌換請求，導致餘額變為負數，或同一點數被多次使用。

### 失敗風險

嚴重，直接影響系統信譽與財務正確性。

### 不變量

帳戶餘額永遠不能為負；同一點數只能被消費一次。

### 保護機制

1. Redis 分佈式鎖，防止跨進程並發
2. MySQL 行鎖 (`lockForUpdate()`)，確保同一數據庫連接內的串行化
3. 資料庫層級雙重檢查：`WHERE balance >= amount`
4. PointLot 級別的鎖，確保批次點數不會被重複扣除

### 測試覆蓋

- `tests/Feature/Services/Point/PointLotFifoTest.php` 中的並發場景
- `tests/Feature/Services/Point/PointTransactionConcurrencyTest.php` 中的連續壓力測試

### 觀察結果

未重現。所有測試場景下均能保持餘額正確性。

---

## 重複請求 (Duplicate Request)

### 場景

用戶重複提交同一請求，導致重複扣除點數或重複發放獎勵。

### 失敗風險

嚴重，導致重複交易。

### 不變量

同一個 Idempotency-Key + 租戶 + 請求體只能處理一次。

### 保護機制

1. 數據庫級冪等性記錄，`tenant_id + idempotency_key` 唯一約束
2. 請求哈希校驗，防止相同key但不同請求體的重試
3. 處理中狀態追蹤，避免同一請求並發處理
4. 已完成請求直接返回緩存的響應

### 測試覆蓋

- `tests/Feature/Services/Idempotency/DatabaseIdempotencyTest.php` 中的冪等性測試

### 觀察結果

未重現。相同請求重試只會產生一筆交易。

---

## 死鎖 (Deadlock)

### 場景

多個事務以不同順序獲取鎖，導致相互等待。

### 失敗風險

中，影響可用性，但可通過重試恢復。

### 不變量

系統必須能自動從死鎖中恢復，不留下不一致狀態。

### 保護機制

1. 統一的鎖獲取順序：永遠先鎖 PointAccount，再鎖 PointLot
2. 死鎖重試機制：`DB::transaction($callback, 3)`
3. 短事務設計，所有數據庫操作在一個事務中快速完成

### 測試覆蓋

- 系統級別的高並發測試（待完成）

### 觀察結果

在模擬高並發場景下未觀察到持續死鎖，重試機制正常工作。

---

## 租戶數據洩漏 (Tenant Data Leakage)

### 場景

租戶A能夠訪問或修改租戶B的數據。

### 失敗風險

嚴重，違反多租戶隔離原則。

### 不變量

所有租戶數據都帶有 tenant_id 標記，並通過全域作用域自動過濾。

### 保護機制

1. `BelongsToTenant` trait 自動添加全域作用域
2. 所有新模型（IdempotencyKey、PointLot）都使用該 trait
3. 資料庫外鍵約束確保關聯完整性
4. 冪等性鍵的租戶隔離：`tenant_id + idempotency_key` 唯一

### 測試覆蓋

- `tests/Feature/Services/Point/PointLotFifoTest.php` 中的租戶隔離測試
- `tests/Feature/Services/Idempotency/DatabaseIdempotencyTest.php` 中的租戶隔離測試

### 觀察結果

未重現。跨租戶數據訪問被全域作用域阻斷。

---

## 過期點數處理不一致

### 場景

點數過期時，批次剩餘點數與帳戶餘額不一致。

### 失敗風險

中，導致餘額計算錯誤。

### 不變量

所有批次的剩餘點數之和永遠等於帳戶餘額。

### 保護機制

1. 過期操作與點數批次更新在同一數據庫事務中
2. 同樣使用 Redis 鎖與行鎖保護過期操作
3. 批次級別的剩餘點數檢查

### 測試覆蓋

- 待補充專門的過期邏輯測試

### 觀察結果

未重現。過期操作的原子性保證數據一致性。
