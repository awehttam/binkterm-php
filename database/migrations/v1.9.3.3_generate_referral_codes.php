<?php
/**
 * Migration: 1.9.3.3 - Generate referral codes for existing users
 *
 * This migration sets referral codes based on usernames.
 * Since usernames are unique, they make perfect referral codes.
 */

// Main migration function
return function($db) {
    // Get all users without referral codes
    $stmt = $db->query("SELECT id, username FROM users WHERE referral_code IS NULL");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updateStmt = $db->prepare("UPDATE users SET referral_code = ? WHERE id = ?");

    $updated = 0;
    foreach ($users as $user) {
        // Use username as referral code (usernames are already unique and URL-safe)
        $updateStmt->execute([$user['username'], $user['id']]);
        $updated++;
    }

    echo "Generated referral codes for {$updated} existing users\n";
    return true;
};
