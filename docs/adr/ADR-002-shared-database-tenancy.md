# ADR-002: Shared Database Multi-Tenancy

## Context

系統需要支援多租戶（Multi-Tenant）架構，讓不同的客戶（Tenant）可以在同一個系統實例中運行，彼此的數據完全隔離。

目前的實現採用：

```text
Tenant A ─┐
Tenant B ─┼── Shared MySQL Database
Tenant C ─┘
```

所有租戶共享同一組資料表，透過 `tenant_id` 欄位進行數據隔離。

## Isolation Mechanism

系統透過多層機制確保租戶隔離：

### 1. 資料庫層級

- 主要租戶資源（customers、point_accounts、point_transactions、campaigns 等）都包含 `tenant_id` 欄位與 tenants 表建立關聯
- 資料庫層級的外鍵約束確保引用完整性
- 最後一道防線：防止非法數據寫入

### 2. 模型層級 (`BelongsToTenant`)

- [BelongsToTenant trait](../../app/Models/Concerns/BelongsToTenant.php) 自動為所有租戶資源添加全局作用域
- 創建實體時自動填入當前租戶ID（超級管理員除外，可手動指定）
- 超級管理員例外，可以跨租戶操作

### 3. 全域作用域 (Global Scope)

- 自動在所有查詢中添加 `tenant_id = current_tenant_id` 條件
- 一般 Eloquent 查詢會自動套用 tenant scope，降低因遺漏 tenant filter 所造成的跨租戶查詢風險；明確繞過 Global Scope 或直接使用 Query Builder / raw SQL 的程式碼仍需額外進行租戶驗證。
- 超級管理員除外，可跳過此限制

### 4. 租戶解析器 (`TenantResolver`)

- [TenantResolver](../../app/Support/Tenancy/TenantResolver.php) 負責解析當前請求的租戶
- 從認證的使用者中提取租戶信息，並存入租戶上下文
- 提供獲取當前租戶ID和租戶實體的統一接口

### 5. 租戶上下文 (`TenantContext`)

- [TenantContext](../../app/Support/Tenancy/TenantContext.php) 在整個請求生命週期中持有當前租戶
- 提供統一的接口設定、獲取當前租戶信息
- 支援上下文清除和重置

### 6. 應用程式層級驗證

- 在 API 控制器中再次驗證請求操作的資源確實屬於當前租戶 [範例](../../app/Http/Controllers/Api/V1/PointTransactionController.php)
- Policy 層級的授權檢查
- Filament 管理後台的租戶範圍過濾

## Why Shared Database?

### 1. Shared Database / Shared Tables (目前採用)

- **優點**:
    - 部署和維護簡單，只需維護一個資料庫
    - 跨租戶報表和分析容易實現
    - 資源利用率高，不需要為每個租戶預留資源
    - 備份恢復流程簡單
    - 對於租戶數量多但每個租戶數據量不大的場景最經濟

- **缺點**:
    - 若漏用全域作用域，可能導致跨租戶數據洩露
    - 單一資料庫可能成為瓶頸（但對於目前規模足夠）
    - 無法為特定租戶進行性能優化

### 2. Database per Tenant (未採用)

- **優點**:
    - 隔離等級最高，一個租戶的資料庫問題不會影響其他租戶
    - 可以為不同租戶選擇不同的區域部署
    - 超大租戶可以獨立進行性能優化

- **缺點**:
    - 維護成本指數增長，N個租戶需要維護N個資料庫
    - 部署複雜，需要自動化建立和配置新租戶的資料庫
    - 跨租戶查詢極端困難
    - 資源利用率低，很多小租戶的資料庫資源閒置

### 3. Schema per Tenant (未採用)

- **優點**:
    - 同一資料庫內的隔離，比Shared Database更安全
    - 比Database per Tenant維護成本低

- **缺點**:
    - 仍然需要為每個租戶創建和管理獨立的Schema
    - Laravel等框架對此模式支援有限
    - 遷移管理複雜，需要為所有租戶的Schema運行遷移

## Super Admin 跨租戶操作安全規範與審計要求

### 超級管理員的安全邊界

只有平台級的Super Admin角色才能跳過Global Scope的租戶隔離，進行跨租戶操作。其權限受到嚴格限制：

1. **最小權限原則**：Super Admin僅能執行平台運維必需的跨租戶操作，包括：
    - 租戶數據遷移或備份
    - 緊急故障排查與數據修正
    - 平台級的統計報表生成
    - 用戶投訴的人工核查

2. **操作前置確認**：所有跨租戶寫操作必須：
    - 在操作界面明確提示即將修改的租戶ID和資源信息
    - 要求管理員輸入操作備註（至少10個字）
    - 支持二次確認，防止誤操作

### 強制審計要求

所有Super Admin的跨租戶操作都被強制記錄審計日誌，不可刪除或修改：

1. **審計日誌必填字段**：
    - 操作人ID、帳號、姓名
    - 操作時間戳（精確到毫秒）
    - 操作類型（讀/寫/刪除）
    - 涉及的租戶ID、資源類型、資源ID
    - 修改前後的數據快照（僅寫操作需要）
    - 操作備註
    - 請求的IP地址和User-Agent

2. **審計日誌存儲**：
    - 獨立於業務數據庫的審計日誌存儲，保留至少1年
    - 日誌一經寫入不可修改，只追加
    - 每日自動備份審計日誌到離線存儲

3. **異常操作告警**：
    - 監控Super Admin的操作頻率，1小時內跨租戶寫操作超過10次觸發告警
    - 非工作時間（00:00-06:00）的跨租戶寫操作立即告警
    - 未知IP來源的Super Admin登錄和操作立即告警

### 程式碼層級的強制保證

- [BelongsToTenant trait](../../app/Models/Concerns/BelongsToTenant.php) 中，只有通過明確的`withoutTenancy()`方法才能跳過全局作用域
- 所有調用`withoutTenancy()`的地方都必須記錄審計日誌
- 所有`withoutTenancy()`的呼叫點必須通過Code Review強制檢查，並在CI中加入靜態掃描規則
- Filament後台的跨租戶資源訪問都被中間件截獲並記錄
- API層的跨租戶寫操作需要額外的簽權驗證，防止前端直接調用

## Major Risk: Tenant Isolation Failure

> **Tenant isolation failure is a correctness/security issue, not merely a filtering issue.**

如果某個查詢忘記添加租戶過濾條件，可能導致：

- 租戶A可以讀取租戶B的客戶數據
- 租戶A可以修改租戶B的點數餘額
- 租戶A可以查看租戶B的所有交易記錄

因此，租戶隔離必須在所有層級進行驗證：

- ✅ Model 層：BelongsToTenant trait + Global Scope
- ✅ Query 層：所有查詢自動帶有tenant_id過濾
- ✅ Service 層：業務邏輯再次驗證租戶歸屬
- ✅ API 層：控制器中手動驗證資源所屬租戶
- ✅ Admin Panel 層：Filament資源自動套用租戶範圍
- ✅ Authorization 層：Policy層級的租戶權限檢查

## Consequences

### 正面影響

- 開發和維護成本低
- 新租戶上線快速，只需創建Tenant記錄即可
- 資源效率高
- 跨租戶的系統級分析容易實現

### 風險與緩解

- **風險**: 全域作用域遺忘導致數據洩露
    - **緩解**: 寫測試驗證所有主要資源的隔離性；程式碼審查重點檢查查詢語句
- **風險**: 單一資料庫性能瓶頸
    - **緩解**: 適當的索引設計；讀寫分離；當租戶數量和數據量增長到一定程度再考慮拆分
