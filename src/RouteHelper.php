<?php

namespace BinktermPHP;

/**
 * RouteHelper - Consolidates common route patterns
 *
 * This class provides static helper methods to reduce code duplication
 * across route handlers, particularly for authentication workflows.
 */
class RouteHelper
{
    /**
     * Require authentication and return the authenticated user
     *
     * This method consolidates the common pattern:
     *   $auth = new Auth();
     *   $user = $auth->requireAuth();
     *
     * @return array The authenticated user array
     * @throws \Exception If authentication fails (redirects to login)
     */
    public static function requireAuth(): array
    {
        $auth = new Auth();
        return $auth->requireAuth();
    }

    /**
     * Require admin authentication and return the authenticated admin user
     *
     * This method consolidates the common pattern:
     *   $auth = new Auth();
     *   $user = $auth->requireAuth();
     *   $adminController = new AdminController();
     *   $adminController->requireAdmin($user);
     *
     * @return array The authenticated admin user array
     * @throws \Exception If authentication or admin check fails
     */
    public static function requireAdmin(): array
    {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $adminController = new AdminController();
        $adminController->requireAdmin($user);

        return $user;
    }

    /**
     * Get the current authenticated user without requiring authentication
     *
     * Returns null if user is not authenticated.
     *
     * @return array|null The authenticated user array or null
     */
    public static function getUser(): ?array
    {
        $auth = new Auth();
        return $auth->getCurrentUser();
    }
}
