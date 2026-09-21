# ADR-008: Coupon System

## Related ADRs

- [ADR-002: Shared Database Multi-Tenancy](ADR-002-shared-database-tenancy.md) - 多租戶隔離基礎
- [ADR-003: Point Transaction Locking](ADR-003-point-transaction-locking.md) - 點數交易鎖定機制，優惠券系統需與其完全對齊
- [ADR-006: Idempotency Strategy](ADR-006-idempotency-strategy.md) - 冪等性保證，適用於優惠券領取與核銷操作
- [ADR-007: Point Lot & Expiration Strategy](ADR-007-point-lot-expiration-strategy.md) - 點數批次與過期處理，混合支付場景需協同工作

## Context

系統需要擴展優惠券（Coupon）系統來支援台灣常見的行銷場景，包含折扣券、滿減券、買一送一等多種券種。優惠券系統需與現有點數系統深度整合，支援「點數+優惠券」混合支付場景，同時必須保持與ADR-003完全一致的一致性保證，防止超發、重複核銷等核心業務問題。

### 核心挑戰

1. 支援台灣市場常見的優惠券類型，滿足租戶彈性配置需求
2. 處理優惠券領取場景的超發問題（限量券併發領取）
3. 防止同一張會員優惠券被重複核銷
4. 混合支付場景下，優惠券核銷與點數扣減的原子性保證
5. 優惠券過期自動處理，與點數過期機制協同
6. 多租戶隔離，確保不同租戶的優惠券規則完全獨立

## Decision

### 1. 支援的台灣常見券種

基於台灣電商與實體零售的常見場景，優先支援以下券種：

| 券種類型   | 業務場景         | 實現方式                                        |
| ---------- | ---------------- | ----------------------------------------------- |
| 金額折扣券 | 全店折現NT$100   | `discount_type=fixed_amount` + `value=100`      |
| 比例折扣券 | 全館85折         | `discount_type=percentage` + `value=0.85`       |
| 滿額減免券 | 滿NT$500減NT$50  | `discount_type=fixed_amount` + `min_spend=500`  |
| 滿件折扣券 | 買3件第2件半價   | 基於購物車商品數量的複合規則，透過rules欄位實現 |
| 買一送一券 | 指定商品買一送一 | `buy_x_get_y_free`規則，關聯特定SKU             |
| 免運費券   | 免運費優惠       | `shipping_free`標記，計算運費時識別             |
| 點數加成券 | 消費獲得2倍點數  | `point_multiplier=2.0`，影響點數發放量          |

所有複雜規則透過`CouponTemplate.rules` JSON欄位存儲，支援租戶自定義擴展，避免隨業務需求不斷新增欄位。**重要提醒**：buy_x_get_y_free、point_multiplier等進階規則存放於此JSON欄位，所有複雜規則必須有對應的單元測試與集成測試，確保邏輯正確性。

### 2. 模型設計（與現有coupon.md模型對齊，欄位命名完全統一）

```text
CouponTemplate (優惠券模板)
├── tenant_id: 多租戶隔離
├── name: 券名稱
├── code: 模板唯一識別碼 (租戶內唯一)
├── type: 折扣類型 (fixed_amount/percentage/buy_x_get_y_free/point_multiplier/shipping_free)
├── discount_amount: 固定金額折扣值 (type=fixed_amount時使用)
├── discount_percentage: 比例折扣值 (type=percentage時使用，如0.85代表85折)
├── min_spend: 最低消費門檻
├── max_usage: 總發行數量上限 (null=無限)
├── used_count: 已發出數量
├── max_per_user: 單一用戶最多領取數量
├── is_active: 模板是否啟用
├── starts_at: 有效期開始時間
├── ends_at: 有效期結束時間
├── rules: JSON 複雜規則存儲（buy_x_get_y_free等進場景的擴展欄位）
└── created_by: 建立者管理員ID

UserCoupon (會員持有的優惠券)
├── tenant_id: 多租戶隔離
├── coupon_template_id: 關聯模板
├── customer_id: 關聯會員
├── code: 優惠券唯一兌換碼 (全局唯一)
├── status: 狀態 (available/used/expired/cancelled)
├── claimed_at: 領取時間
├── used_at: 使用時間
├── expired_at: 過期時間
└── redemption_id: 關聯的核銷記錄ID

CouponRedemption (優惠券核銷記錄)
├── tenant_id: 多租戶隔離
├── user_coupon_id: 關聯會員優惠券
├── order_id: 關聯的訂單ID
├── customer_id: 核銷時的會員ID
├── discount_amount: 實際折扣金額
├── original_total: 訂單原金額
├── final_total: 折扣後金額
├── metadata: JSON 存儲額外資訊（商品明細、POS編號等）
└── created_at: 核銷時間
```

