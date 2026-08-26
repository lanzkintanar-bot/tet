/**
 * assets/js/pos-shift.js
 * -----------------------------------------------------------------------
 * Start Shift / End Shift on the POS Screen. Talks to
 * app/controllers/ShiftController.php. Exposes window.POSShift.refresh()
 * so pos.js can nudge the live stats right after a sale completes,
 * instead of waiting for the next poll.
 */
(function ($) {
    'use strict';

    const ENDPOINT = (window.APP_URL || '') + '/app/controllers/ShiftController.php';

    let activeShift = null;
    let pollTimer = null;

    function money(n) { return '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function moneyShort(n) { return '₱' + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }); }

    function formatDuration(openedAt) {
        const ms = Date.now() - new Date(openedAt.replace(' ', 'T')).getTime();
        const totalMinutes = Math.max(0, Math.floor(ms / 60000));
        return Math.floor(totalMinutes / 60) + 'h ' + (totalMinutes % 60) + 'm';
    }

    function quickCountTotal() {
        let total = Number($('#startShiftCoins').val()) || 0;
        $('#startShiftModal [data-denom]').each(function () {
            total += (Number($(this).data('denom')) || 0) * (Number($(this).val()) || 0);
        });
        return total;
    }

    function renderActive(shift, summary) {
        activeShift = shift;
        $('#posShiftBadgeWrap').removeClass('d-none');
        $('#shiftBadgeNumber, #shiftPanelNumber').text(shift.shift_number);
        $('#shiftPanelCashier').text(shift.cashier_name);
        $('#shiftPanelStartedAt').text(new Date(shift.opened_at.replace(' ', 'T')).toLocaleTimeString());
        $('#shiftPanelTotalSales').text(money(summary.net_sales));
        $('#shiftPanelCashSales').text(money(summary.cash_sales));
        $('#shiftPanelTxns').text(summary.transactions);
        updateDurationDisplay();
    }

    function updateDurationDisplay() {
        if (!activeShift) return;
        const duration = formatDuration(activeShift.opened_at);
        $('#shiftBadgeDuration, #shiftPanelDuration').text(duration);
    }

    function loadActive() {
        $.get(ENDPOINT, { action: 'active' }).done(function (res) {
            if (!res.success) return;
            if (res.shift) {
                renderActive(res.shift, res.summary);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('startShiftModal')).hide();
            } else {
                activeShift = null;
                $('#posShiftBadgeWrap').addClass('d-none');
                // Only nag to start a shift on the POS Screen itself (per
                // the "click POS in the nav -> Start Shift modal" flow) -
                // someone just checking Reports or Settings with no active
                // shift shouldn't be interrupted; the badge simply stays
                // hidden there until they do start one.
                if (!$('#posV2').length) return;
                $('#startShiftNumberPreview').text(res.next_shift_number || 1);
                if (res.previous_closed_cash) {
                    $('#startShiftPrevBanner').removeClass('d-none');
                    $('#startShiftPrevAmount').text(money(res.previous_closed_cash.actual_cash));
                    $('#startShiftPrevCashier').text(res.previous_closed_cash.cashier_name || '');
                } else {
                    $('#startShiftPrevBanner').addClass('d-none');
                }
                bootstrap.Modal.getOrCreateInstance(document.getElementById('startShiftModal')).show();
            }
        });
    }

    function startShift() {
        const openingCash = Number($('#startShiftOpeningCash').val()) || 0;
        if (openingCash < 0) { alert('Opening cash cannot be negative.'); return; }

        $('#btnStartShift').prop('disabled', true);
        $.post(ENDPOINT, { action: 'start', opening_cash: openingCash, notes: $('#startShiftNotes').val() })
            .done(function (res) {
                if (!res.success) { alert(res.message || 'Could not start the shift.'); return; }
                loadActive();
            })
            .fail(function (xhr) { alert((xhr.responseJSON && xhr.responseJSON.message) || 'Could not start the shift.'); })
            .always(function () { $('#btnStartShift').prop('disabled', false); });
    }

    function openEndShiftModal() {
        if (!activeShift) return;
        $.get(ENDPOINT, { action: 'active' }).done(function (res) {
            if (!res.success || !res.shift) return;
            const shift = res.shift, summary = res.summary;
            const expected = shift.opening_cash + summary.cash_sales - summary.change_given;

            $('#endShiftNumber').text(shift.shift_number);
            $('#endShiftCashier').text(shift.cashier_name);
            $('#endShiftStartedAt').text(new Date(shift.opened_at.replace(' ', 'T')).toLocaleTimeString());
            $('#endShiftNetSales').text(money(summary.net_sales));
            $('#endShiftTxnCount').text(summary.transactions);
            $('#endShiftItemsSold').text(summary.items_sold);
            $('#endShiftOpening').text(money(shift.opening_cash));
            $('#endShiftCashSales').text('+' + money(summary.cash_sales));
            $('#endShiftExpected').text(money(expected));
            $('#endShiftActualCash').val(expected.toFixed(2)).data('expected', expected);
            $('#endShiftNotes').val('');
            updateEndShiftButtonState();

            $('#btnShiftBadge').dropdown('hide');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('endShiftModal')).show();
        });
    }

    /** The cashier must actually count and enter the drawer before ending a shift - a stray 0 (untouched default, or cleared field) should not be submittable. */
    function updateEndShiftButtonState() {
        const actualCash = Number($('#endShiftActualCash').val());
        $('#btnConfirmEndShift').prop('disabled', !actualCash || actualCash <= 0);
    }

    function confirmEndShift() {
        if (!activeShift) return;
        const actualCash = Number($('#endShiftActualCash').val()) || 0;
        if (actualCash <= 0) { alert('Enter the actual counted cash before ending the shift.'); return; }

        $('#btnConfirmEndShift').prop('disabled', true);
        $.post(ENDPOINT, { action: 'end', shift_id: activeShift.shift_id, actual_cash: actualCash, notes: $('#endShiftNotes').val() })
            .done(function (res) {
                if (!res.success) { alert(res.message || 'Could not end the shift.'); return; }
                bootstrap.Modal.getOrCreateInstance(document.getElementById('endShiftModal')).hide();
                loadActive(); // no active shift now - this re-opens Start Shift for the next one
            })
            .fail(function (xhr) { alert((xhr.responseJSON && xhr.responseJSON.message) || 'Could not end the shift.'); })
            .always(function () { $('#btnConfirmEndShift').prop('disabled', false); });
    }

    $(function () {
        if (!$('#posShiftBadgeWrap').length) return; // guards against a page missing the navbar (shouldn't happen - it's global now)

        loadActive();
        clearInterval(pollTimer);
        pollTimer = setInterval(function () {
            updateDurationDisplay();
            if (activeShift) loadActive(); // refreshes Total Sales/Cash Sales/Transactions periodically
        }, 30000);
        setInterval(updateDurationDisplay, 15000); // ticks the duration display between polls

        $('#btnStartShift').on('click', startShift);
        $('#startShiftModal [data-denom], #startShiftCoins').on('input', function () {
            $('#startShiftQuickCountTotal').text(money(quickCountTotal()));
            $('#startShiftOpeningCash').val(quickCountTotal().toFixed(2));
        });
        $('#btnOpenEndShift').on('click', openEndShiftModal);
        $('#btnConfirmEndShift').on('click', confirmEndShift);
        $('#endShiftActualCash').on('input', updateEndShiftButtonState);
    });

    window.POSShift = {
        refresh: function () { if (activeShift) loadActive(); },
        hasActiveShift: function () { return !!activeShift; },
        requireShift: function () {
            // Used by pos.js to gate "Complete sale" - if the cashier
            // cancelled the initial prompt (shifts are optional, not
            // force-blocked from using the POS at all), re-show it the
            // moment they actually try to check out instead of letting
            // every sale silently go untracked.
            if (activeShift) return true;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('startShiftModal')).show();
            return false;
        },
    };
})(jQuery);
