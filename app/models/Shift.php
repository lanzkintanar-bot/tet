<?php
/**
 * app/models/Shift.php
 * -----------------------------------------------------------------------
 * Per-cashier "Start Shift / End Shift" register sessions. Each Sales
 * row is stamped with the active shift's ID at checkout time, so a
 * shift's Total Sales / Cash Sales / Transactions / Items Sold are
 * exact - not a guess based on the cashier's user_id and a time window,
 * which would misattribute sales made during an overlapping shift on
 * a shared register.
 */

if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}

class Shift
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /** The cashier's current open shift, if any - a user can only have one active shift at a time. */
    public function getActive(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, u.full_name AS cashier_name
             FROM Shifts s INNER JOIN Users u ON u.user_id = s.user_id
             WHERE s.user_id = :user_id AND s.status = 'active'"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? $this->normalise($row) : null;
    }

    public function getById(int $shiftId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, u.full_name AS cashier_name
             FROM Shifts s INNER JOIN Users u ON u.user_id = s.user_id
             WHERE s.shift_id = :id"
        );
        $stmt->bindValue(':id', $shiftId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? $this->normalise($row) : null;
    }

    /** Live running totals for an active (or just-closed) shift - Net Sales, Items Sold, Cash Sales, Transactions. */
    public function summary(int $shiftId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ISNULL(COUNT(*), 0) AS txn_count, ISNULL(SUM(s.grand_total), 0) AS net_sales
             FROM Sales s WHERE s.shift_id = :shift_id AND s.status = 'completed'"
        );
        $stmt->bindValue(':shift_id', $shiftId, PDO::PARAM_INT);
        $stmt->execute();
        $salesRow = $stmt->fetch();

        $stmt = $this->db->prepare(
            "SELECT ISNULL(SUM(sd.quantity), 0) AS items_sold
             FROM SaleDetails sd INNER JOIN Sales s ON s.sale_id = sd.sale_id
             WHERE s.shift_id = :shift_id AND s.status = 'completed'"
        );
        $stmt->bindValue(':shift_id', $shiftId, PDO::PARAM_INT);
        $stmt->execute();
        $itemsRow = $stmt->fetch();

        $movement = $this->cashMovement($shiftId);

        return [
            'net_sales'      => round((float) $salesRow['net_sales'], 2),
            'transactions'   => (int) $salesRow['txn_count'],
            'items_sold'     => (int) $itemsRow['items_sold'],
            'cash_sales'     => $movement['cash_collected'],
            'change_given'   => $movement['change_given'],
        ];
    }

    /** Cash actually collected/paid out for this shift, from SalePayments - same approach as Reconciliation::cashMovementForDate(). */
    public function cashMovement(int $shiftId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ISNULL(SUM(sp.amount), 0) AS cash_collected
             FROM SalePayments sp INNER JOIN Sales s ON s.sale_id = sp.sale_id
             WHERE s.shift_id = :shift_id AND s.status = 'completed' AND sp.payment_method = 'cash'"
        );
        $stmt->bindValue(':shift_id', $shiftId, PDO::PARAM_INT);
        $stmt->execute();
        $cashCollected = (float) $stmt->fetch()['cash_collected'];

        $stmt = $this->db->prepare(
            "SELECT ISNULL(SUM(s.change_due), 0) AS change_given
             FROM Sales s WHERE s.shift_id = :shift_id AND s.status = 'completed' AND s.change_due > 0"
        );
        $stmt->bindValue(':shift_id', $shiftId, PDO::PARAM_INT);
        $stmt->execute();
        $changeGiven = (float) $stmt->fetch()['change_given'];

        return ['cash_collected' => round($cashCollected, 2), 'change_given' => round($changeGiven, 2)];
    }

    /** The most recently closed shift's counted cash for this cashier - suggests it as the next shift's opening float. */
    public function lastClosedCash(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT TOP 1 actual_cash, closed_at, user_id, (SELECT full_name FROM Users WHERE user_id = Shifts.user_id) AS cashier_name
             FROM Shifts WHERE status = 'closed'
             ORDER BY closed_at DESC"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? ['actual_cash' => round((float) $row['actual_cash'], 2), 'closed_at' => $row['closed_at'], 'cashier_name' => $row['cashier_name']] : null;
    }

    public function nextShiftNumber(string $businessDate): int
    {
        $stmt = $this->db->prepare("SELECT ISNULL(MAX(shift_number), 0) AS max_number FROM Shifts WHERE business_date = :business_date");
        $stmt->bindValue(':business_date', $businessDate, PDO::PARAM_STR);
        $stmt->execute();
        return ((int) $stmt->fetch()['max_number']) + 1;
    }

    /** @return array{0: array|null, 1: string|null} [shift, error] */
    public function start(int $userId, float $openingCash, ?string $notes): array
    {
        if ($this->getActive($userId)) {
            return [null, 'You already have an active shift. End it before starting a new one.'];
        }
        if ($openingCash < 0) {
            return [null, 'Opening cash cannot be negative.'];
        }

        $businessDate = date('Y-m-d');
        $shiftNumber = $this->nextShiftNumber($businessDate);

        $stmt = $this->db->prepare(
            "INSERT INTO Shifts (shift_number, business_date, user_id, opening_cash, opening_notes, status, opened_at)
             OUTPUT INSERTED.shift_id
             VALUES (:shift_number, :business_date, :user_id, :opening_cash, :opening_notes, 'active', GETDATE())"
        );
        $stmt->bindValue(':shift_number', $shiftNumber, PDO::PARAM_INT);
        $stmt->bindValue(':business_date', $businessDate, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':opening_cash', $openingCash);
        $stmt->bindValue(':opening_notes', $notes !== '' ? $notes : null, $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();
        $shiftId = (int) $stmt->fetchColumn();

        return [$this->getById($shiftId), null];
    }

    /** @return array{0: array|null, 1: string|null} [shift, error] */
    public function end(int $shiftId, int $userId, float $actualCash, ?string $notes): array
    {
        $shift = $this->getById($shiftId);
        if (!$shift || $shift['user_id'] !== $userId || $shift['status'] !== 'active') {
            return [null, 'That shift is not currently active for this user.'];
        }
        if ($actualCash < 0) {
            return [null, 'Actual cash cannot be negative.'];
        }

        $movement = $this->cashMovement($shiftId);
        $expectedCash = round($shift['opening_cash'] + $movement['cash_collected'] - $movement['change_given'], 2);
        $variance = round($actualCash - $expectedCash, 2);

        $stmt = $this->db->prepare(
            "UPDATE Shifts
             SET actual_cash = :actual_cash, expected_cash = :expected_cash, variance = :variance,
                 closing_notes = :closing_notes, status = 'closed', closed_at = GETDATE()
             WHERE shift_id = :shift_id"
        );
        $stmt->bindValue(':actual_cash', $actualCash);
        $stmt->bindValue(':expected_cash', $expectedCash);
        $stmt->bindValue(':variance', $variance);
        $stmt->bindValue(':closing_notes', $notes !== '' ? $notes : null, $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':shift_id', $shiftId, PDO::PARAM_INT);
        $stmt->execute();

        return [$this->getById($shiftId), null];
    }

    private function normalise(array $row): array
    {
        return [
            'shift_id'      => (int) $row['shift_id'],
            'shift_number'  => (int) $row['shift_number'],
            'business_date' => $row['business_date'],
            'user_id'       => (int) $row['user_id'],
            'cashier_name'  => $row['cashier_name'],
            'opening_cash'  => round((float) $row['opening_cash'], 2),
            'actual_cash'   => $row['actual_cash'] !== null ? round((float) $row['actual_cash'], 2) : null,
            'expected_cash' => $row['expected_cash'] !== null ? round((float) $row['expected_cash'], 2) : null,
            'variance'      => $row['variance'] !== null ? round((float) $row['variance'], 2) : null,
            'opening_notes' => $row['opening_notes'],
            'closing_notes' => $row['closing_notes'],
            'status'        => $row['status'],
            'opened_at'     => $row['opened_at'],
            'closed_at'     => $row['closed_at'],
        ];
    }
}
