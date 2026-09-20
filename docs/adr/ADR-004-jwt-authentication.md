# ADR-004: JWT Authentication

## Context

本系統需要支援兩種截然不同的使用場景：

1. **External API 消費者**：第三方系統、POS、行動應用、網站需要透過 API 存取資源
2. **Admin Panel 使用者**：內部管理人員透過瀏覽器的 Filament 後台管理系統

這兩種場景對身份驗證有完全不同的需求：

- API 消費者需要無狀態的、可跨域名的驗證機制
- 後台管理使用者需要傳統的 Session 基礎的網頁驗證體驗

## Decision

系統分離兩種驗證上下文：

```text
External API
    ↓
JWT Authentication (stateless)
    ↓
/api/v1/*


Admin Panel (Filament)
    ↓
Web Session Authentication (stateful)
    ↓
/admin/*
```

### API 層：JWT 驗證

API 路由採用 JWT（JSON Web Token）進行身份驗證，原因：

- 無狀態，不需要伺服器存儲會話信息
- 支援分散式部署，多個應用實例都可以驗證 token
- 適合跨域的 API 調用場景
- 第三方系統集成簡單，不需要處理 Cookie
- token 中可以承載用戶身份、租戶信息等聲明

### Admin 層：Session 驗證

Filament 管理後台保持使用 Laravel 原生的 Session 認證：

- 提供良好的網頁使用者體驗，自動處理登錄狀態
- 與 Filament 的權限系統原生集成
- 支援 remember me、登出等傳統網頁功能
- 內建的 CSRF 保護機制

## Alternatives Considered

### 1. Session 用於所有場景

- **優點**:
    - 統一的驗證機制，代碼重複性低
    - Laravel 原生支援完善
    - 安全模型成熟

- **缺點**:
    - 不適合第三方系統集成，需要處理 Cookie
    - 有狀態，分散式部署需要共享 Session 存儲
    - 跨域場景下配置複雜
    - API 服務的無狀態特性被破壞

### 2. JWT 用於所有場景（包括 Admin）

- **優點**:
    - 統一的驗證機制
    - 完全無狀態，易於擴展

- **缺點**:
    - 破壞了 Filament 與 Laravel Session 的原生集成
    - 需要重新實現登錄、記住我等網頁常用功能
    - JWT 無法主動撤銷（除非引入黑名單機制，增加複雜度）
    - 網頁場景下 Session 是更成熟的選擇
    - 開發成本高，收益有限

### 3. OAuth2.0 / Passport

- **優點**:
    - 業界標準的授權框架
    - 支援更複雜的權限委託場景
    - 成熟的生態系統

- **缺點**:
    - 對於目前的使用場景過於複雜
    - 系統目前只需要服務自己的前端和簡單的第三方集成，不需要複雜的授權流程
    - Passport 增加了系統的部署和維護複雜度
    - JWT 已經滿足當前的身份驗證需求

## Consequences

### 正面影響

- 兩種場景都使用了最適合的驗證方案
- API 保持無狀態，便於水平擴展
- Admin 後台保持與 Laravel/Filament 生態的原生集成
- 開發和維護成本可控，不需要為了統一而勉強使用不合適的技術
- 身份驗證上下文完全隔離，降低安全風險

### 負面影響

- 系統維護兩套不同的驗證代碼路徑
- 新開發者需要理解兩種驗證機制的區別和使用場景
- 需要確保兩套驗證系統的權限模型保持一致

### 安全考慮

- JWT token 使用短期有效期，並支援刷新機制
- 存儲在 HTTP Only Secure Cookie 中（如果用於瀏覽器端）
- 敏感操作仍然需要重新驗證
- Admin Session 使用 SameSite Cookie 防止 CSRF 攻擊

## Exit Criteria

以下場景出現時重新評估此決策：

- 需要支援複雜的第三方應用授權場景（此時考慮 OAuth2.0）
- API 和 Admin 的身份驗證需求趨同
- 出現與雙驗證機制相關的無法解決的安全問題
- 團隊希望統一技術棧以降低維護成本
