<?php
/**
 * app/controllers/ReportController.php
 * -----------------------------------------------------------------------
 * AJAX endpoint for the Reports page. Single bundled action so switching
 * date ranges is one round trip instead of five. Read-only; gated by the
 * 'reports.view' permission (see Roles & Permissions).
 */

if (!defined('POS_APP') && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    require_once dirname(__DIR__, 2) . '/config/config.php';
}

if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}

class ReportController
{
    private Report $reportModel;
    private Expense $expenseModel;

    public function __construct()
    {
        $this->reportModel  = new Report();
        $this->expenseModel = new Expense();
    }

    public function dispatch(): void
    {
        SessionManager::requirePermission('reports.view');

        switch ($_REQUEST['action'] ?? '') {
            case 'summary':      $this->summary(); break;
            case 'add_expense':  $this->addExpense(); break;
            case 'delete_expense': $this->deleteExpense(); break;
            case 'cashier_payment_breakdown':    $this->cashierPaymentBreakdown(); break;
            case 'export_cashier_payment_excel': $this->exportCashierPaymentExcel(); break;
            case 'export_cashier_payment_pdf':   $this->exportCashierPaymentPdf(); break;
            default: Helper::jsonResponse(false, 'Unknown action.', [], 400);
        }
    }

    private function summary(): void
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange();

        $summary = $this->reportModel->summary($dateFrom, $dateTo);
        $expenseTotal = $this->expenseModel->totalForRange($dateFrom, $dateTo);
        $summary['expense_total'] = $expenseTotal;
        $summary['net_profit'] = round($summary['gross_profit'] - $expenseTotal, 2);

