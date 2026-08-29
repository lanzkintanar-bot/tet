<?php
/**
 * app/controllers/PosController.php
 * -----------------------------------------------------------------------
 * AJAX endpoint for the POS Screen. Unlike the CRUD controllers, this
 * isn't a single-resource ?action=list|get|create|update|delete pattern -
 * a checkout touches Products (read), Sales, SaleDetails, and Inventory
 * all at once - so the actions here map to POS workflows instead:
 * product/customer lookup while building a cart, checkout, hold/resume,
 * and receipt retrieval. All money math happens in Sale.php, never here
 * and never on the client.
 */

if (!defined('POS_APP') && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    require_once dirname(__DIR__, 2) . '/config/config.php';
}

if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}

class PosController
{
    private Sale $saleModel;
    private Category $categoryModel;
    private User $userModel;
    private Role $roleModel;

    public function __construct()
    {
        $this->saleModel     = new Sale();
        $this->categoryModel = new Category();
        $this->userModel     = new User();
        $this->roleModel     = new Role();
    }

    public function dispatch(): void
    {
        SessionManager::requirePermission('pos.access');

        switch ($_REQUEST['action'] ?? '') {
            case 'form_data':    $this->formData(); break;
            case 'products':     $this->products(); break;
            case 'barcode':      $this->barcode(); break;
            case 'customers':    $this->customers(); break;
            case 'checkout':     $this->checkout(); break;
            case 'hold':         $this->hold(); break;
            case 'held_list':    $this->heldList(); break;
            case 'held_get':     $this->heldGet(); break;
            case 'held_delete':  $this->heldDelete(); break;
            case 'receipt':      $this->receipt(); break;
            case 'authorize_override': $this->authorizeOverride(); break;
            default: Helper::jsonResponse(false, 'Unknown action.', [], 400);
        }
    }

    /** Categories for the product filter dropdown + accepted payment methods. */
    private function formData(): void
    {
        Helper::jsonResponse(true, '', [
            'categories'      => $this->categoryModel->allActive(),
            'payment_methods' => ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card'],
            'loyalty'         => (new Setting())->getAll(),
            'current_user_id' => (int) SessionManager::get('user_id'),
            'can_override_price' => SessionManager::hasPermission('pos.override_price'),
            'wholesale_pricing_enabled' => (new Setting())->getAll()['wholesale_pricing_enabled'] === '1',
        ]);
    }

    private function products(): void
    {
        $search     = Security::sanitize(trim($_GET['search'] ?? ''));
        $categoryId = !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null;
        $searchBy   = Security::sanitize(trim($_GET['search_by'] ?? ''));

        $result = $this->saleModel->searchProducts($search, $categoryId, (int) ($_GET['page'] ?? 1), 48, $searchBy);
        Helper::jsonResponse(true, '', ['products' => $result['rows'], 'page' => $result['page'], 'total_pages' => $result['total_pages']]);
    }

    /** Exact-match lookup for a barcode-scanner "Enter" keystroke. */
    private function barcode(): void
    {
        $code = Security::sanitize(trim($_GET['code'] ?? ''));
        if ($code === '') {
            Helper::jsonResponse(false, 'No barcode provided.', [], 422);
        }

        $product = $this->saleModel->findByBarcode($code);
        if (!$product) {
            Helper::jsonResponse(false, 'No product matches that barcode.', [], 404);
        }
        if (!$product['is_active']) {
            Helper::jsonResponse(false, 'That product is inactive and cannot be sold.', [], 409);
        }

        Helper::jsonResponse(true, '', ['product' => $product]);
    }

    private function customers(): void
    {
        $search = Security::sanitize(trim($_GET['search'] ?? ''));
        Helper::jsonResponse(true, '', [
            'customers' => $search !== '' ? $this->saleModel->searchCustomers($search) : [],
        ]);
    }

    private function checkout(): void
    {
        Security::requireValidCsrfFromRequest();

        $items = $this->decodeItems();
        $header = $this->buildHeader();

        [$saleId, $error] = $this->saleModel->checkout($items, $header, (int) SessionManager::get('user_id'));
        if ($error) {
            Helper::jsonResponse(false, $error, [], 422);
        }

        $this->userModel->logActivity((int) SessionManager::get('user_id'), 'SALE_CHECKOUT', "Completed sale #{$saleId}");
        Helper::jsonResponse(true, 'Sale completed.', ['sale_id' => $saleId, 'invoice_no' => $this->saleModel->getInvoiceNumber($saleId)]);
    }

    private function hold(): void
    {
        Security::requireValidCsrfFromRequest();

        $items = $this->decodeItems();
        $header = $this->buildHeader();

        [$saleId, $error] = $this->saleModel->hold($items, $header, (int) SessionManager::get('user_id'));
        if ($error) {
            Helper::jsonResponse(false, $error, [], 422);
        }

        $this->userModel->logActivity((int) SessionManager::get('user_id'), 'SALE_HOLD', "Held sale #{$saleId}");
        Helper::jsonResponse(true, 'Sale held.', ['sale_id' => $saleId]);
    }

    private function heldList(): void
    {
        Helper::jsonResponse(true, '', ['held' => $this->saleModel->listHeld()]);
    }

    /** Loads a held sale's items back into cart shape. Does NOT delete it yet. */
    private function heldGet(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $sale = $id > 0 ? $this->saleModel->getHeldWithItems($id) : null;
        if (!$sale) {
            Helper::jsonResponse(false, 'That held sale no longer exists.', [], 404);
        }
        Helper::jsonResponse(true, '', ['sale' => $sale]);
    }

