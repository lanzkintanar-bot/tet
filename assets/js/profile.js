/**
 * assets/js/profile.js
 * -----------------------------------------------------------------------
 * Drives views/profile.php via app/controllers/ProfileController.php.
 */
(function ($) {
    'use strict';

    const ENDPOINT = (window.APP_URL || '') + '/app/controllers/ProfileController.php';

    let currentFullName = '';

    function loadProfile() {
        $.get(ENDPOINT, { action: 'get' }).done(function (res) {
            if (!res.success) return;
            const u = res.user;
            currentFullName = u.full_name || '';
            $('#profileUsername').val(u.username);
            $('#profileRole').val(u.role_name);
            $('#profileFullName').val(u.full_name);
            $('#profileEmail').val(u.email || '');

            $('#qrBadgeCardWrap').toggleClass('d-none', !res.can_approve_overrides);
            if (res.qr_badge_issued_at) {
                $('#qrBadgeStatus').text('Badge issued ' + res.qr_badge_issued_at + '. Generating a new one replaces it.');
                $('#btnRevokeQrBadge').removeClass('d-none');
            } else {
                $('#qrBadgeStatus').text('No badge issued yet.');
                $('#btnRevokeQrBadge').addClass('d-none');
            }
        });
    }

    function saveProfile() {
        const $btn = $('#profileSaveBtn');
        $('#profileSuccessAlert, #profileFormAlert').addClass('d-none');
        $btn.prop('disabled', true).find('.spinner-border').removeClass('d-none');

        $.post(ENDPOINT, {
            action: 'update',
            full_name: $('#profileFullName').val(),
            email: $('#profileEmail').val(),
        })
            .done(function (res) {
                if (res.success) { $('#profileSuccessAlert').removeClass('d-none'); }
                else { $('#profileFormAlert').removeClass('d-none').text(res.message || 'Could not save your profile.'); }
            })
            .fail(function (xhr) {
                $('#profileFormAlert').removeClass('d-none').text((xhr.responseJSON && xhr.responseJSON.message) || 'Could not save your profile.');
            })
            .always(function () {
                $btn.prop('disabled', false).find('.spinner-border').addClass('d-none');
            });
    }

    function changePassword() {
        const newPass = $('#newPassword').val();
        const confirm = $('#confirmPassword').val();
        $('#passwordSuccessAlert, #passwordFormAlert').addClass('d-none');

        if (newPass !== confirm) {
            $('#passwordFormAlert').removeClass('d-none').text('New password and confirmation do not match.');
            return;
        }

        const $btn = $('#passwordSaveBtn');
        $btn.prop('disabled', true).find('.spinner-border').removeClass('d-none');

        $.post(ENDPOINT, {
            action: 'change_password',
            current_password: $('#currentPassword').val(),
            new_password: newPass,
        })
            .done(function (res) {
                if (res.success) {
                    $('#passwordSuccessAlert').removeClass('d-none');
                    $('#passwordForm')[0].reset();
                } else {
                    $('#passwordFormAlert').removeClass('d-none').text(res.message || 'Could not change your password.');
                }
            })
            .fail(function (xhr) {
                $('#passwordFormAlert').removeClass('d-none').text((xhr.responseJSON && xhr.responseJSON.message) || 'Could not change your password.');
            })
            .always(function () {
                $btn.prop('disabled', false).find('.spinner-border').addClass('d-none');
            });
    }

    function renderQrBadge(value) {
        $('#qrBadgePrintLabel').text('POS Approval Badge' + (currentFullName ? ' - ' + currentFullName : ''));
        const $canvas = $('#qrBadgeCanvas').empty();
        if (typeof QRCode !== 'undefined') {
            new QRCode($canvas[0], { text: value, width: 200, height: 200 });
        } else {
            $canvas.text('QR library failed to load - badge value: ' + value);
        }
        $('#qrBadgeDisplay').removeClass('d-none');
    }

    function generateQrBadge(e) {
        e.preventDefault();
        const $btn = $('#qrBadgeGenerateBtn');
        $('#qrBadgeSuccessAlert, #qrBadgeFormAlert').addClass('d-none');
        $btn.prop('disabled', true).find('.spinner-border').removeClass('d-none');

        $.post(ENDPOINT, { action: 'generate_qr_badge', current_password: $('#qrBadgePassword').val() })
            .done(function (res) {
                if (res.success) {
                    $('#qrBadgePassword').val('');
                    $('#qrBadgeSuccessAlert').removeClass('d-none').text('Badge generated - save or print it now.');
                    $('#qrBadgeStatus').text('Badge issued ' + res.issued_at + '. Generating a new one replaces it.');
                    $('#btnRevokeQrBadge').removeClass('d-none');
                    renderQrBadge(res.badge);
                } else {
                    $('#qrBadgeFormAlert').removeClass('d-none').text(res.message || 'Could not generate a badge.');
                }
            })
            .fail(function (xhr) {
                $('#qrBadgeFormAlert').removeClass('d-none').text((xhr.responseJSON && xhr.responseJSON.message) || 'Could not generate a badge.');
            })
            .always(function () {
                $btn.prop('disabled', false).find('.spinner-border').addClass('d-none');
            });
    }

    function revokeQrBadge() {
        if (!confirm('Revoke your current QR badge? It will stop working immediately, and cashiers will need your password instead.')) return;
        $.post(ENDPOINT, { action: 'revoke_qr_badge' }).done(function (res) {
            if (res.success) {
                $('#qrBadgeStatus').text('No badge issued yet.');
                $('#btnRevokeQrBadge').addClass('d-none');
                $('#qrBadgeDisplay').addClass('d-none');
                $('#qrBadgeSuccessAlert, #qrBadgeFormAlert').addClass('d-none');
            }
        });
    }

    $(function () {
        loadProfile();
        $('#profileForm').on('submit', function (e) { e.preventDefault(); saveProfile(); });
        $('#passwordForm').on('submit', function (e) { e.preventDefault(); changePassword(); });
        $('#qrBadgeForm').on('submit', generateQrBadge);
        $('#btnRevokeQrBadge').on('click', revokeQrBadge);
        $('#btnPrintQrBadge').on('click', function () {
            const qrHtml = $('#qrBadgeCanvas').html();
            if (!qrHtml) return;

            // A dedicated popup with only the badge markup, rather than
            // window.print() on the whole Profile page - relying on the
            // page's global print stylesheet to correctly isolate one
            // card among everything else on the page is fragile (easy
            // for an unrelated print rule to leave this blank); a fresh
            // document with nothing else in it can't have that problem.
            const win = window.open('', '_blank', 'width=420,height=560');
            if (!win) {
                alert('Please allow pop-ups for this site to print the badge.');
                return;
            }
            win.document.write(
                '<!DOCTYPE html><html><head><title>POS Approval Badge</title><style>' +
                'body{font-family:sans-serif;text-align:center;padding:32px 24px;}' +
                'img,canvas,table{max-width:260px;}' +
                'p{color:#555;font-size:13px;margin-top:16px;}' +
                '</style></head><body>' + qrHtml +
                '<p>Scan this at the register to approve a price override or discount.</p>' +
                '<script>window.onload = function () { window.print(); };<' + '/script>' +
                '</body></html>'
            );
            win.document.close();
        });
    });
})(jQuery);
