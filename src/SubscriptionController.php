<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */


namespace BinktermPHP;

class SubscriptionController
{
    private $db;
    private $subscriptionManager;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->subscriptionManager = new EchoareaSubscriptionManager();
    }

    /**
     * Handle user subscription management requests
     */
    public function handleUserSubscriptions()
    {
        $user = RouteHelper::requireAuth();
        
        if (!$user) {
            return; // requireAuth() already sent 401 response
        }
        
        $userId = $user['user_id'] ?? $user['id'];

        $method = $_SERVER['REQUEST_METHOD'];
        header('Content-Type: application/json');

        if ($method === 'GET') {
            // Get user's subscription status for all echoareas
            $echoareas = $this->subscriptionManager->getAllEchoareasWithSubscriptionStatus($userId);
            echo json_encode(['echoareas' => $echoareas]);
            
        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';
            $echoareaId = $input['echoarea_id'] ?? null;
            
            if (!$echoareaId) {
                $this->respondApiError('errors.subscriptions.echoarea_id_required', 'Echoarea ID required', 400, $user);
                return;
            }
            
            if ($action === 'subscribe') {
                $success = $this->subscriptionManager->subscribeUser($userId, $echoareaId);
                $response = ['success' => $success];
                if ($success) {
                    $response['message_code'] = 'ui.user_subscriptions.subscribed_success';
                }
                echo json_encode($response);
                
            } elseif ($action === 'unsubscribe') {
                $success = $this->subscriptionManager->unsubscribeUser($userId, $echoareaId);
                $response = ['success' => $success];
                if ($success) {
                    $response['message_code'] = 'ui.user_subscriptions.unsubscribed_success';
                }
                echo json_encode($response);
                
            } else {
                $this->respondApiError('errors.subscriptions.invalid_action', 'Invalid action', 400, $user);
            }
        }
    }

    /**
     * Handle admin subscription management requests
     */
    public function handleAdminSubscriptions()
    {
        $user = RouteHelper::requireAuth();
        
        if (!$user) {
            return; // requireAuth() already sent 401 response
        }
        
        $userId = $user['user_id'] ?? $user['id'];
        
        if (!$this->isUserAdmin($userId)) {
            $this->respondApiError('errors.subscriptions.admin_required', 'Admin access required', 403, $user);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];
        
        if ($method === 'GET') {
            // Get echoareas with subscription statistics
            $echoareas = $this->subscriptionManager->getEchoareasWithStats();
            $stats = $this->subscriptionManager->getSubscriptionStats();
            
            echo json_encode([
                'echoareas' => $echoareas,
                'stats' => $stats
            ]);
            
        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';
            $echoareaId = $input['echoarea_id'] ?? null;
            
            if (!$echoareaId) {
                $this->respondApiError('errors.subscriptions.echoarea_id_required', 'Echoarea ID required', 400, $user);
                return;
            }
            
            if ($action === 'set_default') {
                // Debug: log what we're receiving
                error_log("DEBUG: is_default raw value: " . var_export($input['is_default'] ?? 'NOT SET', true));
                error_log("DEBUG: is_default type: " . gettype($input['is_default'] ?? null));

                // Convert to integer (0 or 1) for PostgreSQL boolean compatibility
                $isDefault = !empty($input['is_default']) ? 1 : 0;
                error_log("DEBUG: isDefault after conversion: " . var_export($isDefault, true));

                $success = $this->subscriptionManager->setEchoareaAsDefault($echoareaId, $isDefault);
                $response = ['success' => $success];
                if ($success) {
                    $response['message_code'] = $isDefault
                        ? 'ui.admin_subscriptions.default_enabled_success'
                        : 'ui.admin_subscriptions.default_disabled_success';
                }
                echo json_encode($response);
                
            } else {
                $this->respondApiError('errors.subscriptions.invalid_action', 'Invalid action', 400, $user);
            }
        }
    }

    /**
     * Render user subscription management page
     */
    public function renderUserSubscriptionPage()
    {
        $user = RouteHelper::getUser();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        
        $echoareas = $this->subscriptionManager->getAllEchoareasWithSubscriptionStatus($userId);

        $interests = [];
        $echoareaInterestMap = [];
        if (Config::env('ENABLE_INTERESTS', 'true') === 'true') {
            $im = new InterestManager();
            $interests = $im->getInterests(true);
            $echoareaInterestMap = $im->getEchoareaInterestMap();
        }

        return [
            'echoareas' => $echoareas,
            'interests' => $interests,
            'echoarea_interest_map' => $echoareaInterestMap,
            'page_title_code' => 'ui.user_subscriptions.page_title'
        ];
    }

    /**
     * Render admin subscription management page
     */
    public function renderAdminSubscriptionPage()
    {
        $echoareas = $this->subscriptionManager->getEchoareasWithStats();
        $stats = $this->subscriptionManager->getSubscriptionStats();
        
        return [
            'echoareas' => $echoareas,
            'stats' => $stats,
            'page_title_code' => 'ui.admin_subscriptions.page_title'
        ];
    }

    private function isUserAdmin($userId)
    {
        $stmt = $this->db->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user && $user['is_admin'];
    }

    private function renderTemplate($template, $data = [])
    {
        // This integrates with the existing Template system
        // The calling code will handle the actual rendering
        return $data;
    }

    private function respondApiError(string $errorCode, string $fallbackMessage, int $statusCode = 400, ?array $user = null): void
    {
        http_response_code($statusCode);
        $localized = $this->localizedErrorText($errorCode, $fallbackMessage, $user);
        echo json_encode([
            'success' => false,
            'error_code' => $errorCode,
            'error' => $localized
        ]);
    }

    private function localizedErrorText(string $errorCode, string $fallbackMessage, ?array $user = null): string
    {
        static $translator = null;
        static $localeResolver = null;

        if ($translator === null) {
            $translator = new \BinktermPHP\I18n\Translator();
            $localeResolver = new \BinktermPHP\I18n\LocaleResolver($translator);
        }

        $preferredLocale = is_array($user) ? (string)($user['locale'] ?? '') : '';
        $resolvedLocale = $localeResolver->resolveLocale($preferredLocale !== '' ? $preferredLocale : null, $user);
        $translated = $translator->translate($errorCode, [], $resolvedLocale, ['errors']);

        return $translated === $errorCode ? $fallbackMessage : $translated;
    }
}
