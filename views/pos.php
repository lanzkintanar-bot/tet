<?php
if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}
?>
<div class="pos-v2" id="posV2">
    <header class="pos-v2-header"><div><span class="pos-v2-eyebrow">POINT OF SALE</span><h1>New sale</h1></div><button class="pos-v2-held-button" type="button" id="btnHeldSales"><i class="bi bi-clock-history"></i><span>Held</span><span class="pos-v2-held-count" id="heldCountBadge" style="display:none;">0</span></button></header>

    <section class="pos-v2-items"><div class="pos-v2-item-search"><i class="bi bi-upc-scan"></i><input type="text" id="posScanInput" placeholder="Search barcode, product number, or item name" autofocus><button type="button" id="btnOpenScanner" aria-label="Open barcode / QR scanner" title="Scan barcode or QR code"><i class="bi bi-qr-code-scan"></i></button><button type="button" id="btnOpenCatalog" aria-label="Browse items"><i class="bi bi-grid"></i></button><button type="button" id="btnShowShortcuts" aria-label="Keyboard shortcuts" title="Keyboard shortcuts (Ctrl+/)"><i class="bi bi-keyboard"></i></button></div><div class="small text-muted mt-2 d-none" id="posScanStatus"></div><div class="pos-v2-cart-head"><span>Item</span><span>Qty</span><span>Total</span></div><div class="pos-v2-cart" id="posCartScroll"><table class="pos-table pos-v2-cart-table"><tbody id="posCartBody"><tr><td colspan="4" class="pos-v2-empty"><i class="bi bi-bag"></i><span>Your cart is empty</span><small>Search or scan an item to begin</small></td></tr></tbody></table></div></section>
    <section class="pos-v2-summary"><div class="pos-v2-count-row"><span><i class="bi bi-boxes"></i> Items: <strong id="posItemCount">0</strong></span><span><i class="bi bi-stack"></i> Total Qty: <strong id="posTotalQuantity">0</strong></span></div><div class="pos-v2-summary-row"><span>Subtotal</span><strong id="posSubtotal">₱0.00</strong></div><div class="pos-v2-summary-row"><span>Tax</span><strong id="posTax">₱0.00</strong></div><div class="pos-v2-summary-row pos-v2-discount-row"><span>Discount</span><strong id="posDiscount">₱0.00</strong></div><div class="pos-v2-total-row"><span>Total</span><strong id="posGrandTotal">₱0.00</strong></div><div class="small text-success d-none" id="posLoyaltyPreview"></div></section>
    <footer class="pos-v2-actions"><button class="pos-v2-hold" type="button" id="btnHoldSale"><i class="bi bi-pause-circle"></i> Hold</button><button class="pos-v2-complete" type="button" id="btnOpenPayment">Complete sale <i class="bi bi-arrow-right"></i></button><button class="pos-v2-clear" type="button" id="btnClearCart" title="Clear cart" aria-label="Clear cart"><i class="bi bi-trash3"></i></button></footer>
</div>

<div class="modal fade" id="editPriceModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil-fill me-1"></i>Edit Price</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">
    <div class="fw-medium mb-2" id="editPriceItemName">—</div>
    <div class="pos-recon-line"><span>Old price</span><strong id="editPriceOld">₱0.00</strong></div>
    <label class="form-label small text-muted mt-2 mb-1">New price (up to catalog price of <span id="editPriceCeiling">₱0.00</span>)</label>
    <div class="input-group"><span class="input-group-text">₱</span><input type="number" class="form-control" id="editPriceNew" min="0.01" step="0.01" autofocus></div>
    <div class="alert alert-danger py-2 mt-2 mb-0 d-none small" id="editPriceError"></div>
</div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="pos-v2-complete" type="button" id="btnApplyEditPrice">Update Price</button></div></div></div></div>

