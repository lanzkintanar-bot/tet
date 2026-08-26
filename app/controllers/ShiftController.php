<?php
/**
 * app/controllers/ShiftController.php
 * -----------------------------------------------------------------------
 * AJAX endpoint backing the Start Shift / End Shift modals on the POS
 * Screen. Any logged-in POS user can manage their own shift - there's
 * no extra role restriction here (unlike Reconciliation), since every
 * cashier needs to start/end their own shift to use the register.
 */

if (!defined('POS_APP') && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    require_once dirname(__DIR__, 2) . '/config/config.php';
}

if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}

class ShiftController
{
    private Shift $model;

    public function __construct()
    {
        $this->model = new Shift();
    }

    public function dispatch(): void
    {
        SessionManager::requireLogin();

        switch ($_REQUEST['action'] ?? '') {
            case 'active':   $this->active(); break;
            case 'start':    $this->start(); break;
            case 'end':      $this->end(); break;
            default: Helper::jsonResponse(false, 'Unknown action.', [], 400);
        }
    }

    private function active(): void
    {
        $userId = (int) SessionManager::get('user_id');
        $shift = $this->model->getActive($userId);

        if ($shift) {
            Helper::jsonResponse(true, '', ['shift' => $shift, 'summary' => $this->model->summary($shift['shift_id'])]);
            return;
        }

        $previous = $this->model->lastClosedCash($userId);
        Helper::jsonResponse(true, '', [
            'shift' => null,
            'previous_closed_cash' => $previous,
            'next_shift_number' => $this->model->nextShiftNumber(date('Y-m-d')),
        ]);
    }

    private function start(): void
    {
        Security::requireValidCsrfFromRequest();

        $userId = (int) SessionManager::get('user_id');
        $openingCash = (float) ($_POST['opening_cash'] ?? 0);
        $notes = Security::sanitize(trim($_POST['notes'] ?? ''));

        [$shift, $error] = $this->model->start($userId, $openingCash, $notes);
        if ($error) {
            Helper::jsonResponse(false, $error, [], 422);
        }

        Helper::jsonResponse(true, 'Shift started.', ['shift' => $shift]);
    }

    private function end(): void
    {
        Security::requireValidCsrfFromRequest();

        $userId = (int) SessionManager::get('user_id');
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $actualCash = (float) ($_POST['actual_cash'] ?? 0);
        $notes = Security::sanitize(trim($_POST['notes'] ?? ''));

        [$shift, $error] = $this->model->end($shiftId, $userId, $actualCash, $notes);
        if ($error) {
            Helper::jsonResponse(false, $error, [], 422);
        }

        Helper::jsonResponse(true, 'Shift ended.', ['shift' => $shift, 'summary' => $this->model->summary($shiftId)]);
    }
}

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    SessionManager::start();
    (new ShiftController())->dispatch();
}