### 3. 與點數混合支付的規則

當交易同時使用優惠券和點數時，嚴格遵循以下計算順序：

1. **先套用優惠券折扣**：計算優惠券折扣後的應付金額
2. **再扣減點數**：基於折扣後的金額計算可使用的點數上限
3. **原子性保證**：優惠券狀態變更與點數扣減必須在同一事務中完成，要麼全部成功，要麼全部回滾

示例：

- 訂單原金額：NT$1000
- 使用NT$100金額券 → 折扣後應付NT$900
- 點數可折抵上限 = 折扣後應付金額 = NT$900（同時受會員點數餘額與系統單筆交易點數上限限制）
- 若點數1點抵NT$1，最多可使用900點 → 最終支付NT$0 + 900點

### 4. 完整的鎖定與一致性策略（與ADR-003完全對齊）

#### 4.1 領取場景鎖定策略（限量券防超發）

```text
Redis Distributed Lock (模板級鎖)
        ↓
DB::transaction() (最多3次死鎖重試)
        ↓
CouponTemplate::lockForUpdate() 鎖定模板行
        ↓
驗證總發行數量上限 (used_count < max_usage)
        ↓
驗證單用戶領取上限 (該用戶已領數量 < max_per_user)
        ↓
used_count += 1
        ↓
創建UserCoupon記錄，狀態設為available
        ↓
Commit
```

- **Redis鎖鍵**：`coupon_template:{template_id}:tenant:{tenant_id}`
- **TTL策略**：15秒，確保領取流程完成前不會提前釋放
- **等待超時**：8秒，超時返回「系統繁忙，請稍後再試」

#### 4.2 核銷場景鎖定策略（單一優惠券防重複核銷）

```text
Redis Distributed Lock (會員級鎖，與點數系統共用相同策略)
        ↓
DB::transaction() (最多3次死鎖重試)
        ↓
UserCoupon::lockForUpdate() 鎖定會員優惠券行
        ↓
驗證狀態必須為available
        ↓
驗證未過期 (expired_at > NOW())
        ↓
如果涉及點數扣減：按ADR-003順序鎖定PointAccount和PointLot
        ↓
更新UserCoupon狀態為used，記錄used_at
        ↓
創建CouponRedemption記錄
        ↓
如果有點數扣減：執行點數扣減邏輯，創建PointTransaction
        ↓
Commit
```

- **鎖獲取順序**：永遠依資源ID升序加鎖，避免死鎖（與ADR-003完全對齊）
    1. 先鎖定UserCoupon（較小ID）
    2. 再鎖定PointAccount（較大ID，如果需要）
    3. 最後按順序鎖定需要修改的PointLot

#### 4.3 混合支付的原子性保證

所有操作都包在同一個MySQL事務中：

- UserCoupon狀態更新
- CouponRedemption記錄創建
- PointAccount餘額更新
- PointLot批次扣減
- PointTransaction記錄創建

任一環節失敗，整個事務回滾，所有狀態還原。

### 5. 超發防護多層機制

1. **資料庫層強制唯一約束**：`user_coupons`表建立`unique(tenant_id, coupon_template_id, customer_id)`複合唯一索引，當`max_per_user=1`時從資料庫層級根本防止單用戶重複領取；若業務允許領取多次（`max_per_user>1`），則移除唯一約束，透過應用層在模板行鎖保護下計數該用戶已領數量
2. **模板行鎖**：領取時對CouponTemplate執行`lockForUpdate()`，串行化used_count更新，確保總發行量不超過max_usage
3. **Redis分散式鎖**：應用層排隊，降低資料庫鎖競爭
4. **最終一致性校驗**：定時任務每日核對`used_count`與實際`user_coupons`數量，自動修復不一致

### 6. 重複核銷防護多層機制

