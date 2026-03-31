# Credits System

BinktermPHP includes an integrated credits economy that rewards user participation and allows charging for certain actions. Credits can be used to encourage quality content, manage resource usage, and gamify the BBS experience.

## Key Features

- Configurable credit costs and rewards for various activities
- Daily login bonuses to encourage regular participation
- New user approval bonuses to welcome approved members
- Bonus rewards for longer, higher-quality content
- Transaction history and balance tracking

## Default Credit Values

| Activity                               | Amount | Type   | Notes                                             |
|----------------------------------------|--------|--------|---------------------------------------------------|
| Daily Login                            | +25    | Reward | Awarded once per day after 5-minute delay         |
| New User Approval                      | +100   | Bonus  | One-time reward when account is approved          |
| Netmail Sent                           | -5     | Cost   | Private messages to other users                   |
| Echomail Posted                        | +3     | Reward | Public forum posts                                |
| Echomail Posted (approx. 2 paragraphs) | +6     | Bonus  | 2× reward for substantial posts (2+ paragraphs)   |
| Crashmail Sent                         | -10    | Cost   | Direct delivery bypassing uplink                  |
| Poll Creation                          | -15    | Cost   | Creating a new poll in voting booth               |
| File Upload                            | 0      | Cost   | Optional charge applied before a file upload      |
| File Upload                            | 0      | Reward | Optional reward applied after a successful upload |
| File Download                          | 0      | Cost   | Optional charge applied before a file download    |
| File Download                          | 0      | Reward | Optional reward applied after a successful download |

## Configuration

Credits are configured in `config/bbs.json` under the `credits` section. All values are customizable:

```json
{
  "credits": {
    "enabled": true,
    "symbol": "CR",
    "daily_amount": 25,
    "daily_login_delay_minutes": 5,
    "approval_bonus": 100,
    "netmail_cost": 1,
    "echomail_reward": 5,
    "crashmail_cost": 10,
    "poll_creation_cost": 15,
    "file_upload_cost": 0,
    "file_upload_reward": 0,
    "file_download_cost": 0,
    "file_download_reward": 0
  }
}
```

Settings can also be modified through the web interface at **Admin → BBS Settings → Credits System Configuration**.

## Transaction Types

- `payment` — User paid for a service
- `system_reward` — Automatic reward for activity
- `daily_login` — Daily login bonus
- `admin_adjustment` — Manual admin modification
- `npc_transaction` — Transaction with system/game
- `refund` — Credit refund

## Developer API

Extensions and WebDoors can integrate with the credits system:

```php
// Get user's balance
$balance = UserCredit::getBalance($userId);

// Award credits
UserCredit::credit($userId, 10, 'Completed quest', null, UserCredit::TYPE_SYSTEM_REWARD);

// Charge credits
UserCredit::debit($userId, 5, 'Used service', null, UserCredit::TYPE_PAYMENT);

// Get configurable costs/rewards
$cost = UserCredit::getCreditCost('action_name', $defaultValue);
$reward = UserCredit::getRewardAmount('action_name', $defaultValue);
```

## Disabling Credits

Set `"enabled": false` in the credits configuration to disable the entire system. When disabled, all credit-related functionality is hidden and no transactions are recorded.

---

## Developer: Adding a New Credit Type

When adding a new `UserCredit` credit or debit type, update all five of these locations:

1. **`src/UserCredit.php`** — add the new type with a fallback value to the `$defaults` array in `getCreditsConfig()`, and add validation in the `$merged` array processing.
2. **`bbs.json.example`** — add to the `credits` section with a comment explaining the type.
3. **`templates/admin/bbs_settings.twig`** — add a form field in the Credits section, load it in `loadBbsSettings()`, validate in `saveBbsCredits()`, and include it in the config object sent to the API.
4. **`routes/admin-routes.php`** — in the POST `/admin/api/bbs-settings` handler, add validation and include the field in `$config['credits']` with correct type casting.
5. **`README.md`** — document the new type in the credits section.

**Configuration priority** (highest to lowest): `data/bbs.json` → `bbs.json.example` → code defaults in `src/UserCredit.php`.

Always add code defaults first so the system works even without `bbs.json`. When updating a template to show credit info, use the `credits_enabled` Twig variable to conditionally show it.

### Credit Transaction Security

**CRITICAL**: Credit balance changes must only happen server-side. JavaScript requests business actions; the server decides whether credits are involved and performs the transaction internally.

```text
❌ POST /api/credits/deduct   ← never expose credit endpoints to JS
✅ POST /api/webdoor/game/buy-item  ← server handles credits internally, returns new balance
```

JS may display the balance value returned by the server and communicate it to parent windows via `postMessage`. It must never calculate or request credit modifications.