    /** Used both to cancel a held sale outright and to clear it once resumed into the cart. */
    private function heldDelete(): void
    {
        Security::requireValidCsrfFromRequest();

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0 || !$this->saleModel->delete($id)) {
            Helper::jsonResponse(false, 'That held sale no longer exists.', [], 404);
        }

        $this->userModel->logActivity((int) SessionManager::get('user_id'), 'SALE_HELD_REMOVED', "Removed held sale #{$id}");
        Helper::jsonResponse(true, 'Held sale removed.');
    }

    private function receipt(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $sale = $id > 0 ? $this->saleModel->getReceipt($id) : null;
        if (!$sale) {
            Helper::jsonResponse(false, 'Receipt not found.', [], 404);
        }
        $settings = (new Setting())->getAll();
        Helper::jsonResponse(true, '', ['sale' => $sale, 'settings' => $settings]);
    }

    /**
     * Clears the "manager approval required" popup for editing an item's
     * price or applying a discount. Does NOT touch the current session -
     * the cashier stays logged in as themselves; this only checks that
     * SOME account holding 'pos.override_price' is vouching for the
     * action, right now, either by password or by scanning their
     * personal QR badge (see database/migration_pos_override.sql).
     * Every attempt (approved or denied) is written to ActivityLogs.
     */
    private function authorizeOverride(): void
    {
        Security::requireValidCsrfFromRequest();

        $mode = $_POST['mode'] ?? 'password';
        $cashierId = (int) SessionManager::get('user_id');
        $reason = Security::sanitize(trim($_POST['reason'] ?? '')); // e.g. "Edit price" / "Add discount" - for the audit log only

        $approver = null;
        if ($mode === 'qr') {
            $code = trim((string) ($_POST['qr_code'] ?? ''));
            $approver = $code !== '' ? $this->userModel->findByQrBadge($code) : null;
        } else {
            $username = Security::sanitize(trim($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if ($username !== '' && $password !== '') {
                $candidate = $this->userModel->findByUsername($username);
                if ($candidate && $this->userModel->verifyPassword($password, $candidate['password_hash'])) {
                    $approver = $candidate;
                }
            }
        }

        if (!$approver || !$approver['is_active'] || $this->userModel->isLocked($approver)) {
            $this->userModel->logActivity($cashierId, 'POS_OVERRIDE_DENIED', "Override request denied ({$mode})" . ($reason !== '' ? " for: {$reason}" : ''));
            Helper::jsonResponse(false, $mode === 'qr' ? 'That QR badge is not recognized or is no longer active.' : 'Invalid username or password.', [], 401);
        }

        if (!$this->roleModel->hasPermission((int) $approver['role_id'], 'pos.override_price')) {
            $this->userModel->logActivity($cashierId, 'POS_OVERRIDE_DENIED', "{$approver['username']} attempted to approve an override but is not authorized.");
            Helper::jsonResponse(false, 'This account is not authorized to approve overrides.', [], 403);
        }

        $this->userModel->logActivity($cashierId, 'POS_OVERRIDE_APPROVED', "Approved by {$approver['full_name']} ({$approver['username']}) via {$mode}" . ($reason !== '' ? " for: {$reason}" : ''));
        Helper::jsonResponse(true, 'Approved.', ['approver_name' => $approver['full_name']]);
    }

    // -------------------------------------------------------------
    // Shared request parsing
    // -------------------------------------------------------------

    /** Cart items arrive as a JSON string (one POST field) - easiest shape for the JS side to build. */
    private function decodeItems(): array
    {
        $raw = $_POST['items'] ?? '[]';
        $items = json_decode($raw, true);
        return is_array($items) ? $items : [];
    }

    private function buildHeader(): array
    {
        $payments = json_decode($_POST['payments'] ?? '[]', true);
        $payments = is_array($payments) ? array_slice($payments, 0, 5) : [];
        $cleanPayments = [];
        foreach ($payments as $payment) {
            if (!is_array($payment)) continue;
            $cleanPayments[] = [
                'method' => Security::sanitize(trim((string) ($payment['method'] ?? ''))),
                'amount' => (float) ($payment['amount'] ?? 0),
                'reference' => Security::sanitize(trim((string) ($payment['reference'] ?? ''))),
            ];
        }
        if (!$cleanPayments) {
            $cleanPayments[] = [
                'method' => Security::sanitize(trim($_POST['payment_method'] ?? 'cash')),
                'amount' => (float) ($_POST['amount_paid'] ?? 0),
                'reference' => Security::sanitize(trim($_POST['payment_reference'] ?? '')),
            ];
        }
        $cashOnly = (new Setting())->getAll()['cash_payment_only'] === '1';
        if ($cashOnly && array_filter($cleanPayments, static fn($payment) => $payment['method'] !== 'cash')) {
            Helper::jsonResponse(false, 'This register is configured for cash payments only.', [], 422);
        }
        return [
            'customer_id'       => (int) ($_POST['customer_id'] ?? 0) ?: null,
            'payment_method'    => Security::sanitize(trim($_POST['payment_method'] ?? 'cash')),
            'payment_reference' => Security::sanitize(trim($_POST['payment_reference'] ?? '')),
            'amount_paid'       => (float) ($_POST['amount_paid'] ?? 0),
            'manual_discount'   => (float) ($_POST['manual_discount'] ?? 0),
            'loyalty_points_redeemed' => max(0, (int) ($_POST['loyalty_points_redeemed'] ?? 0)),
            'senior_pwd_type'       => Security::sanitize(trim($_POST['senior_pwd_type'] ?? '')),
            'senior_pwd_id_number'  => Security::sanitize(trim($_POST['senior_pwd_id_number'] ?? '')),
            'payments' => $cleanPayments,
        ];
    }

}

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    SessionManager::start();
    (new PosController())->dispatch();
}
