<?php

namespace BinktermPHP;

use PDO;

class EchoareaSubscriptionManager
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Get all echoareas the user is subscribed to
     */
    public function getUserSubscribedEchoareas($userId)
    {
        $sql = "
            SELECT e.*, s.subscribed_at, s.subscription_type, s.is_active as subscribed
            FROM echoareas e
            JOIN user_echoarea_subscriptions s ON e.id = s.echoarea_id
            WHERE s.user_id = ? AND s.is_active = TRUE AND e.is_active = TRUE
            ORDER BY e.tag
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all available echoareas with subscription status for user
     */
    public function getAllEchoareasWithSubscriptionStatus($userId)
    {
        $sql = "
            SELECT e.*, 
                   s.is_active as subscribed,
                   s.subscription_type,
                   s.subscribed_at,
                   e.is_default_subscription
            FROM echoareas e
            LEFT JOIN user_echoarea_subscriptions s ON (e.id = s.echoarea_id AND s.user_id = ?)
            WHERE e.is_active = TRUE
            ORDER BY e.is_default_subscription DESC, e.tag
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Subscribe user to an echoarea
     */
    public function subscribeUser($userId, $echoareaId)
    {
        // Check if subscription already exists
        $existing = $this->getUserSubscriptionStatus($userId, $echoareaId);
        
        if ($existing) {
            if (!$existing['is_active']) {
                // Reactivate existing subscription
                $sql = "
                    UPDATE user_echoarea_subscriptions 
                    SET is_active = TRUE, subscribed_at = CURRENT_TIMESTAMP, subscription_type = 'user'
                    WHERE user_id = ? AND echoarea_id = ?
                ";
                $stmt = $this->db->prepare($sql);
                return $stmt->execute([$userId, $echoareaId]);
            }
            return true; // Already subscribed
        }
        
        // Create new subscription
        $sql = "
            INSERT INTO user_echoarea_subscriptions (user_id, echoarea_id, subscription_type) 
            VALUES (?, ?, 'user')
        ";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId, $echoareaId]);
    }

    /**
     * Unsubscribe user from an echoarea
     */
    public function unsubscribeUser($userId, $echoareaId)
    {
        $sql = "
            UPDATE user_echoarea_subscriptions 
            SET is_active = FALSE 
            WHERE user_id = ? AND echoarea_id = ?
        ";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId, $echoareaId]);
    }

    /**
     * Get user's subscription status for a specific echoarea
     */
    public function getUserSubscriptionStatus($userId, $echoareaId)
    {
        $sql = "
            SELECT * FROM user_echoarea_subscriptions 
            WHERE user_id = ? AND echoarea_id = ?
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $echoareaId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Check if user is subscribed to an echoarea
     */
    public function isUserSubscribed($userId, $echoareaId)
    {
        $sql = "
            SELECT COUNT(*) as count 
            FROM user_echoarea_subscriptions 
            WHERE user_id = ? AND echoarea_id = ? AND is_active = TRUE
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $echoareaId]);
        return $stmt->fetch()['count'] > 0;
    }

    /**
     * Admin: Get all echoareas with subscription statistics
     */
    public function getEchoareasWithStats()
    {
        $sql = "
            SELECT e.*,
                   COUNT(s.id) as subscriber_count,
                   COUNT(CASE WHEN s.subscription_type = 'user' THEN 1 END) as user_subscribers,
                   COUNT(CASE WHEN s.subscription_type = 'auto' THEN 1 END) as auto_subscribers
            FROM echoareas e
            LEFT JOIN user_echoarea_subscriptions s ON (e.id = s.echoarea_id AND s.is_active = TRUE)
            WHERE e.is_active = TRUE
            GROUP BY e.id, e.tag, e.description, e.is_default_subscription, e.created_at
            ORDER BY e.is_default_subscription DESC, subscriber_count DESC, e.tag
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Admin: Set echoarea as default subscription
     */
    public function setEchoareaAsDefault($echoareaId, $isDefault = true)
    {
        $sql = "UPDATE echoareas SET is_default_subscription = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$isDefault, $echoareaId]);
    }

    /**
     * Admin: Get subscription statistics
     */
    public function getSubscriptionStats()
    {
        $stats = [];
        
        // Total echoareas
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM echoareas WHERE is_active = TRUE");
        $stmt->execute();
        $stats['total_echoareas'] = $stmt->fetch()['count'];
        
        // Default echoareas
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM echoareas WHERE is_active = TRUE AND is_default_subscription = TRUE");
        $stmt->execute();
        $stats['default_echoareas'] = $stmt->fetch()['count'];
        
        // Total active subscriptions
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM user_echoarea_subscriptions WHERE is_active = TRUE");
        $stmt->execute();
        $stats['total_subscriptions'] = $stmt->fetch()['count'];
        
        // Users with at least one subscription
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT user_id) as count 
            FROM user_echoarea_subscriptions 
            WHERE is_active = TRUE
        ");
        $stmt->execute();
        $stats['subscribed_users'] = $stmt->fetch()['count'];
        
        return $stats;
    }

    /**
     * Get echoarea subscription count
     */
    public function getEchoareaSubscriberCount($echoareaId)
    {
        $sql = "
            SELECT COUNT(*) as count 
            FROM user_echoarea_subscriptions 
            WHERE echoarea_id = ? AND is_active = TRUE
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$echoareaId]);
        return $stmt->fetch()['count'];
    }
}