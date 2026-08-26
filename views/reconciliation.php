<?php
if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0">Transaction Record</h1>
        <p class="text-muted mb-0">Review the day's transactions, then close out the cash drawer.</p>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRecPrevDay" aria-label="Previous day"><i class="bi bi-chevron-left"></i></button>
        <input type="date" class="form-control form-control-sm pos-select" id="recDate" style="max-width: 160px;">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRecNextDay" aria-label="Next day"><i class="bi bi-chevron-right"></i></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRecToday">Today</button>
        <button type="button" class="btn btn-sm pos-btn-eod" id="btnOpenEndOfDay"><i class="bi bi-calculator me-1"></i>End of Day</button>
    </div>
</div>

<!-- Transaction Record -->
<div class="card pos-card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h6 mb-0">Transactions</h2>
            <span class="text-muted small" id="recTxnCount">0 transactions</span>
        </div>
        <div class="table-responsive">
            <table class="table pos-table align-middle">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Cashier</th>
                        <th>Items</th>
                        <th>Payment</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="recTxnBody">
                    <tr><td colspan="8" class="text-center text-muted py-4">Loading...</td></tr>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="6" class="text-end">Total (completed sales)</td>
                        <td class="text-end" id="recTxnTotal">₱0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- End of Day Reconciliation modal -->
<div class="modal fade" id="eodModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-calculator me-1"></i>End of Day Reconciliation</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">

    <div class="small text-muted mb-2" id="eodModalDate"></div>

    <div class="mb-3">
        <div class="small text-muted mb-1">Payment method breakdown</div>
        <div id="recPaymentBreakdown" class="d-flex flex-column gap-1"></div>
    </div>

    <div class="pos-recon-line"><span>Opening float</span><input type="number" class="form-control form-control-sm text-end" id="recOpeningFloat" min="0" step="0.01" style="max-width:130px;"></div>
    <div class="pos-recon-line"><span>Cash collected</span><strong id="recCashCollected">₱0.00</strong></div>
    <div class="pos-recon-line"><span>Change given out</span><strong id="recChangeGiven">-₱0.00</strong></div>
    <div class="pos-recon-line pos-recon-expected"><span>Expected cash in drawer</span><strong id="recExpectedCash">₱0.00</strong></div>

    <div class="pos-recon-line mt-2"><span>Counted cash</span><input type="number" class="form-control form-control-sm text-end" id="recCountedCash" min="0" step="0.01" style="max-width:130px;"></div>
    <div class="pos-recon-line pos-recon-variance"><span>Variance</span><strong id="recVariance">₱0.00</strong></div>

    <textarea class="form-control mt-2" id="recNotes" rows="2" placeholder="Notes (optional) - e.g. reason for a shortage/overage"></textarea>

    <div class="small text-muted mt-2 d-none" id="recSavedInfo"></div>

</div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button><button type="button" class="pos-v2-complete" id="btnSaveReconciliation"><i class="bi bi-check2-circle me-1"></i>Save Reconciliation</button></div></div></div></div>