        Helper::jsonResponse(true, '', [
            'date_from'          => $dateFrom,
            'date_to'            => $dateTo,
            'summary'            => $summary,
            'sales_by_day'       => $this->reportModel->salesByDay($dateFrom, $dateTo),
            'top_products'       => $this->reportModel->topProducts($dateFrom, $dateTo, 10),
            'payment_breakdown'  => $this->reportModel->paymentBreakdown($dateFrom, $dateTo),
            'purchase_summary'   => $this->reportModel->purchaseSummary($dateFrom, $dateTo),
            'returns_summary'    => $this->reportModel->returnsSummary($dateFrom, $dateTo),
            'expenses'           => $this->expenseModel->listForRange($dateFrom, $dateTo),
            'expenses_by_category' => $this->reportModel->expensesByCategory($dateFrom, $dateTo),
            'expense_categories' => Expense::CATEGORIES,
        ]);
    }

    private function addExpense(): void
    {
        Security::requireValidCsrfFromRequest();

        $category    = Security::sanitize(trim($_POST['category'] ?? ''));
        $description = Security::sanitize(trim($_POST['description'] ?? ''));
        $amount      = (float) ($_POST['amount'] ?? 0);
        $expenseDate = $_POST['expense_date'] ?? '';

        [$expenseId, $error] = $this->expenseModel->create(
            $category, $description, $amount, $expenseDate, (int) SessionManager::get('user_id')
        );

        if ($error) {
            Helper::jsonResponse(false, $error, [], 422);
        }

        Helper::jsonResponse(true, 'Expense recorded.', ['expense_id' => $expenseId]);
    }

    private function deleteExpense(): void
    {
        Security::requireValidCsrfFromRequest();

        $id = (int) ($_POST['expense_id'] ?? 0);
        if ($id <= 0 || !$this->expenseModel->delete($id)) {
            Helper::jsonResponse(false, 'That expense no longer exists.', [], 404);
        }

        Helper::jsonResponse(true, 'Expense removed.');
    }

    /** Defaults to the current calendar month if no valid range is given. */
    private function resolveDateRange(): array
    {
        $from = $_GET['date_from'] ?? '';
        $to   = $_GET['date_to'] ?? '';

        $validFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-01');
        $validTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : date('Y-m-d');

        if ($validFrom > $validTo) {
            [$validFrom, $validTo] = [$validTo, $validFrom];
        }

        return [$validFrom, $validTo];
    }

    // -------------------------------------------------------------
    // Cashier x Payment Method report (its own filters + Excel/PDF export)
    // -------------------------------------------------------------

    /** @return array [dateFrom, dateTo, cashierId|null, paymentMethod|null] */
    private function resolveCashierPaymentFilters(): array
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange();

        $cashierId = (int) ($_GET['cashier_id'] ?? 0);
        $cashierId = $cashierId > 0 ? $cashierId : null;

        $paymentMethod = strtolower(trim((string) ($_GET['payment_method'] ?? '')));
        $paymentMethod = array_key_exists($paymentMethod, Report::CASHIER_PAYMENT_METHODS) ? $paymentMethod : null;

        return [$dateFrom, $dateTo, $cashierId, $paymentMethod];
    }

    private function cashierPaymentBreakdown(): void
    {
        [$dateFrom, $dateTo, $cashierId, $paymentMethod] = $this->resolveCashierPaymentFilters();
        $result = $this->reportModel->cashierPaymentBreakdown($dateFrom, $dateTo, $cashierId, $paymentMethod);
        $cashierName = $cashierId !== null ? ($this->reportModel->cashierName($cashierId) ?? 'Unknown Cashier') : 'All Cashiers';

        Helper::jsonResponse(true, '', [
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'cashier_id'     => $cashierId,
            'cashier_name'   => $cashierName,
            'payment_method' => $paymentMethod,
            'cashiers'       => $this->reportModel->cashiersWithSales($dateFrom, $dateTo),
            'payment_methods' => Report::CASHIER_PAYMENT_METHODS,
            'summary'        => $result['summary'],
            'breakdown'      => $result['breakdown'],
        ]);
    }

    /** @return array [dateFrom, dateTo, breakdown result, filenameLabel, titleLabel] shared by both export formats */
    private function cashierPaymentExportData(): array
    {
        [$dateFrom, $dateTo, $cashierId, $paymentMethod] = $this->resolveCashierPaymentFilters();
        $result = $this->reportModel->cashierPaymentBreakdown($dateFrom, $dateTo, $cashierId, $paymentMethod);
        $cashierName = $cashierId !== null ? ($this->reportModel->cashierName($cashierId) ?? 'Unknown Cashier') : 'All Cashiers';

        $labelParts = [$cashierName];
        if ($paymentMethod !== null) {
            $labelParts[] = Report::CASHIER_PAYMENT_METHODS[$paymentMethod];
        }

        return [$dateFrom, $dateTo, $result, implode(' - ', $labelParts)];
    }

    private function exportCashierPaymentExcel(): void
    {
        [$dateFrom, $dateTo, $result, $label] = $this->cashierPaymentExportData();

        $headers = ['Cashier', 'Payment Method', 'Transactions', 'Revenue'];
        $rows = [];
        foreach ($result['breakdown'] as $row) {
            $rows[] = [$row['cashier_name'], strtoupper($row['payment_method']), $row['transaction_count'], $row['revenue']];
        }
        $rows[] = ['TOTAL', '', $result['summary']['transaction_count'], $result['summary']['revenue']];

        $sheetName = substr($label . ' ' . $dateFrom . ' to ' . $dateTo, 0, 31);
        $filename = 'cashier_payment_report_' . preg_replace('/[^A-Za-z0-9]+/', '_', $label) . '_' . $dateFrom . '_to_' . $dateTo;

        XlsxWriter::stream($filename, $headers, $rows, $sheetName);
    }

    private function exportCashierPaymentPdf(): void
    {
        [$dateFrom, $dateTo, $result, $label] = $this->cashierPaymentExportData();

        $headers = ['Cashier', 'Payment Method', 'Transactions', 'Revenue'];
        $colWidths = [190, 120, 90, 115];

        $rows = [];
        foreach ($result['breakdown'] as $row) {
            $rows[] = [$row['cashier_name'], strtoupper($row['payment_method']), (string) $row['transaction_count'], number_format($row['revenue'], 2)];
        }
        $rows[] = ['TOTAL', '', (string) $result['summary']['transaction_count'], number_format($result['summary']['revenue'], 2)];

        $title = 'Cashier & Payment Method Report - ' . $label;
        $subtitle = $dateFrom . ' to ' . $dateTo
            . ' | Transactions: ' . $result['summary']['transaction_count']
            . ' | Revenue: ' . number_format($result['summary']['revenue'], 2)
            . ' | Avg. Sale: ' . number_format($result['summary']['average_sale'], 2);
        $filename = 'cashier_payment_report_' . preg_replace('/[^A-Za-z0-9]+/', '_', $label) . '_' . $dateFrom . '_to_' . $dateTo;

        PdfWriter::stream($filename, $title, $subtitle, $headers, $colWidths, $rows);
    }
}

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    SessionManager::start();
    (new ReportController())->dispatch();
}
