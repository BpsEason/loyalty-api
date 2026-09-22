# ADR-010: Membership Tier System

## Status

✅ Implemented

## Context

Loyalty Platform 需要根據會員累積價值提供不同會員等級，以鼓勵會員持續消費和參與平台活動。會員等級需要能夠：

- 依累積消費金額自動升級
- 依累積獲得點數自動升級
- 每個租戶可以獨立設定自己的會員等級體系
- 等級對應不同的權益，提升會員留存率

## Decision

### 架構設計

1. **MembershipTier 與 Tenant 關聯**：每個會員等級都綁定到特定租戶，實現租戶間的完全隔離
2. **Customer 保存目前 Membership Tier**：每個客戶記錄當前所屬的會員等級ID
3. **使用 threshold_type 判斷升級條件**：支援 'spend'（依累積消費）和 'points'（依累積點數）兩種升級模式
4. **等級具有排序與升級門檻**：透過 sort_order 和 upgrade_threshold 欄位控制等級順序和升級條件
5. **權益由 Membership Tier 設定**：預留 points_multiplier、discount_rate、free_shipping 等權益欄位

### 核心邏輯

- 客戶累積消費或點數增加時，自動觸發 updateMembershipTier() 檢查是否需要升級
- getEligibleTier() 方法根據當前累積值計算符合資格的最高等級
- 支援同一租戶內設定多組不同閾值類型的等級體系

## Consequences

### 正面影響

- ✅ Tenant 可以自行設定會員制度，不需要把會員等級寫死在程式中
- ✅ 可以依不同業務需求選擇使用消費或點數作為升級條件
- ✅ 升級邏輯自動化，減少人工管理成本
- ✅ 權益欄位已預留，未來擴展彈性高

### 目前限制

- ⚠️ 權益欄位（points_multiplier, discount_rate, free_shipping）僅完成資料層設定
- ⚠️ 尚未整合到實際交易結算流程中，權益尚未自動生效
- ⚠️ 僅支援自動升級，尚未實作降級邏輯
