# WebSocket / Laravel Reverb 詳細文件

本專案使用 WebSocket 技術實現會員點數異動的即時通知，採用以下技術棧：
- Laravel Broadcasting
- Laravel Reverb
- Redis
- Redis Queue
- Laravel Echo
- Pusher JS

用途是讓會員點數異動可以透過 WebSocket 即時通知前端，實現頁面無需刷新即可獲得最新的點數餘額。

## Architecture

資料流：
```text
PointService
↓
DB Transaction Commit
↓
DB::afterCommit()
↓
PointsUpdated
↓
Redis Queue
↓
Queue Worker
↓
Laravel Reverb
↓
WebSocket
↓
Laravel Echo
↓
Frontend
```

特別說明：
- `PointsUpdated` 使用 `ShouldBroadcast` 介面
- WebSocket 不參與核心 DB transaction，確保即時通訊失敗不影響核心交易
- `DB::afterCommit()` 確保交易成功 commit 後才 dispatch WebSocket event
- WebSocket 失敗不應影響核心點數交易
- 現有 PointEarned / PointRedeemed Outbox 流程維持獨立，WebSocket 未強行整合進 Outbox

## Event

事件名稱：
```text
Event: points.updated
```

頻道格式：
```text
tenant.{tenantId}.member.{memberId}
```

例如：
```text
tenant.1.member.1
```

因為使用 Private Channel，前端 Echo 使用範例：
```javascript
Echo.private('tenant.1.member.1')
  .listen('.points.updated', (data) => {
    console.log(data);
  });
```

注意：
```text
Echo.private() 不需要自行加入 private- 前綴，Laravel Echo 會自動處理。
```

## Payload

`PointsUpdated::broadcastWith()` 的 payload 結構：
```json
{
  "member_id": 1,
  "transaction_id": 1234,
  "delta": 100,
  "balance": 1200,
  "occurred_at": "2026-09-30T00:00:00.000000Z"
}
```

| 欄位             | 說明                                      |
|------------------|-------------------------------------------|
| member_id        | 會員 ID                                   |
| transaction_id   | 點數交易 ID                               |
| delta            | 本次點數變動量，增加為正數、扣除為負數    |
| balance          | 異動後點數餘額                            |
| occurred_at      | 事件發生時間                              |

## Configuration

必要的環境變數設定：
```env
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis

REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...

VITE_REVERB_APP_KEY=...
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8888
VITE_REVERB_SCHEME=http
```

## Docker

Docker 中相關服務：
```text
app
queue-worker
redis
reverb
```

Reverb 對外 WebSocket endpoint：
```text
ws://localhost:8888
```

## Private Channel Authorization

Private Channel 的伺服器端授權透過 `routes/channels.php` 進行驗證。

授權原則：
- tenant isolation：嚴格的租戶隔離，只有所屬租戶的使用者才能存取
- 使用登入使用者身份進行驗證，完全依賴已認證的使用者身份
- 不信任前端傳入的 tenant_id，必須透過登入使用者的 tenant_id 進行驗證
- member/customer 身份必須驗證：驗證該 memberId 確實存在於該租戶下
- 不會假設 `member_id === user.id`，member_id 對應的是 Customer 模型，user.id 是後台管理員

## Testing

WebSocket 測試指令：
```bash
docker compose exec app php artisan websocket:test 1 1
```

參數說明：
```text
tenant_id = 1
member_id = 1
```

測試流程：
```text
websocket:test
↓
PointsUpdated
↓
Redis Queue
↓
queue-worker
↓
Reverb
```

## Frontend Verification

瀏覽器實際驗證方式：
1. 啟動前端：
   ```bash
   npm run dev
   ```
2. 開啟前端 / Filament 管理介面
3. 開啟 Chrome DevTools
4. Network → WS
5. 確認 WebSocket connection 已建立
6. 確認 Private Channel subscription 成功
7. 執行測試指令：
   ```bash
   docker compose exec app php artisan websocket:test 1 1
   ```
8. 確認 Console 收到 `points.updated` 事件，並驗證 payload 正確性

## Troubleshooting

常見問題排查：

### Reverb server 日誌
```bash
docker compose logs loyalty-reverb --tail=100
```

### Queue Worker 日誌
```bash
docker compose logs queue-worker --tail=100
```

### Redis 連線測試
```bash
docker compose exec redis redis-cli ping
```
預期回應：
```text
PONG
```

### Laravel 配置重載
```bash
docker compose exec app php artisan config:clear
```

## Current Status
```text
WebSocket integration status:
Backend pipeline verified:
PointsUpdated → Redis Queue → Queue Worker → Reverb

Frontend browser reception:
需透過 Chrome DevTools 實際確認 Browser WebSocket / Echo 是否收到 points.updated。
```