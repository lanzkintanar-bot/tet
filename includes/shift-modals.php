<?php
/**
 * includes/shift-modals.php
 * -----------------------------------------------------------------------
 * Start Shift / End Shift modals. Included once, globally, from
 * includes/footer.php - the navbar shift badge (includes/navbar.php)
 * can open them from any page, not just the POS Screen, since a
 * cashier's shift isn't tied to which page happens to be open.
 * Driven entirely by assets/js/pos-shift.js.
 */
if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}
?>
<div class="modal fade" id="startShiftModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-sunrise me-2"></i>Start Shift <span class="badge pos-badge-soft ms-1">#<span id="startShiftNumberPreview">1</span></span></h5></div><div class="modal-body">
    <label class="pos-additional-discount-label">Opening Cash</label>
    <div class="pos-discount-value-row mb-3"><span class="pos-peso-prefix">₱</span><input type="number" class="form-control" id="startShiftOpeningCash" min="0" step="0.01" value="0.00"></div>

    <div class="pos-shift-quickcount">
        <div class="pos-shift-quickcount-head"><i class="bi bi-calculator me-1"></i>Quick Count</div>
        <div class="pos-shift-quickcount-grid">
            <label>₱1K <input type="number" class="form-control form-control-sm" data-denom="1000" min="0" step="1" value="0"></label>
            <label>₱500 <input type="number" class="form-control form-control-sm" data-denom="500" min="0" step="1" value="0"></label>
            <label>₱200 <input type="number" class="form-control form-control-sm" data-denom="200" min="0" step="1" value="0"></label>
            <label>₱100 <input type="number" class="form-control form-control-sm" data-denom="100" min="0" step="1" value="0"></label>
            <label>₱50 <input type="number" class="form-control form-control-sm" data-denom="50" min="0" step="1" value="0"></label>
            <label>₱20 <input type="number" class="form-control form-control-sm" data-denom="20" min="0" step="1" value="0"></label>
        </div>
        <label class="pos-shift-coins">Coins ₱ <input type="number" class="form-control form-control-sm" id="startShiftCoins" min="0" step="0.01" value="0.00"></label>
        <div class="pos-shift-quickcount-total"><span>Total</span><strong id="startShiftQuickCountTotal">₱0.00</strong></div>
    </div>

    <div class="pos-shift-prev-banner d-none" id="startShiftPrevBanner">
        <i class="bi bi-info-circle"></i>
        <span>Previous shift closed <strong id="startShiftPrevAmount">₱0.00</strong></span>
        <span class="text-muted" id="startShiftPrevCashier"></span>
    </div>

    <label class="form-label small text-muted mt-3">Notes (optional)</label>
    <textarea class="form-control" id="startShiftNotes" rows="2" placeholder="Add any notes..."></textarea>
</div><div class="modal-footer"><span class="small text-muted me-auto">Start your shift to use POS</span><button type="button" class="btn btn-light" id="btnCancelStartShift" data-bs-dismiss="modal">Cancel</button><button type="button" class="pos-shift-start-btn" id="btnStartShift"><i class="bi bi-sunrise me-1"></i>Start Shift</button></div></div></div></div>

<div class="modal fade" id="endShiftModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-moon me-2"></i>End Shift <span class="badge pos-badge-soft ms-1">#<span id="endShiftNumber">-</span></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">
    <div class="small text-muted mb-3"><span id="endShiftCashier"></span> · Started <span id="endShiftStartedAt"></span></div>
    <div class="row g-2 mb-3">
        <div class="col-7"><div class="pos-shift-stat-card pos-shift-stat-success"><div class="small">Net Sales</div><strong id="endShiftNetSales">₱0.00</strong><div class="small text-muted"><span id="endShiftTxnCount">0</span> txn(s)</div></div></div>
        <div class="col-5"><div class="pos-shift-stat-card"><div class="small">Items Sold</div><strong id="endShiftItemsSold">0</strong><div class="small text-muted">products</div></div></div>
    </div>
    <div class="pos-shift-recon-card">
        <div class="pos-shift-recon-head"><i class="bi bi-cash-coin me-1"></i>Cash Reconciliation</div>
        <div class="pos-recon-line"><span>Opening</span><strong id="endShiftOpening">₱0.00</strong></div>
        <div class="pos-recon-line"><span>+ Cash Sales</span><strong id="endShiftCashSales" class="text-success">+₱0.00</strong></div>
        <div class="pos-recon-line pos-recon-expected"><span>Expected</span><strong id="endShiftExpected">₱0.00</strong></div>
    </div>
    <label class="form-label small mt-3">Actual Cash</label>
    <div class="pos-discount-value-row mb-2"><span class="pos-peso-prefix">₱</span><input type="number" class="form-control" id="endShiftActualCash" min="0" step="0.01" value="0.00"></div>
    <label class="form-label small text-muted">Notes</label>
    <textarea class="form-control" id="endShiftNotes" rows="2" placeholder="Explain variance..."></textarea>
</div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="pos-shift-end-confirm-btn" id="btnConfirmEndShift"><i class="bi bi-moon me-1"></i>End Shift</button></div></div></div></div>
