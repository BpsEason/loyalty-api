# ADR-012: Audit Logging System

## Status

✅ Implemented

## Context

Loyalty Platform涉及會員資料、點數、Campaign、Reward等敏感資料的管理操作，需要完整的操作追蹤機制：

- 誰在什麼時間對什麼資料做了什麼操作
- 資料修改前後的值是什麼，以便問題排查
- 不同租戶的操作記錄必須隔離，避免跨租戶存取
- 超級管理員需要能夠查看所有租戶的操作記錄
- 支援審計記錄的匯出，以滿足合规要求

沒有審計日誌的情況下，無法追溯問題根源，也無法滿足企業的稽核需求。

## Decision

### 架構設計

1. **採用owen-it/laravel-auditing套件**：基於成熟的Laravel審計套件，減少重複造輪
2. **自訂Audit模型繼承**：擴展BaseAudit加入BelongsToTenant trait，實現租戶隔離
3. **自訂TenantResolver**：自動解析當前請求的租戶ID，自動綁定到審計記錄
4. **Filament後台整合**：建立AuditResource提供審計日誌的查詢、篩選、查看功能
5. **Excel匯出功能**：支援將審計記錄匯出為Excel檔案，供離線分析
6. **權限控制**：透過AuditPolicy限制審計記錄唯讀，禁止任何修改或刪除操作

### 記錄內容

每條審計記錄包含：

- 操作人：user關聯，記錄是誰執行的操作
- 操作類型：created/updated/deleted/restored
- 操作對象：auditable_type + auditable_id，記錄操作的是哪個模型的哪筆資料
- 租戶資訊：tenant_id，實現租戶隔離
- 客户端資訊：IP地址、User-Agent、存取URL
- 資料變更：old_values與new_values，記錄修改前後的所有欄位值
- 操作時間：created_at，精確到秒的時間戳

### 租戶隔離機制

- 超級管理員(isSuperAdmin())：可查看所有租戶的審計記錄
- 一般租戶使用者：只能查看所屬租戶的審計記錄
- 由Model層的BelongsToTenant全域作用域自動處理，確保不會跨租戶洩漏

## Consequences

### 正面影響

- ✅ 可以完整追蹤所有後台管理操作，滿足稽核需求
- ✅ 發生問題時可以透過審計日誌快速定位操作人與修改內容
- ✅ 租戶隔離機制確保不同租戶無法互相查看操作記錄
- ✅ Excel匯出功能支援合规性審查的資料匯出需求
- ✅ 審計記錄唯讀，確保日誌本身的不可篡改性
- ✅ 僅有標記為Auditable的模型才會產生審計記錄，可精準控制覆蓋範圍

### 目前限制

- ⚠️ 只有實作了Auditable介面的模型才會產生審計記錄，並非所有模型都已覆蓋
- ⚠️ 審計日誌本身沒有自動清理機制，長期累積可能占用較多儲存空間
- ⚠️ 大量審計記錄匯出時可能影響系統效能，建議分次匯出
- ⚠️ 僅支援Filament後台查詢，尚未提供API介面供其他系統存取
