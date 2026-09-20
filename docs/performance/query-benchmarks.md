# Query Benchmarks

本文檔用於記錄實際的數據庫查詢性能測試結果。所有數據必須基於實際的 EXPLAIN ANALYZE 執行結果，不得虛構。

---

## 基準測試模板

### 查詢場景

[在此描述查詢的業務場景，例如：查詢某客戶的點數交易歷史]

### SQL 語句

```sql
[在此粘貼實際的 SQL 查詢語句]
```

### 測試數據集

| 指標         | 數值             |
| ------------ | ---------------- |
| 客戶數量     | Not measured yet |
| 點數交易數量 | Not measured yet |
| 測試環境     | Not measured yet |
| MySQL 版本   | Not measured yet |

---

## 優化前的性能

### EXPLAIN ANALYZE 輸出

```
[在此粘貼實際的 EXPLAIN ANALYZE 輸出結果]
```

### 關鍵指標

| 指標       | 數值             |
| ---------- | ---------------- |
| 執行時間   | Not measured yet |
| 掃描行數   | Not measured yet |
| 返回行數   | Not measured yet |
| 使用的索引 | Not measured yet |

---

## 索引優化（如果有）

### 添加的索引

```sql
[在此描述添加的數據庫索引]
```

### 優化後的性能

#### EXPLAIN ANALYZE 輸出

```
[在此粘貼優化後的 EXPLAIN ANALYZE 輸出結果]
```

#### 關鍵指標

| 指標       | 數值             |
| ---------- | ---------------- |
| 執行時間   | Not measured yet |
| 掃描行數   | Not measured yet |
| 返回行數   | Not measured yet |
| 使用的索引 | Not measured yet |

---

## 結論

[在此寫入基於實際測量數據的結論，說明優化的效果]

Status: Not measured yet

---

## 已記錄的查詢場景

### 1. 點數交易歷史查詢

**業務場景**：查詢特定客戶的點數交易歷史，按時間倒序排列

**典型查詢模式**：

```sql
SELECT * FROM point_transactions
WHERE tenant_id = ?
  AND point_account_id = ?
ORDER BY created_at DESC;
```

**當前索引**：`(tenant_id, point_account_id, created_at)`

**索引設計理由**：

- `tenant_id`：租戶隔離，首先過濾不同租戶的數據
- `point_account_id`：定位到具體的點數帳戶
- `created_at`：支援按時間倒序排序，避免額外的排序操作

**預期效果**：覆蓋索引可以完全滿足查詢需求，不需要回表，查詢效率最高。

**測量狀態**：Not measured yet

---

### 2. 客戶列表分頁查詢

**業務場景**：在管理後台中列出當前租戶的所有客戶，支持分頁

**典型查詢模式**：

```sql
SELECT * FROM customers
WHERE tenant_id = ?
ORDER BY created_at DESC
LIMIT ? OFFSET ?;
```

**當前索引**：需要驗證 migrations 中的索引設置

**測量狀態**：Not measured yet

---

### 3. 活動效果報表查詢

**業務場景**：統計某個行銷活動期間發放的點數總量

**典型查詢模式**：

```sql
SELECT SUM(amount) as total_points
FROM point_transactions
WHERE tenant_id = ?
  AND created_at BETWEEN ? AND ?
  AND reference_type = 'campaign';
```

**索引建議**：`(tenant_id, reference_type, created_at)`

**測量狀態**：Not measured yet

---

## 未來需要測量的查詢場景

1. 大數據量下的分頁查詢性能（100萬+ 行數據）
2. 並發查詢下的數據庫鎖等待時間
3. 複雜報表查詢的執行計劃分析
4. 讀寫分離後的查詢性能變化
5. 數據庫分片前的最後性能基準

---

## 性能監控建議

- 在生產環境啟用 MySQL 的慢查詢日誌
- 定期分析慢查詢日誌，識別性能瓶頸
- 對頻繁執行的複雜查詢建立專用的讀取副本
- 考慮對歷史數據進行分區，提高歷史數據查詢性能
- 建立性能基準，每次部署前後對比性能變化