<div class="modal fade" id="editDiscountModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-tag-fill me-1"></i>Add discount on this item</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">
    <div class="fw-medium mb-2" id="editDiscountItemName">—</div>
    <div class="pos-recon-line"><span>Original price</span><strong id="editDiscountOriginal">₱0.00</strong></div>
    <label class="form-label small text-muted mt-2 mb-1">Discount</label>
    <div class="input-group">
        <input type="number" class="form-control" id="editDiscountValue" min="0" step="0.01" placeholder="0" autofocus>
    </div>
    <div class="btn-group btn-group-sm mt-2" role="group" aria-label="Discount type">
        <input type="radio" class="btn-check" name="editDiscountType" id="editDiscountTypePeso" value="peso" checked>
        <label class="btn btn-outline-secondary" for="editDiscountTypePeso">₱ Peso value</label>
        <input type="radio" class="btn-check" name="editDiscountType" id="editDiscountTypePercent" value="percent">
        <label class="btn btn-outline-secondary" for="editDiscountTypePercent">% Percent</label>
    </div>
    <div class="pos-recon-line mt-2"><span>Discount</span><strong id="editDiscountPreviewAmount">-₱0.00</strong></div>
    <div class="pos-recon-line pos-recon-expected"><span>New price</span><strong id="editDiscountNewPrice">₱0.00</strong></div>
    <input type="hidden" id="editDiscountNew">
    <div class="alert alert-danger py-2 mt-2 mb-0 d-none small" id="editDiscountError"></div>
</div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="pos-v2-complete" type="button" id="btnApplyEditDiscount">Apply discount</button></div></div></div></div>

