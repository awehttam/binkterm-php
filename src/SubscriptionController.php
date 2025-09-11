<?php

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
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        if (!$user) {
            return; // requireAuth() already sent 401 response
        }
        
        $userId = $user['user_id'] ?? $user['id'];

        $method = $_SERVER['REQUEST_METHOD'];
        
        if ($method === 'GET') {
            // Get user's subscription status for all echoareas
            $echoareas = $this->subscriptionManager->getAllEchoareasWithSubscriptionStatus($userId);
            echo json_encode(['echoareas' => $echoareas]);
            
        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';
            $echoareaId = $input['echoarea_id'] ?? null;
            
            if (!$echoareaId) {
                http_response_code(400);
                echo json_encode(['error' => 'Echoarea ID required']);
                return;
            }
            
            if ($action === 'subscribe') {
                $success = $this->subscriptionManager->subscribeUser($userId, $echoareaId);
                echo json_encode(['success' => $success]);
                
            } elseif ($action === 'unsubscribe') {
                $success = $this->subscriptionManager->unsubscribeUser($userId, $echoareaId);
                echo json_encode(['success' => $success]);
                
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
        }
    }

    /**
     * Handle admin subscription management requests
     */
    public function handleAdminSubscriptions()
    {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        if (!$user) {
            return; // requireAuth() already sent 401 response
        }
        
        $userId = $user['user_id'] ?? $user['id'];
        
        if (!$this->isUserAdmin($userId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
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
                http_response_code(400);
                echo json_encode(['error' => 'Echoarea ID required']);
                return;
            }
            
            if ($action === 'set_default') {
                $isDefault = $input['is_default'] ?? false;
                $success = $this->subscriptionManager->setEchoareaAsDefault($echoareaId, $isDefault);
                echo json_encode(['success' => $success]);
                
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
        }
    }

    /**
     * Render user subscription management page
     */
    public function renderUserSubscriptionPage()
    {
        $auth = new Auth();
        $user = $auth->getCurrentUser();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        
        $echoareas = $this->subscriptionManager->getAllEchoareasWithSubscriptionStatus($userId);
        
        return [
            'echoareas' => $echoareas,
            'page_title' => 'Manage Subscriptions'
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
            'page_title' => 'Admin: Manage Subscriptions'
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
}