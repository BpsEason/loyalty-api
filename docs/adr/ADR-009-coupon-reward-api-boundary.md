# ADR-009: Coupon / Reward API Boundary

## Related ADRs

- [ADR-002: Shared Database Multi-Tenancy](ADR-002-shared-database-tenancy.md) - 多租戶隔離基礎
- [ADR-006: Idempotency Strategy](ADR-006-idempotency-strategy.md) - 冪等性保證適用於客戶端API
- [ADR-007: Point Lot & Expiration Strategy](ADR-007-point-lot-expiration-strategy.md) - 點數批次管理
- [ADR-008: Coupon System](ADR-008-coupon-system.md) - 優惠券系統設計

## Context

本系統同時提供面向客戶的公開API和面向管理員的後台管理介面（Filament）。需要明確劃分哪些業務能力屬於公開API範疇，哪些僅限於後台管理，避免API邊界模糊導致的安全風險與維護負擔。

## Decision

### API 邊界劃分原則

所有API按照訪問對象與使用場景分為三類：

| 類別                | 描述                                         | 實現位置          | 認證要求              |
| ------------------- | -------------------------------------------- | ----------------- | --------------------- |
| Customer-facing API | 面向終端客戶/合作系統的API，用於會員自助操作 | `/api/v1/` 前綴   | JWT + 租戶解析        |
| Internal Admin API  | 僅供平台內部管理員使用的後台API              | Filament 自動生成 | Filament Session Auth |
| System Internal     | 僅供系統內部排程任務、事件監聽器調用         | 程式碼內部呼叫    | 無對外公開            |

### 1. Customer-facing Public API（客戶端可用）

所有在 `routes/api.php` 中定義的端點都屬於此類別，這些API是經過嚴格驗證、適合外部系統集成的穩定接口：

#### Authentication API

- `POST /api/v1/auth/login` - 用戶登錄獲取JWT
- `POST /api/v1/auth/logout` - 登出失效令牌
- `POST /api/v1/auth/refresh` - 刷新JWT
- `GET /api/v1/auth/me` - 獲取當前用戶信息

#### Customer API

- Customer CRUD - 會員資料管理
- `GET /customers/{customer}/qr-code` - 獲取會員QR碼（用於POS掃描）
- `POST /customers/identify` - 通過QR token識別會員

#### Points API

- `GET /customers/{customer}/points` - 查詢點數餘額
- `GET /customers/{customer}/point-transactions` - 查詢交易歷史
- `GET /customers/{customer}/point-transactions/expiring` - 查詢即將過期的點數
- `GET /customers/{customer}/point-transactions/{pointTransaction}` - 查詢單筆交易明細
- `POST /customers/{customer}/point-transactions` - 通用點數異動接口（支援所有交易類型）
- `POST /customers/{customer}/points/redeem` - POS端點數兌換語意捷徑

#### Coupon API

- `GET /customers/{customer}/coupons` - 查詢會員持有的優惠券
- `GET /customers/{customer}/coupons/{userCoupon}` - 查詢單張優惠券明細
- `POST /customers/{customer}/coupons/claim` - 領取優惠券
- `POST /customers/{customer}/coupons/{userCoupon}/redeem` - 核銷優惠券
- `GET /customers/{customer}/coupon-redemptions` - 查詢優惠券核銷歷史
- `POST /customers/{customer}/mixed-payment` - 混合支付（優惠券+點數）

#### Reward API

- `GET /customers/{customer}/reward-grants` - 查詢獎勵發放記錄
- `POST /customers/{customer}/rewards/grant` - 手動發放獎勵

### 2. Internal Filament Management Only（僅後台管理）

以下業務實體僅通過Filament後台管理，**不提供公開API**，以確保這些核心配置只能由授權管理員操作：

#### Campaign（行銷活動）

- **實體位置**：[Campaign Model](../../app/Models/Campaign.php)
- **管理介面**：[CampaignResource](../../app/Filament/Resources/CampaignResource.php)
- **為什麼不公開**：活動的創建、編輯、上下線屬於租戶管理員的權限範疇，開放公開API會帶來極高的安全風險（未經授權創建活動、篡改活動規則等）

#### CampaignReward（活動獎勵）