<!-- Manager approval popup: clears before a Cashier/Staff account can edit an item's price or apply a discount. Two ways to approve: password, or scanning a manager's personal QR badge (see Profile page). Neither touches the cashier's own login session. -->
<div class="modal fade" id="posOverrideModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-lock-fill me-1"></i>Manager Approval Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">Requesting: <strong id="posOverrideReason">—</strong></p>
                <ul class="nav nav-pills nav-fill mb-3" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active" id="posOverridePasswordTabBtn" data-bs-toggle="pill" data-bs-target="#posOverridePasswordTab" type="button" role="tab"><i class="bi bi-key me-1"></i>Password</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="posOverrideQrTabBtn" data-bs-toggle="pill" data-bs-target="#posOverrideQrTab" type="button" role="tab"><i class="bi bi-qr-code-scan me-1"></i>Scan Badge</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="posOverridePasswordTab" role="tabpanel">
                        <label class="form-label small text-muted mb-1">Manager / owner username</label>
                        <input type="text" class="form-control mb-2" id="posOverrideUsername" autocomplete="off" placeholder="Username">
                        <label class="form-label small text-muted mb-1">Password</label>
                        <input type="password" class="form-control" id="posOverridePassword" autocomplete="off" placeholder="Password">
                    </div>
                    <div class="tab-pane fade" id="posOverrideQrTab" role="tabpanel">
                        <div id="posOverrideCameraView" class="pos-scanner-camera"></div>
                        <div class="small text-muted mt-2">Point the manager's QR badge at the camera.</div>
                        <div class="alert alert-warning d-none mt-2 mb-0 small" id="posOverrideQrError"></div>
                    </div>
                </div>
                <div class="alert alert-danger py-2 mt-3 mb-0 d-none small" id="posOverrideError"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="pos-v2-complete" type="button" id="btnPosOverrideApprove">Approve</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="catalogModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Add items</h5><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><select class="form-select pos-select mb-3" id="posCategoryFilter"><option value="">All categories</option></select><div class="row g-2" id="posProductGrid"><div class="col-12 text-center text-muted py-4">Loading items...</div></div><div class="d-flex justify-content-center align-items-center gap-2 pt-3" id="posProductPagination"></div></div></div></div></div>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down"><div class="modal-content pos-v2-payment-modal"><div class="modal-header"><h5 class="modal-title">Complete sale</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="pos-senior-pwd d-none" id="posSeniorPwdSection"><div class="pos-additional-discount-label">Senior Citizen / PWD discount</div><div class="pos-discount-applied-row d-none" id="posSeniorPwdAppliedRow"><span class="pos-discount-applied-value" id="posSeniorPwdAppliedText"></span><button type="button" class="pos-discount-remove-link" id="btnRemoveSeniorPwd">Remove</button></div><div id="posSeniorPwdForm"><div class="form-check"><input class="form-check-input" type="checkbox" id="posApplySeniorPwd"><label class="form-check-label" for="posApplySeniorPwd">Apply Senior Citizen / PWD discount</label></div><div class="d-none mt-2" id="posSeniorPwdFields"><div class="btn-group btn-group-sm mb-2" role="group" aria-label="Discount category"><input type="radio" class="btn-check" name="posSeniorPwdType" id="posSeniorPwdTypeSenior" value="senior" checked><label class="btn btn-outline-secondary" for="posSeniorPwdTypeSenior">Senior Citizen</label><input type="radio" class="btn-check" name="posSeniorPwdType" id="posSeniorPwdTypePwd" value="pwd"><label class="btn btn-outline-secondary" for="posSeniorPwdTypePwd">PWD</label></div><input type="text" class="form-control form-control-sm" id="posSeniorPwdIdNumber" placeholder="ID number (required, printed on receipt)" maxlength="50"><button type="button" class="pos-discount-apply-btn mt-2" id="btnApplySeniorPwd">Apply</button><div class="small text-success mt-1" id="posSeniorPwdPreview"></div></div></div></div><div class="pos-v2-payment-totals"><div><span>Subtotal</span><strong id="posPaymentSubtotal">₱0.00</strong></div><div><span>Tax</span><strong id="posPaymentTax">₱0.00</strong></div><div class="pos-discount-breakdown-row d-none" id="posRowItemDiscount"><span>Discount per item</span><strong id="posItemDiscountAmt">₱0.00</strong></div><div class="pos-discount-breakdown-row d-none" id="posRowSeniorPwdDiscount"><span>Discount per PWD/SC</span><strong id="posSeniorPwdDiscountAmt">₱0.00</strong></div><div class="pos-discount-breakdown-row d-none" id="posRowAdditionalDiscount"><span>Additional discount</span><strong id="posAdditionalDiscountAmt">₱0.00</strong></div><div class="pos-discount-breakdown-row d-none" id="posRowLoyaltyDiscount"><span>Redeem loyalty points</span><strong id="posLoyaltyDiscountAmt">₱0.00</strong></div><div><span>Total discount</span><strong id="posPaymentDiscount">₱0.00</strong></div><div class="pos-v2-payment-due"><span>Total</span><strong id="posPaymentTotal">₱0.00</strong></div></div><div class="pos-additional-discount"><div class="pos-additional-discount-label">Additional discount <i class="bi bi-question-circle" title="Applies to the subtotal after item-level discounts, before tax."></i></div><div class="pos-discount-locked d-none" id="posAdditionalDiscountLocked"><span class="text-muted small"><i class="bi bi-lock-fill me-1"></i>Manager approval required</span><button type="button" class="pos-discount-apply-btn" id="btnUnlockAdditionalDiscount">Approve</button></div><div class="pos-discount-applied-row d-none" id="posAdditionalDiscountApplied"><span class="pos-discount-applied-value" id="posAdditionalDiscountAppliedText"></span><button type="button" class="pos-discount-remove-link d-none" id="btnRemoveAdditionalDiscount">Remove</button></div><div id="posAdditionalDiscountForm"><div class="pos-discount-presets"><button type="button" class="pos-discount-preset" data-value="5">5%</button><button type="button" class="pos-discount-preset" data-value="10">10%</button><button type="button" class="pos-discount-preset" data-value="20">20%</button><button type="button" class="pos-discount-preset" data-value="25">25%</button></div><div class="pos-discount-value-row"><input type="number" class="form-control" id="posAdditionalDiscountValue" placeholder="Value" min="0" step="0.01"><button type="button" class="pos-discount-mode-btn active" id="posDiscountModePercent" data-mode="percent" title="Percent">%</button><button type="button" class="pos-discount-mode-btn" id="posDiscountModePeso" data-mode="peso" title="Peso value">₱</button></div><button type="button" class="pos-discount-apply-btn mt-2" id="btnApplyAdditionalDiscount">Apply</button></div></div><div class="pos-payment-header"><label class="pos-v2-payment-label mb-0">Payment method</label><div class="pos-payment-mode-toggle" id="posPaymentModeToggle"><button type="button" class="pos-payment-mode-btn active" id="btnPaymentModeSingle" data-mode="single">Single payment</button><button type="button" class="pos-payment-mode-btn" id="btnPaymentModeSplit" data-mode="split">Split payment</button></div></div><div class="pos-split-banner d-none" id="posSplitBanner"><span>Split payment</span><span>Total: <strong id="posSplitBannerTotal">₱0.00</strong></span></div><div id="posPaymentRows"></div><button type="button" class="pos-add-tender-btn d-none" id="btnAddPayment"><i class="bi bi-plus-lg me-1"></i>Add another tender</button><div class="pos-payment-status-bar"><span>Paid: <strong id="posPaymentPaidText">₱0.00</strong> / <strong id="posPaymentDueText">₱0.00</strong></span><span id="posPaymentRemainingText">Remaining: ₱0.00</span></div><div class="pos-v2-cash-keypad d-none" id="posCashKeypad" aria-label="Cash keypad"><button type="button" data-key="1">1</button><button type="button" data-key="2">2</button><button type="button" data-key="3">3</button><button type="button" data-key="4">4</button><button type="button" data-key="5">5</button><button type="button" data-key="6">6</button><button type="button" data-key="7">7</button><button type="button" data-key="8">8</button><button type="button" data-key="9">9</button><button type="button" class="pos-v2-keypad-clear" data-key="clear">Clear</button><button type="button" data-key="0">0</button><button type="button" data-key="back" aria-label="Backspace"><i class="bi bi-backspace"></i></button><button type="button" class="pos-v2-keypad-dot" data-key=".">.</button></div><div class="pos-v2-change" id="posChangeRow" style="display:none;"><span>Change</span><strong id="posChangeDue">₱0.00</strong></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Back</button><button class="pos-v2-complete" type="button" id="btnCheckout">Save sale <i class="bi bi-check2"></i></button></div></div></div></div>