1. **資料庫行鎖**：核銷時對UserCoupon執行`lockForUpdate()`，確保只有一個交易能讀取到available狀態
2. **數據庫約束**：`user_coupons.redemption_id`建立唯一約束，防止同一張券關聯多個核銷記錄
3. **狀態機約束**：應用層嚴格狀態轉換，只有available狀態能進入used狀態
4. **冪等性中間件**：核銷API與點數API同樣應用ADR-006的冪等性策略，防止用戶端重試導致重複核銷

### 7. 過期處理機制

1. **定時任務每日執行**：掃描`user_coupons`中`expired_at < NOW()`且`status=available`的記錄，批量更新為expired狀態
2. **即時檢查**：每次展示用戶可用優惠券列表時，過濾掉已過期但尚未被定時任務處理的記錄
3. **核銷前強檢查**：核銷流程第一步驗證`expired_at > NOW()`，拒絕過期券的使用
4. 與點數過期機制協同：同一個定時任務處理優惠券和點數過期，集中維護

## Alternatives Considered

### 1. 將優惠券與點數系統完全分離（不採用）

- **優點**：模塊邊界清晰，耦合度低
- **缺點**：混合支付場景無法保證原子性，容易出現狀態不一致；重複實現鎖定和一致性邏輯，維護成本高
- **為什麼不採用**：核心交易的一致性優先於模塊的完全解耦，共用同一套鎖定策略能最大限度減少bug風險

### 2. 使用樂觀鎖處理優惠券領取（不採用）

- **優點**：實現簡單，並發性能好
- **缺點**：高並發場景下更新失敗率高，用戶體驗差；無法避免超發風險
- **為什麼不採用**：限量券領取是典型的寫衝突高的場景，悲觀鎖的可靠性更重要

### 3. Redis計數器處理領取數量（不採用）

- **優點**：性能高，支持高併發領取
- **缺點**：Redis故障可能導致計數不准確，無法與資料庫事務保持原子性；需要額外的雙寫一致性邏輯
- **為什麼不採用**：本系統的核心原則是「資料庫為唯一權威」，Redis僅能用作快取，不能作為關鍵計數的存儲

### 4. 與ADR-003完全一致的雙重鎖定策略（目前採用）

- **優點**：與現有點數系統共用同一套一致性邏輯，開發和維護成本低；開發人員已熟悉此鎖定機制；避免重複造輪子帶來的潛在bug
- **缺點**：耦合度略高，但在Modular Monolith架構下是可接受的
- **為什麼採用**：完全符合系統的一致性優先原則，最大限度降低線上故障風險

## Consequences

### 正面影響

- 完整支援台灣市場常見的優惠券場景，滿足租戶業務需求
- 與現有點數系統的鎖定策略完全對齊，開發團隊無需學習新的一致性模型
- 多層防護機制徹底避免超發、重複核銷等核心業務問題
- 混合支付場景的原子性保證，防止優惠券用了但點數沒扣、或點數扣了但優惠券沒用上的不一致狀態
- 多租戶隔離機制與ADR-002保持一致，不會引入跨租戶資料洩漏風險
- 過期處理機制與點數系統協同，代碼集中維護

### 風險與緩解

- **風險**：領取場景的鎖粒度較粗（模板級鎖），可能影響並發性能
    - **緩解**：Redis鎖在應用層排隊，絕大多數並發請求在進入資料庫前已被串行化；模板級鎖的粒度假設同一時間段內同一模板的領取請求不會特別高，符合絕大多數租戶的實際場景
- **風險**：混合支付場景的鎖持有時間較長，可能增加死鎖概率
    - **緩解**：嚴格遵循ID升序的鎖獲取順序；Laravel內建3次死鎖重試機制；監控死鎖發生頻率，及時優警
- **風險**：CouponTemplate的used_count可能與實際UserCoupon數量不一致
    - **緩解**：每日定時任務自動校驗和修復；記錄不一致事件，觸發告警

## Exit Criteria

當系統滿足以下任一條件時，可考慮重新評估此優惠券系統設計：

1. 單租戶的日均優惠券領取/核銷交易超過300萬次，或鎖等待時間P99超過2秒，現有鎖定策略的開銷成為性能瓶頸
2. 系統拆分為微服務架構，優惠券與點數系統部署在不同服務實例上，需要跨服務分佈式事務
3. 引入了複雜的優惠券組合場景，現有模型無法靈活支援，需要重新設計規則引擎
4. 租戶普遍要求實時性極高的限量秒殺場景，需要專門的高並發搶購系統
