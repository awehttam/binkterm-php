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
    "poll_creation_cost": 15
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
