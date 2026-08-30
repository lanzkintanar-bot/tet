<?php
/**
 * app/models/Report.php
 * -----------------------------------------------------------------------
 * Read-only aggregate queries for the Reports page. Everything here is
 * scoped to completed sales within a date range - held/voided sales
 * never count toward revenue, and a voided sale's stock has already
 * been restored so excluding it keeps inventory and revenue numbers
 * consistent with each other.
 */

if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}

class Report
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /** Headline numbers for the date range: revenue, tax, discount, txn count, avg sale, units sold, gross profit. */
    public function summary(string $dateFrom, string $dateTo): array
    {
        // Deliberately two independent aggregation scopes, not one query
        // joining Sales -> SaleDetails -> Products: summing Sales-level
        // columns (grand_total/tax_total/discount_total) across that join
        // multiplies each sale's totals once per line item it contains -
        // a 3-item ₱100 sale would count as ₱300 of revenue. units_sold
        // and cost_total are the only figures that actually belong at the
        // line-item grain, so only they use that join.
        $saleStmt = $this->db->prepare(
            "SELECT
                ISNULL(COUNT(*), 0)              AS transaction_count,
                ISNULL(SUM(s.grand_total), 0)    AS revenue,
                ISNULL(SUM(s.tax_total), 0)      AS tax_total,
                ISNULL(SUM(s.discount_total), 0) AS discount_total
             FROM Sales s
             WHERE s.status = 'completed' AND s.created_at BETWEEN :date_from AND :date_to"
        );
        $saleStmt->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
        $saleStmt->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
        $saleStmt->execute();
        $saleRow = $saleStmt->fetch();

        $lineStmt = $this->db->prepare(
            "SELECT
                ISNULL(SUM(sd.quantity), 0)                AS units_sold,
                ISNULL(SUM(sd.quantity * p.cost_price), 0) AS cost_total
             FROM SaleDetails sd
             INNER JOIN Sales s ON s.sale_id = sd.sale_id
             INNER JOIN Products p ON p.product_id = sd.product_id
             WHERE s.status = 'completed' AND s.created_at BETWEEN :date_from AND :date_to"
        );
        $lineStmt->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
        $lineStmt->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
        $lineStmt->execute();
        $lineRow = $lineStmt->fetch();

        $revenue = (float) $saleRow['revenue'];
        $cost    = (float) $lineRow['cost_total'];
        $profit  = $revenue - $cost;

        return [
            'transaction_count' => (int) $saleRow['transaction_count'],
            'revenue'           => round($revenue, 2),
            'tax_total'         => round((float) $saleRow['tax_total'], 2),
            'discount_total'    => round((float) $saleRow['discount_total'], 2),
            'units_sold'        => (int) $lineRow['units_sold'],
            'average_sale'      => $saleRow['transaction_count'] > 0 ? round($revenue / $saleRow['transaction_count'], 2) : 0.0,
            'gross_profit'      => round($profit, 2),
            'gross_margin_pct'  => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0,
        ];
    }

    /** Revenue per calendar day in range - feeds the trend chart. */
    /** Revenue per day across the range, including days with zero sales - a chart/trend consumer needs a continuous timeline, not one that silently compresses or skips empty days. */
    public function salesByDay(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare(
            "SELECT CAST(s.created_at AS DATE) AS sale_date, SUM(s.grand_total) AS revenue, COUNT(*) AS transaction_count
             FROM Sales s
             WHERE s.status = 'completed' AND s.created_at BETWEEN :date_from AND :date_to
             GROUP BY CAST(s.created_at AS DATE)
             ORDER BY sale_date ASC"
        );
        $stmt->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
        $stmt->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
        $stmt->execute();

        $byDate = [];
        foreach ($stmt->fetchAll() as $row) {
            $date = substr((string) $row['sale_date'], 0, 10);
            $byDate[$date] = [
                'date'              => $date,
                'revenue'           => round((float) $row['revenue'], 2),
                'transaction_count' => (int) $row['transaction_count'],
            ];
        }

        $result = [];
        $cursor = new \DateTime($dateFrom);
        $end = new \DateTime($dateTo);
        // Cap the walk so a mistyped/huge range can't hang the request building millions of rows.
        $safety = 0;
        while ($cursor <= $end && $safety < 3660) {
            $date = $cursor->format('Y-m-d');
            $result[] = $byDate[$date] ?? ['date' => $date, 'revenue' => 0.0, 'transaction_count' => 0];
            $cursor->modify('+1 day');
            $safety++;
        }
        return $result;
    }

    /** Best sellers by revenue and by quantity, each independently ranked and limited. */
    public function topProducts(string $dateFrom, string $dateTo, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $sql = "SELECT TOP {$limit} p.product_id, p.product_name,
                       SUM(sd.quantity) AS quantity_sold, SUM(sd.line_total) AS revenue
                FROM SaleDetails sd
                INNER JOIN Sales s ON s.sale_id = sd.sale_id
                INNER JOIN Products p ON p.product_id = sd.product_id
                WHERE s.status = 'completed' AND s.created_at BETWEEN :date_from AND :date_to
                GROUP BY p.product_id, p.product_name
                ORDER BY %s DESC";

        $byRevenue = $this->db->prepare(sprintf($sql, 'revenue'));
        $byRevenue->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
        $byRevenue->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
        $byRevenue->execute();

        $byQuantity = $this->db->prepare(sprintf($sql, 'quantity_sold'));
        $byQuantity->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
        $byQuantity->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
        $byQuantity->execute();

        return [
            'by_revenue'  => $byRevenue->fetchAll(),
            'by_quantity' => $byQuantity->fetchAll(),
        ];
    }

    /** How revenue splits across Cash/GCash/Maya/Card. */
    /**
     * Revenue by payment method. Reads actual per-method amounts from
     * SalePayments rather than grouping by Sales.payment_method - a split
     * payment sale stores payment_method='multiple' on the Sales row
     * itself, which would otherwise dump every split sale into a single
     * unhelpful "MULTIPLE" bucket instead of attributing it correctly
     * across the methods actually used (matches the same fix already
     * applied to Reconciliation's cash breakdown).
     */
    public function paymentBreakdown(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare(
            "SELECT sp.payment_method, COUNT(*) AS transaction_count, SUM(sp.amount) AS revenue
             FROM SalePayments sp
             INNER JOIN Sales s ON s.sale_id = sp.sale_id
             WHERE s.status = 'completed' AND s.created_at BETWEEN :date_from AND :date_to
             GROUP BY sp.payment_method
             ORDER BY revenue DESC"
        );
        $stmt->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
        $stmt->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
        $stmt->execute();

        return array_map(function ($row) {
            return [
                'payment_method'    => $row['payment_method'],
                'transaction_count' => (int) $row['transaction_count'],
                'revenue'           => round((float) $row['revenue'], 2),
            ];
        }, $stmt->fetchAll());
    }

    /** Stock received and spend per supplier in range - the purchasing-side counterpart to sales totals. */
    public function purchaseSummary(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare(
            "SELECT ISNULL(COUNT(*), 0) AS purchase_count, ISNULL(SUM(p.total_amount), 0) AS total_spend
             FROM Purchases p
             WHERE p.status = 'received' AND p.purchased_at BETWEEN :date_from AND :date_to"
        );
        $stmt->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
        $stmt->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        return [
            'purchase_count' => (int) $row['purchase_count'],
            'total_spend'    => round((float) $row['total_spend'], 2),
        ];
    }

    /** Expense totals grouped by category, for the Reports page's expense breakdown. */
    public function expensesByCategory(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare(
            "SELECT category, SUM(amount) AS total, COUNT(*) AS expense_count
             FROM Expenses
             WHERE expense_date BETWEEN :date_from AND :date_to
             GROUP BY category
             ORDER BY total DESC"
        );
        $stmt->bindValue(':date_from', $dateFrom, PDO::PARAM_STR);
        $stmt->bindValue(':date_to', $dateTo, PDO::PARAM_STR);
        $stmt->execute();

        return array_map(function ($row) {
            return [
                'category'       => $row['category'],
                'total'          => round((float) $row['total'], 2),
                'expense_count'  => (int) $row['expense_count'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Voided sales + item-level refunds in range, for the Returns/Refunds
     * KPI - a voided sale is a whole transaction reversed outright, while
     * a refund (database/migration_refunds.sql) is specific returned
     * items on an otherwise-completed sale; both represent money handed
     * back to a customer, so the KPI combines them.
     */
    public function returnsSummary(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare(
            "SELECT ISNULL(COUNT(*), 0) AS voided_count, ISNULL(SUM(s.grand_total), 0) AS voided_amount
             FROM Sales s
             WHERE s.status = 'voided' AND s.created_at BETWEEN :date_from AND :date_to"
        );
        $stmt->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
        $stmt->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        $refundCount = 0;
        $refundAmount = 0.0;
        try {
            $refundStmt = $this->db->prepare(
                "SELECT ISNULL(COUNT(*), 0) AS refund_count, ISNULL(SUM(r.refund_amount), 0) AS refund_amount
                 FROM Refunds r
                 WHERE r.created_at BETWEEN :date_from AND :date_to"
            );
            $refundStmt->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
            $refundStmt->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
            $refundStmt->execute();
            $refundRow = $refundStmt->fetch();
            $refundCount = (int) $refundRow['refund_count'];
            $refundAmount = (float) $refundRow['refund_amount'];
        } catch (\Throwable $e) {
            // The Refunds/RefundDetails migration hasn't been run on this
            // database yet - fall back to voided-sales-only, same as before.
        }

        return [
            'voided_count'  => (int) $row['voided_count'],
            'voided_amount' => round((float) $row['voided_amount'], 2),
            'refund_count'  => $refundCount,
            'refund_amount' => round($refundAmount, 2),
            'total_count'   => (int) $row['voided_count'] + $refundCount,
            'total_amount'  => round((float) $row['voided_amount'] + $refundAmount, 2),
        ];
    }

    // -------------------------------------------------------------
    // Cashier x Payment Method report (its own filterable report -
    // separate from the main Reports page summary above)
    // -------------------------------------------------------------

    public const CASHIER_PAYMENT_METHODS = ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card', 'check' => 'Check'];

    /** Cashiers who actually rang up a completed sale in the range - populates the report's cashier dropdown with only relevant names instead of every registered user. */
    public function cashiersWithSales(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT u.user_id, u.full_name
             FROM Sales s
             INNER JOIN Users u ON u.user_id = s.user_id
             WHERE s.status = 'completed' AND s.created_at BETWEEN :date_from AND :date_to
             ORDER BY u.full_name ASC"
        );
        $stmt->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
        $stmt->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
        $stmt->execute();

        return array_map(function ($row) {
            return ['user_id' => (int) $row['user_id'], 'full_name' => $row['full_name']];
        }, $stmt->fetchAll());
    }

    /** A cashier's name for the report title/export filename when a specific one is selected. */
    public function cashierName(int $userId): ?string
    {
        $stmt = $this->db->prepare("SELECT full_name FROM Users WHERE user_id = :id");
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? $row['full_name'] : null;
    }

    /**
     * Revenue broken down by cashier AND payment method, optionally
     * narrowed to one cashier and/or one payment method. Reads from
     * SalePayments (not Sales.payment_method) for the same reason as
     * paymentBreakdown() above - a split-payment sale would otherwise
     * dump entirely into an unhelpful "MULTIPLE" bucket instead of
     * being attributed across the methods actually used.
     *
     * The summary total is simply the sum of the breakdown rows, so it
     * always agrees with what's shown below it - including when a
     * payment-method filter is active, where the total intentionally
     * reflects only that method's revenue, not the full sale totals of
     * every sale that happened to include it as one of several payments.
     *
     * @return array{summary: array, breakdown: array}
     */
    public function cashierPaymentBreakdown(string $dateFrom, string $dateTo, ?int $cashierId, ?string $paymentMethod): array
    {
        $conditions = ["s.status = 'completed'", "s.created_at BETWEEN :date_from AND :date_to"];
        if ($cashierId !== null) {
            $conditions[] = "s.user_id = :cashier_id";
        }
        if ($paymentMethod !== null) {
            $conditions[] = "sp.payment_method = :payment_method";
        }

        $stmt = $this->db->prepare(
            "SELECT u.user_id AS cashier_id, u.full_name AS cashier_name, sp.payment_method,
                    COUNT(*) AS transaction_count, SUM(sp.amount) AS revenue
             FROM SalePayments sp
             INNER JOIN Sales s ON s.sale_id = sp.sale_id
             INNER JOIN Users u ON u.user_id = s.user_id
             WHERE " . implode(' AND ', $conditions) . "
             GROUP BY u.user_id, u.full_name, sp.payment_method
             ORDER BY u.full_name ASC, sp.payment_method ASC"
        );
        $stmt->bindValue(':date_from', $dateFrom . ' 00:00:00', PDO::PARAM_STR);
        $stmt->bindValue(':date_to', $dateTo . ' 23:59:59', PDO::PARAM_STR);
        if ($cashierId !== null) {
            $stmt->bindValue(':cashier_id', $cashierId, PDO::PARAM_INT);
        }
        if ($paymentMethod !== null) {
            $stmt->bindValue(':payment_method', $paymentMethod, PDO::PARAM_STR);
        }
        $stmt->execute();

        $breakdown = array_map(function ($row) {
            return [
                'cashier_id'        => (int) $row['cashier_id'],
                'cashier_name'      => $row['cashier_name'],
                'payment_method'    => $row['payment_method'],
                'transaction_count' => (int) $row['transaction_count'],
                'revenue'           => round((float) $row['revenue'], 2),
            ];
        }, $stmt->fetchAll());

        $totalTransactions = array_sum(array_column($breakdown, 'transaction_count'));
        $totalRevenue = round(array_sum(array_column($breakdown, 'revenue')), 2);

        return [
            'summary' => [
                'transaction_count' => $totalTransactions,
                'revenue'           => $totalRevenue,
                'average_sale'      => $totalTransactions > 0 ? round($totalRevenue / $totalTransactions, 2) : 0.0,
            ],
            'breakdown' => $breakdown,
        ];
    }
}
