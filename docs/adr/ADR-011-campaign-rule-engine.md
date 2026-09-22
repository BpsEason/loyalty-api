# ADR-011: Campaign Rule Engine

## Status

✅ Implemented

## Context

單純固定的Campaign邏輯無法滿足不同Loyalty活動的需求。不同租戶可能需要：

- 消費滿一定金額就給予額外點數
- 購買特定商品時給予獎勵點數
- 多個活動規則同時生效，按優先級處理
- 避免同一筆交易重複發放獎勵

傳統的硬編碼方式每新增一個活動都需要修改核心程式碼，無法快速回應業務需求。

## Decision

### 架構設計

1. **CampaignRule 資料化**：將活動規則儲存到資料庫，而非硬編碼在程式中
2. **支援兩種規則類型**：
    - `spend_threshold`：消費滿額規則，當消費金額 >= threshold 時觸發
    - `product`：指定商品規則，當購買商品包含規則中指定的任何商品時觸發
3. **與既有服務整合**：由RewardService處理規則評估，PointService負責實際點數發放，重用現有的點數交易一致性保證
4. **冪等性保護**：使用Redis鎖和RewardGrant記錄防止同一筆交易重複發放獎勵
5. **優先級機制**：每個規則可設定priority，數字越大優先級越高

### 核心流程

1. 交易發生時呼叫RewardService::processTransactionForCampaignRules()
2. 取得當前租戶所有活躍的Campaign及其規則
3. 依序評估每個規則的isEligible()條件
4. 符合條件的規則透過鎖機制確保只執行一次
5. 呼叫PointService::addPoints()發放點數，建立RewardGrant記錄

## Consequences

### 正面影響

- ✅ Campaign規則可以資料化，不需要每增加一個活動就修改核心PointService
- ✅ 規則與點數發放流程保持整合，繼承既有交易一致性保證
- ✅ 支援多租戶獨立設定活動規則
- ✅ 內建冪等性機制，防止重複發放
- ✅ 可快速新增新的規則類型，擴展性良好

### 目前限制

- ⚠️ 目前僅支援兩種規則類型，不是一個完整的通用規則引擎
- ⚠️ 規則之間僅有優先級排序，沒有複雜的邏輯組合（AND/OR）
- ⚠️ 規則評估是在應用層進行，無法支援非常複雜的大規模規則匹配