- **實體位置**：[CampaignReward Model](../../app/Models/CampaignReward.php)
- **管理介面**：[CampaignRewardResource](../../app/Filament/Resources/CampaignRewardResource.php)
- **為什麼不公開**：獎勵模板的配置是行銷活動的核心，必須由管理員控制，避免惡意調用大量發放獎勵

#### CouponTemplate（優惠券模板）

- **實體位置**：[CouponTemplate Model](../../app/Models/CouponTemplate.php)
- **管理介面**：[CouponTemplateResource](../../app/Filament/Resources/CouponTemplateResource.php)
- **為什麼不公開**：優惠券模板的創建、发行量限制等配置屬於管理操作，公開API可能導致惡意創建大量優惠券

### 3. System Internal Operations（系統內部）

以下操作僅由系統內部的排程任務、事件監聽器自動調用，**不對外公開任何API**：

- 點數自動過期處理：`RedeemPointsCommand` 每日凌晨自動執行
- 優惠券自動過期處理：系統排程任務
- 超級管理員跨租戶操作：僅限平台運維場景，需審計日誌記錄

### PointTransaction 統一接口設計理由

本系統採用**單一寫入接口**設計，所有點數異動都通過 `POST /customers/{customer}/point-transactions` 處理，通過 `type` 字段區分交易類型：

支援的類型：

- `earn` - 獲得點數
- `redeem` - 兌換點數
- `adjust` - 人工調整
- `refund` - 退款退回
- `expire` - 自動過期

#### 為什麼不拆分為 `/points/earn`、`/points/adjust` 等獨立端點？

1. **一致性保證**：所有點數寫操作都經過同一套鎖定、驗證、冪等性中間件，避免重複實現一致性邏輯
2. **審計軌跡統一**：不論哪種交易類型，都生成結構一致的 PointTransaction 記錄，便於後續審計與分析
3. **前端集成簡化**：客戶端只需學習一個接口的調用方式，不同業務場景僅需變更 type 字段
4. **避免端點爆炸**：如果未來新增新的交易類型，無需擴展API路由，只需擴展 type 枚舉
5. **中間件復用**：idempotent、tenant、auth等中間件只需在一個路由上配置，所有交易類型自動受益

## Code Evidence

- [routes/api.php](../../routes/api.php) - 公開API路由定義，驗證所有客戶端API確實存在
- [CampaignResource](../../app/Filament/Resources/CampaignResource.php) - Campaign僅在Filament後台管理
- [CampaignRewardResource](../../app/Filament/Resources/CampaignRewardResource.php) - CampaignReward僅在Filament後台管理
- [PointTransaction Model](../../app/Models/PointTransaction.php) - 定義所有支援的交易類型常量
- [PointTransactionController](../../app/Http/Controllers/Api/V1/PointTransactionController.php) - 統一的store方法處理所有交易類型

## Consequences

### 正面影響

- API邊界清晰，開發者容易理解哪些接口可以對外暴露
- 核心配置實體僅限後台管理，降低安全風險
- 點數交易接口統一，一致性邏輯只需要實現一次
- 前端集成成本降低，只需處理少量的核心API

### 風險與緩解

- **風險**：統一接口可能較難理解不同type的參數要求
    - **緩解**：完整的OpenAPI文檔中明確定義每種type需要的請求體結構；提供客戶端SDK封裝不同業務場景
- **風險**：後台管理功能無法通過API自動化
    - **緩解**：對於需要自動化的管理場景，可獨立創建Admin API組，同樣受到嚴格的權限控制，不與客戶端API混合
- **風險**：新的交易類型需要修改共享的驗證邏輯
    - **緩解**：通過策略模式將不同type的驗證邏輯拆分，保持store方法的整潔；新增type時必須伴隨完整的測試

## Exit Criteria

當系統滿足以下條件時，可重新評估此邊界劃分：

1. 有第三方開發者需要通過API創建和管理活動，現有的Filament後台無法滿足自动化需求
2. 點數交易類型爆炸增長，超過10種以上，統一接口的驗證邏輯變得過於複雜
3. 需要支持租戶級的自定義交易類型，靜態type枚舉無法滿足動態擴展需求