<div class="modal fade" id="scannerModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-qr-code-scan me-1"></i>Scan barcode / QR code</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">
    <ul class="nav nav-pills pos-scanner-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" id="scannerCameraTabBtn" data-bs-toggle="pill" data-bs-target="#scannerCameraTab" type="button" role="tab"><i class="bi bi-camera me-1"></i>Camera</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="scannerManualTabBtn" data-bs-toggle="pill" data-bs-target="#scannerManualTab" type="button" role="tab"><i class="bi bi-keyboard me-1"></i>Manual input</button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="scannerCameraTab" role="tabpanel">
            <div id="scannerCameraView" class="pos-scanner-camera"></div>
            <div class="small text-muted mt-2" id="scannerCameraStatus">Point a barcode or QR code at the camera.</div>
            <div class="alert alert-warning d-none mt-2" id="scannerCameraError"></div>
        </div>
        <div class="tab-pane fade" id="scannerManualTab" role="tabpanel">
            <label class="form-label small text-muted">Search by</label>
            <select class="form-select pos-select mb-2" id="scannerSearchBy">
                <option value="">Any (barcode, number, or name)</option>
                <option value="barcode">Barcode</option>
                <option value="code">Product number</option>
                <option value="name">Product name</option>
            </select>
            <div class="input-group mb-3">
                <input type="text" class="form-control" id="scannerManualInput" placeholder="Type or paste a code, number, or name" autocomplete="off">
                <button class="btn pos-btn-primary" type="button" id="btnScannerManualSearch"><i class="bi bi-search"></i></button>
            </div>
            <div id="scannerManualResults" class="pos-scanner-results"></div>
        </div>
    </div>
</div></div></div></div>

<div class="modal fade" id="heldSalesModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Held sales</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="list-group" id="heldSalesList"><div class="text-center text-muted py-4">No held sales.</div></div></div></div></div></div>

