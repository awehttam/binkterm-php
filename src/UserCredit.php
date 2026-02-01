<?php

namespace BinktermPHP;

use PDO;

class UserCredit
{
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_DAILY_LOGIN = 'daily_login';
    public const TYPE_SYSTEM_REWARD = 'system_reward';
    public const TYPE_ADMIN_ADJUSTMENT = 'admin_adjustment';
    public const TYPE_NPC_TRANSACTION = 'npc_transaction';
    public const TYPE_REFUND = 'refund';

    /**
     * Credit a user's balance.
     *
     * @param int $userId
     * @param int $amount
     * @param string $description
     * @param int|null $otherPartyId
     * @param string $type
     * @return bool
     */
    public static function credit(
        int $userId,
        int $amount,
        string $description,
        ?int $otherPartyId = null,
        string $type = self::TYPE_PAYMENT
    ): bool {
        if(!self::isEnabled())
            return false;
        try {
            return self::transact($userId, abs($amount), $description, $otherPartyId, $type);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Debit a user's balance.
     *
     * @param int $userId
     * @param int $amount
     * @param string $description
     * @param int|null $otherPartyId
     * @param string $type
     * @return bool
     */
    public static function debit(
        int $userId,
        int $amount,
        string $description,
        ?int $otherPartyId = null,
        string $type = self::TYPE_PAYMENT
    ): bool {
        if(!self::isEnabled())
            return false;
        try {
            return self::transact($userId, -abs($amount), $description, $otherPartyId, $type);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Retrieve the current credit balance for the user.
     *
     * @param int $userId
     * @return int
     * @throws \Exception
     */
    public static function getBalance(int $userId): int
    {
        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare('SELECT credit_balance FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $balance = $stmt->fetchColumn();

        if ($balance === false) {
            throw new \Exception('User not found');
        }

        return (int)$balance;
    }

    /**
     * Perform a credit transaction for a user.
     *
     * @param int $userId
     * @param int $amount
     * @param string $description
     * @param int|null $otherPartyId
     * @param string $type
     * @return bool
     * @throws \Exception
     */
    public static function transact(
        int $userId,
        int $amount,
        string $description,
        ?int $otherPartyId = null,
        string $type = self::TYPE_PAYMENT
    ): bool {
        if(!self::isEnabled())
            throw new \Exception('Credit system is disabled');

        if (!is_int($amount) || $amount === 0) {
            throw new \Exception('Transaction amount must be a non-zero integer');
        }

        $validTypes = [
            self::TYPE_PAYMENT,
            self::TYPE_DAILY_LOGIN,
            self::TYPE_SYSTEM_REWARD,
            self::TYPE_ADMIN_ADJUSTMENT,
            self::TYPE_NPC_TRANSACTION,
            self::TYPE_REFUND
        ];
        if (!in_array($type, $validTypes, true)) {
            throw new \Exception('Invalid transaction type');
        }

        $description = trim($description);
        if ($description === '') {
            throw new \Exception('Transaction description is required');
        }

        $db = Database::getInstance()->getPdo();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT credit_balance FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $currentBalance = $stmt->fetchColumn();

            if ($currentBalance === false) {
                throw new \Exception('User not found');
            }

            $currentBalance = (int)$currentBalance;
            $newBalance = $currentBalance + $amount;

            if ($newBalance < 0) {
                throw new \Exception('Insufficient balance for transaction');
            }

            $updateStmt = $db->prepare('UPDATE users SET credit_balance = ? WHERE id = ?');
            $updateStmt->execute([$newBalance, $userId]);

            $insertStmt = $db->prepare('
                INSERT INTO user_transactions
                    (user_id, other_party_id, amount, balance_after, description, transaction_type, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, NOW())
            ');
            $insertStmt->execute([
                $userId,
                $otherPartyId,
                $amount,
                $newBalance,
                mb_substr($description, 0, 500),
                $type
            ]);

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Process daily login credits for the user.
     *
     * @param int $userId
     * @return bool
     * @throws \Exception
     */
    public static function processDaily(int $userId): bool
    {
        $credits = self::getCreditsConfig();
        if(!self::isEnabled())
            return false;

        $dailyAmount = (int)$credits['daily_amount'];
        if ($dailyAmount <= 0) {
            return false;
        }

        $delayMinutes = (int)$credits['daily_login_delay_minutes'];

        $db = Database::getInstance()->getPdo();
        $eligibleStmt = $db->prepare('
            SELECT (NOW() - created_at) >= (INTERVAL \'1 minute\' * ?) AS eligible
            FROM user_sessions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ');
        $eligibleStmt->execute([$delayMinutes, $userId]);
        $eligible = $eligibleStmt->fetchColumn();

        if (!$eligible) {
            return false;
        }

        $meta = new UserMeta();
        $lastDate = $meta->getValue($userId, 'last_daily_credit_date');
        $today = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        if ($lastDate === $today) {
            return false;
        }

        self::transact(
            $userId,
            $dailyAmount,
            'Daily login bonus',
            null,
            self::TYPE_DAILY_LOGIN
        );

        $meta->setValue($userId, 'last_daily_credit_date', $today);
        return true;
    }

    /**
     * Process 14-day return bonus for new users.
     * Awards bonus once when user logs in 14+ days after account creation.
     *
     * @param int $userId
     * @return bool
     * @throws \Exception
     */
    public static function process14DayReturn(int $userId): bool
    {
        $credits = self::getCreditsConfig();
        if(!self::isEnabled())
            return false;

        $bonusAmount = (int)$credits['return_14days'];
        if ($bonusAmount <= 0) {
            return false;
        }

        // Check if user has already received this bonus
        $meta = new UserMeta();
        $alreadyAwarded = $meta->getValue($userId, 'received_14day_bonus');
        if ($alreadyAwarded === '1') {
            return false;
        }

        $db = Database::getInstance()->getPdo();

        // Get the user's account creation date
        $userStmt = $db->prepare('
            SELECT created_at
            FROM users
            WHERE id = ?
        ');
        $userStmt->execute([$userId]);
        $createdAt = $userStmt->fetchColumn();

        if ($createdAt === false) {
            return false;
        }

        $creationTime = new \DateTime($createdAt, new \DateTimeZone('UTC'));
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $daysSinceCreation = $now->diff($creationTime)->days;

        // Award bonus if 14 or more days since account creation
        if ($daysSinceCreation >= 14) {
            self::transact(
                $userId,
                $bonusAmount,
                sprintf('Welcome back! Bonus for returning after %d days since joining', $daysSinceCreation),
                null,
                self::TYPE_SYSTEM_REWARD
            );

            // Mark as awarded (one-time bonus)
            $meta->setValue($userId, 'received_14day_bonus', '1');
            return true;
        }

        return false;
    }

    /**
     * Retrieve recent transactions for a user.
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public static function getTransactionHistory(int $userId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare('
            SELECT id, user_id, other_party_id, amount, balance_after, description, transaction_type, created_at
            FROM user_transactions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private static function getCreditsConfig(): array
    {
        $config = BbsConfig::getConfig();
        $credits = is_array($config['credits'] ?? null) ? $config['credits'] : [];

        $defaults = [
            'enabled' => true,
            'symbol' => '$',
            'daily_amount' => 25,
            'daily_login_delay_minutes' => 5,
            'approval_bonus' => 100,
            'netmail_cost' => 1,
            'echomail_reward' => 3,
            'crashmail_cost' => 10,
            'poll_creation_cost' => 15,
            'return_14days' => 50,
            'transfer_fee_percent' => 0.05
        ];

        $merged = array_merge($defaults, $credits);
        $merged['enabled'] = !empty($merged['enabled']);
        $symbolRaw = $merged['symbol'] ?? '$';
        $merged['symbol'] = trim((string)$symbolRaw);
        if ($merged['symbol'] === '' && !array_key_exists('symbol', $credits)) {
            $merged['symbol'] = '$';
        }
        $merged['daily_amount'] = max(0, (int)$merged['daily_amount']);
        $merged['daily_login_delay_minutes'] = max(0, (int)$merged['daily_login_delay_minutes']);
        $merged['approval_bonus'] = max(0, (int)$merged['approval_bonus']);
        $merged['netmail_cost'] = max(0, (int)$merged['netmail_cost']);
        $merged['echomail_reward'] = max(0, (int)$merged['echomail_reward']);
        $merged['crashmail_cost'] = max(0, (int)$merged['crashmail_cost']);
        $merged['poll_creation_cost'] = max(0, (int)$merged['poll_creation_cost']);
        $merged['return_14days'] = max(0, (int)$merged['return_14days']);
        $merged['transfer_fee_percent'] = max(0, min(1, (float)$merged['transfer_fee_percent']));

        return $merged;
    }

    public static function isEnabled()
    {
        $config = self::getCreditsConfig();
        return((bool)$config['enabled']);
    }

    /**
     * Get the credit cost for a named action.
     *
     * @param string $name The action name (e.g., 'poll_creation' becomes 'poll_creation_cost')
     * @param int $default Default cost if not configured
     * @return int
     */
    public static function getCreditCost(string $name, int $default = 0): int
    {
        $config = self::getCreditsConfig();
        $key = $name . '_cost';
        return max(0, (int)($config[$key] ?? $default));
    }

    /**
     * Get the reward amount for a named action.
     *
     * @param string $name The action name (e.g., 'poll_creation' becomes 'poll_creation_reward')
     * @param int $default Default reward if not configured
     * @return int
     */
    public static function getRewardAmount(string $name, int $default = 0): int
    {
        $config = self::getCreditsConfig();
        $key = $name . '_reward';
        return max(0, (int)($config[$key] ?? $default));
    }
}