<div class="modal fade" id="posShortcutsModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-keyboard me-1"></i>Keyboard shortcuts</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">
    <div class="pos-shortcut-row"><span>Focus the search / barcode box</span><span><kbd>Ctrl</kbd> + <kbd>S</kbd></span></div>
    <div class="pos-shortcut-row"><span>Open barcode / QR scanner</span><span><kbd>Ctrl</kbd> + <kbd>Alt</kbd> + <kbd>B</kbd></span></div>
    <div class="pos-shortcut-row"><span>Browse items (Add items)</span><span><kbd>Ctrl</kbd> + <kbd>Alt</kbd> + <kbd>I</kbd></span></div>
    <div class="pos-shortcut-row"><span>Hold current sale</span><span><kbd>Ctrl</kbd> + <kbd>Alt</kbd> + <kbd>H</kbd></span></div>
    <div class="pos-shortcut-row"><span>Open held sales</span><span><kbd>Ctrl</kbd> + <kbd>Alt</kbd> + <kbd>J</kbd></span></div>
    <div class="pos-shortcut-row"><span>Open payment / complete sale</span><span><kbd>Ctrl</kbd> + <kbd>Alt</kbd> + <kbd>P</kbd></span></div>
    <div class="pos-shortcut-row"><span>Confirm payment (Save sale)</span><span><kbd>Ctrl</kbd> + <kbd>Enter</kbd></span></div>
    <div class="pos-shortcut-row"><span>Focus customer search</span><span><kbd>Ctrl</kbd> + <kbd>Alt</kbd> + <kbd>U</kbd></span></div>
    <div class="pos-shortcut-row"><span>Clear cart</span><span><kbd>Ctrl</kbd> + <kbd>Alt</kbd> + <kbd>C</kbd></span></div>
    <div class="pos-shortcut-row"><span>Close any open window</span><span><kbd>Esc</kbd></span></div>
    <div class="pos-shortcut-row"><span>Show this list</span><span><kbd>Ctrl</kbd> + <kbd>/</kbd></span></div>
    <p class="text-muted small mt-3 mb-0">Shortcuts that open a window only work when nothing else is already open, so they never stack on top of each other.</p>
</div></div></div></div>
<div class="modal fade" id="paymentCompleteModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content pos-payment-complete"><div class="modal-body text-center py-4">
    <div class="pos-payment-complete-check"><i class="bi bi-check-lg"></i></div>
    <h4 class="mt-3 mb-1">Payment Complete</h4>
    <p class="text-muted mb-3">Sale <strong id="paymentCompleteInvoice">—</strong> was saved successfully.</p>
    <div class="pos-payment-complete-figures">
        <div><span>Amount paid</span><strong id="paymentCompleteAmountPaid">₱0.00</strong></div>
        <div class="pos-payment-complete-change"><span>Change</span><strong id="paymentCompleteChange">₱0.00</strong></div>
    </div>
    <div class="pos-payment-complete-progress mt-4"><div class="pos-payment-complete-progress-bar" id="paymentCompleteProgressBar"></div></div>
    <div class="small text-muted mt-2" id="paymentCompleteCountdownText">Continuing in 20s…</div>
    <button type="button" class="pos-v2-complete w-100 mt-4" id="btnPaymentCompleteDone">Done</button>
</div></div></div></div>

<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Sale complete</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="pos-receipt" id="receiptContent"></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" id="btnNewSale" data-bs-dismiss="modal">New sale</button><button class="btn pos-btn-primary" type="button" id="btnPrintReceipt"><i class="bi bi-printer me-1"></i>Print receipt</button></div></div></div></div>
<template id="posProductTileTpl"><div class="col-4 pos-product-col"><div class="pos-product-tile"><button type="button" class="pos-product-tile-main w-100 text-start"><div class="pos-product-image-wrap"><img class="pos-product-image d-none" alt=""><i class="bi bi-box-seam pos-product-image-placeholder"></i></div><div class="pos-product-name"></div><div class="pos-product-price"></div><div class="pos-product-stock"></div></button><div class="pos-product-tier-toggle d-none" role="group" aria-label="Add as"><span class="pos-product-tier-label">Add As</span><button type="button" class="pos-product-tier-btn active" data-tier="retail"><i class="bi bi-shop"></i><span>Retail</span></button><button type="button" class="pos-product-tier-btn" data-tier="wholesale"><i class="bi bi-box2"></i><span>Wholesale</span></button></div></div></div></template>
