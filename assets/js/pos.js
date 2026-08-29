/**
 * assets/js/pos.js
 * -----------------------------------------------------------------------
 * Drives views/pos.php via app/controllers/PosController.php.
 *
 * The cart lives entirely in memory here for display/preview math only.
 * Every checkout/hold POST is re-priced and re-validated server-side
 * (Sale.php) against live Products/Inventory data - this file never
 * sends a total the server is expected to trust.
 */
(function ($) {
    'use strict';

    const ENDPOINT = (window.APP_URL || '') + '/app/controllers/PosController.php';

    let cart = [];               // [{ line_id, product_id, product_name, unit_price, tax_rate, discount_rate, unit, quantity, quantity_on_hand, price_tier, image_url }]
    let cartLineSeq = 0;          // next line_id - product_id alone stopped being a unique cart-row key once the same product can appear twice (Retail + Wholesale)
    let selectedCustomer = null; // { customer_id, full_name, loyalty_points } or null = walk-in
    let lastSaleId = null;
    let searchDebounce = null;
    let customerDebounce = null;
    let productPage = 1;
    let loyaltyRule = { spend: 1000, points: 10, pointValue: 1 };
    let receiptPreferences = { show: true, autoPrint: false };
    let cashPaymentOnly = false;
    let paymentMode = 'single'; // 'single' | 'split' - a per-sale choice, independent of the cashPaymentOnly store setting
    let seniorPwdEnabled = false;
    let seniorPwdDiscountRate = 20;
    let taxInclusive = false;
    let canOverridePrice = false; // whether THIS session can edit price/discount without a manager approval popup
    let wholesalePricingEnabled = false; // store-wide Settings toggle - Retail/Wholesale switch only shows on tiles when this is on
    let additionalDiscountUnlocked = false; // whether the Payment modal's Additional discount widget has been approved for THIS checkout attempt
    let itemToastTimer = null;
    let draftKey = '';
    let draftReady = false;
    const DRAFT_EXPIRY_MS = 3 * 60 * 60 * 1000;

    function escapeHtml(str) { return $('<div>').text(str == null ? '' : str).html(); }
    function money(n) { return '₱' + Number(n || 0).toFixed(2); }
    function toCents(value) { return roundHalfUp((Number(value) || 0) * 100); }
    function fromCents(value) { return value / 100; }
    /**
     * PHP's round() corrects for floating-point representation error near
     * a .5 boundary (e.g. a value that's mathematically exactly x.5 but
     * is stored as x.499999999998 due to IEEE-754 binary imprecision);
     * JavaScript's Math.round() does not; it rounds what's literally
     * there. Both languages compute the identical underlying float for
     * the identical formula, so without this correction the two can
     * round the same number in opposite directions - a real, if rare,
     * source of the client and server disagreeing by exactly one
     * centavo. Nudging by a tiny epsilon (far larger than the ~1e-12
     * float error this corrects for, far smaller than the 0.5 threshold
     * that would change a legitimate rounding decision) matches PHP's
     * behavior for every value that matters here.
     */
    function roundHalfUp(value) {
        return Math.round(value + (value >= 0 ? 1e-9 : -1e-9));
    }

    function showToast(message) {
        const $toast = $('#posItemToast');
        $('#posItemToastText').text(message);
        $toast.addClass('show');
        clearTimeout(itemToastTimer);
        itemToastTimer = setTimeout(function () { $toast.removeClass('show'); }, 2200);
    }

    function notifyItemAdded(product) {
        showToast(product.product_name + ' added to cart');
    }

    function focusScanInput() {
        // Barcode scanners send Enter and immediately start the next code;
        // keep focus in their textbox instead of moving it into the cart.
        setTimeout(function () { $('#posScanInput').trigger('focus'); }, 0);
    }

    /**
     * Looks a code up as an exact barcode match first, then falls back to
     * a name/product-number/brand search. Shared by the keyboard-wedge
     * scanner input, the camera scanner modal, and the scanner's manual
     * input tab, so "search by barcode, product number, or product name"
     * behaves identically everywhere a code can be entered.
     */
    function lookupCode(code, options) {
        options = options || {};
        $('#posScanStatus').addClass('d-none');

        $.get(ENDPOINT, { action: 'barcode', code: code })
            .done(function (res) {
                if (res.success) {
                    addToCart(res.product);
                    focusScanInput();
                    loadProducts();
                    if (options.onDone) options.onDone(res.product);
                } else {
                    handleCodeMiss(code, res.message, options);
                }
            })
            .fail(function (xhr) {
                // jQuery routes ANY non-2xx HTTP status (this endpoint
                // returns 404 for "no product matches that barcode" -
                // a perfectly normal, expected outcome, not a real
                // error) to .fail() instead of .done(), even though the
                // response body is valid JSON. Read the real message
                // from it when present, and only fall back to a scary
                // generic one for an actual network-level failure.
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    handleCodeMiss(code, xhr.responseJSON.message, options);
                } else {
                    $('#posScanStatus').removeClass('d-none').text('Could not reach the server to look up that barcode. Check your connection and try again.');
                    loadProducts();
                    if (options.onMiss) options.onMiss('Could not reach the server.');
                }
            });
    }

    /**
     * No exact barcode match - falls back to a name/code/brand search
     * (this also covers "Product number" and "Product name", since the
     * server-side search already matches product_code and product_name).
     * If that search turns up exactly one product, add it directly:
     * typing a product name/number and hitting Enter is the common flow
     * here, not just literal barcode scanning, so requiring an extra
     * click on an unambiguous single result would be a surprise every time.
     */
    function handleCodeMiss(code, message, options) {
        options = options || {};
        $.get(ENDPOINT, { action: 'products', search: code, category_id: $('#posCategoryFilter').val() })
            .done(function (res) {
                if (res.success) {
                    renderProductGrid(res.products);
                    if (res.products.length === 1) {
                        addToCart(res.products[0]);
                        $('#posScanStatus').addClass('d-none');
                        focusScanInput();
                        if (options.onDone) options.onDone(res.products[0]);
                        return;
                    }
                }
                const text = (message || 'No exact barcode match.') +
                    (res && res.products && res.products.length ? ' Showing ' + res.products.length + ' close matches below.' : ' No close matches found either.');
                $('#posScanStatus').removeClass('d-none').text(text);
                if (options.onMiss) options.onMiss(text, res && res.products);
            });
    }

    // -----------------------------------------------------------------
    // Product grid
    // -----------------------------------------------------------------

    function loadFormData() {
        $.get(ENDPOINT, { action: 'form_data' })
            .done(function (res) {
                if (!res.success) return;
                const $cat = $('#posCategoryFilter');
                res.categories.forEach(function (c) {
                    $cat.append(`<option value="${c.category_id}">${escapeHtml(c.category_name)}</option>`);
                });
                loyaltyRule.spend = Number(res.loyalty && res.loyalty.loyalty_spend_amount) || 0;
                loyaltyRule.points = Number(res.loyalty && res.loyalty.loyalty_points_awarded) || 0;
                const pointValue = res.loyalty && res.loyalty.loyalty_point_value;
                loyaltyRule.pointValue = pointValue === '' || pointValue == null ? 1 : Math.max(0, Number(pointValue) || 0);
                receiptPreferences.show = !res.loyalty || res.loyalty.show_receipt_after_sale !== '0';
                // Auto-print only makes sense as a sub-option of showing the
                // receipt at all - if "Show receipt after sale" is off, the
                // receipt must never appear (or print) regardless of this
                // setting, otherwise unchecking "show" alone doesn't
                // actually suppress the receipt.
                receiptPreferences.autoPrint = receiptPreferences.show && !!(res.loyalty && res.loyalty.auto_print_receipt === '1');
                cashPaymentOnly = !!(res.loyalty && res.loyalty.cash_payment_only === '1');
                seniorPwdEnabled = !res.loyalty || res.loyalty.pwd_senior_discount_enabled !== '0';
                seniorPwdDiscountRate = res.loyalty && res.loyalty.pwd_senior_discount_rate !== '' && res.loyalty.pwd_senior_discount_rate != null
                    ? Number(res.loyalty.pwd_senior_discount_rate) : 20;
                taxInclusive = !!(res.loyalty && res.loyalty.tax_inclusive === '1');
                canOverridePrice = !!res.can_override_price;
                wholesalePricingEnabled = !!res.wholesale_pricing_enabled;
                $('#posSeniorPwdSection').toggleClass('d-none', !seniorPwdEnabled);
                draftKey = 'pos_store_active_draft_user_' + Number(res.current_user_id || 0);
                restoreDraft();
                draftReady = true;
                renderPaymentRows(paymentRows());
                renderCart();
            })
            .fail(function (xhr) {
                // Non-critical - the category filter just stays empty ("All Categories" only).
                console.error('POS: could not load category filter data.', xhr.status, xhr.responseText);
            });
    }

    function loadProducts() {
        const search = $('#posScanInput').val().trim();
        const categoryId = $('#posCategoryFilter').val();

        $.get(ENDPOINT, { action: 'products', search: search, category_id: categoryId, page: productPage })
            .done(function (res) {
                if (res.success) {
                    renderProductGrid(res.products);
                    renderProductPagination(res.page || 1, res.total_pages || 1);
                } else {
                    renderProductGridError(res.message || 'Could not load products.');
                }
            })
            .fail(function (xhr) {
                console.error('POS: product search request failed.', xhr.status, xhr.responseText);
                renderProductGridError(
                    xhr.status === 0
                        ? 'Could not reach the server. Check your connection and try again.'
                        : 'Server error (' + xhr.status + ') while loading products.'
                );
            });
    }

    function renderProductPagination(page, totalPages) {
        const $p = $('#posProductPagination').empty();
        if (totalPages <= 1) return;
        $p.append(`<button class="btn btn-sm btn-outline-secondary" ${page <= 1 ? 'disabled' : ''} data-pos-page="${page - 1}">&lsaquo;</button><span class="small text-muted">${page} / ${totalPages}</span><button class="btn btn-sm btn-outline-secondary" ${page >= totalPages ? 'disabled' : ''} data-pos-page="${page + 1}">&rsaquo;</button>`);
    }

    function renderProductGridError(message) {
        $('#posProductGrid').html(
            '<div class="col-12 text-center text-danger py-4">' +
            escapeHtml(message) +
            ' <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="btnRetryLoadProducts">Retry</button></div>'
        );
    }

    function renderProductGrid(products) {
        const $grid = $('#posProductGrid');
        $grid.empty();

        if (!products.length) {
            $grid.html('<div class="col-12 text-center text-muted py-4">No products found.</div>');
            return;
        }

        const $tpl = $('#posProductTileTpl');

        products.forEach(function (p) {
            const $col = $($tpl.html());
            const outOfStock = Number(p.quantity_on_hand) <= 0;

            $col.find('.pos-product-name').text(p.product_name);
            $col.find('.pos-product-price').text(money(p.selling_price));
            $col.find('.pos-product-stock')
                .text(outOfStock ? 'Out of stock' : p.quantity_on_hand + ' ' + p.unit + ' left')
                .toggleClass('text-danger', outOfStock);

            if (p.image_url) {
                $col.find('.pos-product-image').attr('src', p.image_url).removeClass('d-none');
                $col.find('.pos-product-image-placeholder').addClass('d-none');
            }

            // Retail/Wholesale ("Add As") toggle - only for products that
            // actually have a wholesale price AND the store-wide Settings
            // switch is on. Each tile tracks its own selected tier via a
            // data attribute; tapping the tile itself (not the toggle)
            // adds at whichever tier is currently active on THAT tile.
            const hasWholesale = wholesalePricingEnabled && p.wholesale_price != null && Number(p.wholesale_price) > 0;
            let selectedTier = 'retail';
            if (hasWholesale) {
                const $toggle = $col.find('.pos-product-tier-toggle').removeClass('d-none');
                $toggle.find('.pos-product-tier-btn').on('click', function (e) {
                    e.stopPropagation();
                    selectedTier = $(this).data('tier') === 'wholesale' ? 'wholesale' : 'retail';
                    $toggle.find('.pos-product-tier-btn').removeClass('active');
                    $(this).addClass('active');
                    $col.find('.pos-product-price').text(money(selectedTier === 'wholesale' ? p.wholesale_price : p.selling_price));
                });
            }

            const $btn = $col.find('.pos-product-tile-main');
            $btn.prop('disabled', outOfStock);
            $btn.on('click', function () { addToCart(p, selectedTier); });

            $grid.append($col);
        });
    }

    // -----------------------------------------------------------------
    // Cart
    // -----------------------------------------------------------------

    function addToCart(product, tier) {
        const useWholesale = tier === 'wholesale' && wholesalePricingEnabled
            && product.wholesale_price != null && Number(product.wholesale_price) > 0;
        const priceTier = useWholesale ? 'wholesale' : 'retail';
        const unitPrice = useWholesale ? Number(product.wholesale_price) : Number(product.selling_price);

        // Retail and Wholesale of the same product are kept as separate cart
        // lines (different price, so they can't just be merged quantities)
        // - matched here by product_id AND tier, addressed everywhere else
        // by their own line_id since product_id alone is no longer unique.
        const existing = cart.find(function (i) { return Number(i.product_id) === Number(product.product_id) && i.price_tier === priceTier; });
        const stock = Number(product.quantity_on_hand);

        if (existing) {
            if (existing.quantity >= stock) {
                alert('Only ' + stock + ' ' + product.unit + ' of "' + product.product_name + '" left in stock.');
                return;
            }
            existing.quantity += 1;
        } else {
            cart.push({
                line_id: ++cartLineSeq,
                product_id: product.product_id,
                product_name: product.product_name,
                unit_price: unitPrice,
                catalog_price: unitPrice,
                tax_rate: Number(product.tax_rate),
                discount_rate: Number(product.discount_rate),
                discount: 0,
                unit: product.unit,
                quantity: 1,
                quantity_on_hand: stock,
                price_tier: priceTier,
                image_url: product.image_url || null,
            });
        }
        renderCart();
        notifyItemAdded(product);
    }

    function changeQty(lineId, delta) {
        const item = cart.find(function (i) { return Number(i.line_id) === Number(lineId); });
        if (!item) return;

        const newQty = item.quantity + delta;
        if (newQty <= 0) {
            cart = cart.filter(function (i) { return Number(i.line_id) !== Number(lineId); });
        } else if (newQty > Math.min(item.quantity_on_hand, 10000)) {
            alert('Only ' + item.quantity_on_hand + ' ' + item.unit + ' left in stock.');
            return;
        } else {
            item.quantity = newQty;
        }
        renderCart();
    }

    function removeFromCart(lineId) {
        cart = cart.filter(function (i) { return Number(i.line_id) !== Number(lineId); });
        renderCart();
    }

    /** Sets the Additional discount widget's mode + value together (used by loadDraft and the preset buttons) and highlights the matching mode button. */
    function setAdditionalDiscount(mode, value) {
        $('#posDiscountModePercent').toggleClass('active', mode !== 'peso');
        $('#posDiscountModePeso').toggleClass('active', mode === 'peso');
        $('#posAdditionalDiscountValue').val(value === '' || value == null ? '' : value);
    }

    /** Shows the compact "5% off / Remove" summary once a value is set, otherwise the editable preset/value form - or, for a Cashier/Staff session that hasn't been approved yet this checkout, a locked banner instead of either. Mirrors the Senior/PWD widget's applied-state pattern below. */
    function updateAdditionalDiscountAppliedView() {
        const mode = $('#posDiscountModePeso').hasClass('active') ? 'peso' : 'percent';
        const rawValue = Math.max(0, Number($('#posAdditionalDiscountValue').val()) || 0);
        const applied = rawValue > 0;
        const locked = !canOverridePrice && !additionalDiscountUnlocked;

        $('#posAdditionalDiscountLocked').toggleClass('d-none', !locked);
        $('#posAdditionalDiscountApplied').toggleClass('d-none', !applied);
        $('#posAdditionalDiscountForm').toggleClass('d-none', locked || applied);
        $('#btnRemoveAdditionalDiscount').toggleClass('d-none', locked);
        if (applied) {
            $('#posAdditionalDiscountAppliedText').text(mode === 'peso' ? money(rawValue) + ' off' : rawValue + '% off');
        }
    }

    /** Same pattern as the Additional discount widget: a compact "20% off (ID: ...) / Remove" summary once applied, otherwise the checkbox/ID-number form. */
    function updateSeniorPwdAppliedView() {
        const applied = $('#posApplySeniorPwd').is(':checked') && !!$('#posSeniorPwdIdNumber').val().trim();
        $('#posSeniorPwdAppliedRow').toggleClass('d-none', !applied);
        $('#posSeniorPwdForm').toggleClass('d-none', applied);
        if (applied) {
            const type = $('input[name="posSeniorPwdType"]:checked').val() === 'pwd' ? 'PWD' : 'Senior Citizen';
            $('#posSeniorPwdAppliedText').text(seniorPwdDiscountRate + '% off (ID: ' + $('#posSeniorPwdIdNumber').val().trim() + ') - ' + type);
        }
    }
    /**
     * The "Additional discount" widget (Payment modal, below the totals):
     * either a flat peso amount, or a percentage applied to the subtotal
     * net of item-level discounts (before tax) - mirrors how the Senior/
     * PWD discount computes its base, so stacking the two behaves
     * predictably instead of compounding off different numbers.
     */
    function getAdditionalDiscountCents(subtotalCents, lineDiscountTotalCents) {
        const mode = $('#posDiscountModePeso').hasClass('active') ? 'peso' : 'percent';
        const rawValue = Math.max(0, Number($('#posAdditionalDiscountValue').val()) || 0);
        if (mode === 'percent') {
            const base = Math.max(0, subtotalCents - lineDiscountTotalCents);
            return roundHalfUp(base * (Math.min(100, rawValue) / 100));
        }
        return toCents(rawValue);
    }

    /** Client-side preview only - mirrors Sale::priceCart()'s formula so the number matches what checkout will charge. */
    function computeTotals() {
        let subtotalCents = 0, taxTotalCents = 0, lineDiscountTotalCents = 0;

        cart.forEach(function (item) {
            const lineSubtotalCents = toCents(item.unit_price) * item.quantity;
            // Tax-inclusive: the sticker price already contains tax -
            // extract the tax-exclusive base from it instead of adding
            // tax on top, mirroring Sale::priceCart() exactly.
            const grossExclusiveCents = (taxInclusive && item.tax_rate > 0)
                ? roundHalfUp(lineSubtotalCents / (1 + item.tax_rate / 100))
                : lineSubtotalCents;
            const autoDiscountCents = roundHalfUp(grossExclusiveCents * (item.discount_rate / 100));
            const manualLineDiscountCents = Math.min(Math.max(0, toCents(item.discount)), Math.max(0, grossExclusiveCents - autoDiscountCents));
            const lineDiscountCents = autoDiscountCents + manualLineDiscountCents;
            // Tax is computed on the full tax-exclusive line amount,
            // independent of any discount - this app has always taxed the
            // sticker price itself, not the post-discount price (discount
            // is a pure deduction after tax). Mirrors Sale::priceCart()
            // exactly - do not recompute this from a discounted base.
            const lineTaxCents = roundHalfUp(grossExclusiveCents * (item.tax_rate / 100));
            subtotalCents += grossExclusiveCents;
            taxTotalCents += lineTaxCents;
            lineDiscountTotalCents += lineDiscountCents;
        });

        const manualDiscountCents = getAdditionalDiscountCents(subtotalCents, lineDiscountTotalCents);

        // Senior Citizen / PWD statutory discount - mirrors Sale::priceCart()'s
        // two-pass order: computed on the subtotal net of item/manual
        // discounts BEFORE loyalty for the redemption cap (matching the
        // server's first pass), then again net of loyalty too for the
        // actual charged total (matching the server's second/final pass).
        const seniorPwdApplied = seniorPwdEnabled && $('#posApplySeniorPwd').is(':checked');
        const seniorPwdRateFraction = Math.max(0, Math.min(100, seniorPwdDiscountRate)) / 100;
        const seniorPwdBaseBeforeLoyaltyCents = Math.max(0, subtotalCents - lineDiscountTotalCents - manualDiscountCents);
        const seniorPwdDiscountBeforeLoyaltyCents = seniorPwdApplied ? roundHalfUp(seniorPwdBaseBeforeLoyaltyCents * seniorPwdRateFraction) : 0;

        const requestedPoints = Math.max(0, Math.floor(Number($('#posPointsToRedeem').val()) || 0));
        const availablePoints = selectedCustomer ? Number(selectedCustomer.loyalty_points) || 0 : 0;
        const availableForRedemptionCents = Math.max(0, subtotalCents + taxTotalCents - lineDiscountTotalCents - manualDiscountCents - seniorPwdDiscountBeforeLoyaltyCents);
        const pointValueCents = Math.max(0, toCents(loyaltyRule.pointValue));
        const redeemedPoints = pointValueCents > 0
            ? Math.min(requestedPoints, availablePoints, Math.floor(availableForRedemptionCents / pointValueCents)) : 0;
        const loyaltyDiscountCents = redeemedPoints * pointValueCents;

        const seniorPwdBaseCents = Math.max(0, subtotalCents - lineDiscountTotalCents - manualDiscountCents - loyaltyDiscountCents);
        const seniorPwdDiscountCents = seniorPwdApplied ? roundHalfUp(seniorPwdBaseCents * seniorPwdRateFraction) : 0;

        const discountTotalCents = lineDiscountTotalCents + manualDiscountCents + loyaltyDiscountCents + seniorPwdDiscountCents;
        const grandTotalCents = Math.max(0, subtotalCents + taxTotalCents - discountTotalCents);

        return {
            subtotal: fromCents(subtotalCents), taxTotal: fromCents(taxTotalCents),
            discountTotal: fromCents(discountTotalCents), grandTotal: fromCents(grandTotalCents),
            manualDiscount: fromCents(manualDiscountCents), redeemedPoints,
            lineDiscountTotal: fromCents(lineDiscountTotalCents),
            loyaltyDiscount: fromCents(loyaltyDiscountCents),
            seniorPwdDiscount: fromCents(seniorPwdDiscountCents), seniorPwdApplied,
            availableForRedemptionCents, pointValueCents
        };
    }

    /** Populates the Subtotal/Tax/itemized-discount/Total block in the Payment modal. Shared by btnOpenPayment and updatePaymentModalTotals so the two can never show different numbers. */
    function renderPaymentTotals(totals) {
        $('#posPaymentSubtotal').text(money(totals.subtotal));
        $('#posPaymentTax').text(money(totals.taxTotal));
        $('#posPaymentTotal').text(money(totals.grandTotal));
        $('#posPaymentDiscount').text(money(totals.discountTotal));

        // Itemized breakdown - each row only shows when it actually
        // applies, so a plain cash sale with no discounts still just
        // sees a single "Total discount" line, not four zero rows.
        $('#posRowItemDiscount').toggleClass('d-none', totals.lineDiscountTotal <= 0);
        $('#posItemDiscountAmt').text(money(totals.lineDiscountTotal));

        $('#posRowSeniorPwdDiscount').toggleClass('d-none', !(totals.seniorPwdApplied && totals.seniorPwdDiscount > 0));
        $('#posSeniorPwdDiscountAmt').text(money(totals.seniorPwdDiscount));

        $('#posRowAdditionalDiscount').toggleClass('d-none', totals.manualDiscount <= 0);
        $('#posAdditionalDiscountAmt').text(money(totals.manualDiscount));

        $('#posRowLoyaltyDiscount').toggleClass('d-none', totals.loyaltyDiscount <= 0);
        $('#posLoyaltyDiscountAmt').text(money(totals.loyaltyDiscount));
    }

    /**
     * Refreshes the Payment modal's totals + Senior/PWD preview line -
     * called on every cart/discount-field change (including each
     * keystroke), so it deliberately does NOT touch the applied/form
     * visibility for the Additional Discount or Senior/PWD widgets -
     * doing that here used to hide the very field the person was
     * mid-keystroke in the instant they typed a non-zero value (see
     * updateAdditionalDiscountAppliedView/updateSeniorPwdAppliedView -
     * now only called on modal-open and their own Apply/Remove clicks).
     */
    function updatePaymentModalTotals() {
        if (!$('#paymentModal').hasClass('show')) return;
        const totals = computeTotals();
        renderPaymentTotals(totals);
        $('#posSeniorPwdPreview').text(
            totals.seniorPwdApplied && totals.seniorPwdDiscount > 0
                ? 'Discount: -' + money(totals.seniorPwdDiscount) + ' (' + seniorPwdDiscountRate + '%)'
                : ''
        );
        updateChangeDue();
    }

    /** Select the largest whole-point discount that does not exceed the amount due. */
    function autoFillRedeemablePoints() {
        if (!selectedCustomer) return;
        const totals = computeTotals();
        const points = totals.pointValueCents > 0
            ? Math.min(selectedCustomer.loyalty_points, Math.floor(totals.availableForRedemptionCents / totals.pointValueCents))
            : 0;
        $('#posPointsToRedeem').val(points > 0 ? points : '');
    }

    function renderCart() {
        const $body = $('#posCartBody');
        $body.empty();

        if (!cart.length) {
            $body.html('<tr><td colspan="4" class="pos-v2-empty"><i class="bi bi-bag"></i><span>Your cart is empty</span><small>Search or scan an item to begin</small></td></tr>');
        } else {
            cart.forEach(function (item) {
                const lineSubtotal = item.unit_price * item.quantity;
                const autoDiscount = Math.round(lineSubtotal * (item.discount_rate / 100) * 100) / 100;
                const manualDiscount = Math.min(Math.max(0, Number(item.discount) || 0), Math.max(0, lineSubtotal - autoDiscount));
                // The Total column is price x quantity minus this line's own
                // discounts - it should read exactly like the price tag
                // times the quantity. Tax is a store-wide add-on shown only
                // in the Subtotal/Tax/Discount/Total summary below, never
                // folded into an individual line so ₱80 x 1 doesn't
                // mysteriously show as ₱88.80.
                const lineTotal = Math.max(0, lineSubtotal - autoDiscount - manualDiscount);
                const bargained = item.unit_price < item.catalog_price;

                $body.append(`
                    <tr data-id="${item.line_id}" class="pos-v2-cart-item" tabindex="-1">
                        <td>
                            <div class="pos-cart-item-row">
                                <div class="pos-cart-thumb">${item.image_url ? `<img src="${escapeHtml(item.image_url)}" alt="">` : '<i class="bi bi-box-seam"></i>'}</div>
                                <div class="pos-cart-item-info">
                                    <div class="fw-medium">${escapeHtml(item.product_name)}${bargained ? ' <span class="badge pos-badge-soft pos-bargain-badge" title="Price negotiated below catalog price">Bargained</span>' : ''}${item.price_tier === 'wholesale' ? ' <span class="badge pos-badge-soft pos-wholesale-badge" title="Wholesale price">Wholesale</span>' : ''}</div>
                                    <div class="pos-cart-price-row">
                                        <button type="button" class="pos-cart-chip pos-cart-chip-price" data-action="edit-price" title="Edit price">
                                            <span>${money(item.unit_price)}/${escapeHtml(item.unit)}</span><i class="bi bi-pencil-fill"></i>
                                        </button>
                                        <button type="button" class="pos-cart-chip pos-cart-chip-discount${manualDiscount > 0 ? ' pos-cart-chip-active' : ''}" data-action="edit-discount" title="${manualDiscount > 0 ? 'Edit discount' : 'Add discount'}">
                                            <i class="bi bi-tag-fill"></i><span>${manualDiscount > 0 ? 'Discount ' + money(manualDiscount) : 'Discount'}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="pos-cart-qty-cell">
                            <div class="input-group input-group-sm pos-qty-control">
                                <button class="btn btn-outline-secondary btn-qty-minus" type="button" title="Decrease quantity" aria-label="Decrease quantity">&minus;</button>
                                <input type="number" class="form-control text-center cart-qty-input" value="${item.quantity}" min="1" max="${Math.min(item.quantity_on_hand, 10000)}" inputmode="numeric" aria-label="Quantity (maximum 10,000)">
                                <button class="btn btn-outline-secondary btn-qty-plus" type="button" title="Increase quantity" aria-label="Increase quantity">+</button>
                            </div>
                        </td>
                        <td class="text-end fw-medium">${money(lineTotal)}</td>
                        <td class="text-end"><button class="btn btn-sm text-danger btn-remove-item" type="button" title="Remove item" aria-label="Remove item">&times;</button></td>
                    </tr>
                `);
            });
        }

        const totalQuantity = cart.reduce(function (sum, item) { return sum + Number(item.quantity || 0); }, 0);
        $('#posItemCount').text(cart.length);
        $('#posTotalQuantity').text(totalQuantity);

        const totals = computeTotals();
        $('#posSubtotal').text(money(totals.subtotal));
        $('#posTax').text(money(totals.taxTotal));
        $('#posDiscount').text(money(totals.discountTotal));
        $('#posGrandTotal').text(money(totals.grandTotal));
        updatePaymentModalTotals(totals);
        const earned = loyaltyRule.spend > 0 && loyaltyRule.points > 0 ? Math.floor(totals.grandTotal / loyaltyRule.spend) * loyaltyRule.points : 0;
        $('#posLoyaltyPreview').toggleClass('d-none', !selectedCustomer).text(selectedCustomer ? `Loyalty: redeem ${totals.redeemedPoints} point(s) (${money(totals.loyaltyDiscount)}) · earn ${earned} point(s) on this sale.` : '');
        updateChangeDue();
        saveDraft();
    }

    // -----------------------------------------------------------------
    // Manager approval for price overrides / discounts
    // -----------------------------------------------------------------
    // A Cashier/Staff session (canOverridePrice === false) can't edit an
    // item's price or add a discount on their own - a manager/admin has
    // to clear a popup first, either by password or by scanning their
    // personal QR badge (Profile page). This never touches the current
    // login session; it's a one-time "someone with permission approved
    // this specific action" check, logged server-side either way.
    let pendingOverrideCallback = null;
    let pendingOverrideParentModalId = null; // which modal (if any) we hid to show the popup, to reshow once it closes
    let overrideApprovedThisRound = false;
    let overrideQrCode = null;
    let overrideCameraRunning = false;

    function requireOverrideApproval(reason, onApproved) {
        if (canOverridePrice) {
            onApproved();
            return;
        }
        pendingOverrideCallback = onApproved;
        $('#posOverrideReason').text(reason);
        $('#posOverrideError').addClass('d-none').text('');
        $('#posOverrideUsername').val('');
        $('#posOverridePassword').val('');
        bootstrap.Tab.getOrCreateInstance(document.getElementById('posOverridePasswordTabBtn')).show();

        // Bootstrap doesn't support two modals shown at the same time -
        // their focus traps fight each other (the password/QR fields
        // become untypable) and the backdrop bookkeeping breaks (the
        // page can end up stuck behind an invisible backdrop after
        // closing). If a modal is already open - e.g. Payment, for the
        // Additional discount button - hide it first and only open this
        // popup once that's fully finished closing.
        const openParent = document.querySelector('#editPriceModal.show, #editDiscountModal.show, #paymentModal.show');
        pendingOverrideParentModalId = openParent ? openParent.id : null;

        if (openParent) {
            openParent.addEventListener('hidden.bs.modal', function onParentHidden() {
                openParent.removeEventListener('hidden.bs.modal', onParentHidden);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('posOverrideModal')).show();
            }, { once: true });
            bootstrap.Modal.getOrCreateInstance(openParent).hide();
        } else {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('posOverrideModal')).show();
        }
    }

    function stopOverrideCamera() {
        if (!overrideCameraRunning || !overrideQrCode) return;
        overrideCameraRunning = false;
        overrideQrCode.stop().catch(function () { /* already stopped - fine */ });
    }

    function startOverrideCamera() {
        if (typeof Html5Qrcode === 'undefined') {
            $('#posOverrideQrError').removeClass('d-none').text('The camera scanner library could not be loaded. Use the password tab instead.');
            return;
        }
        $('#posOverrideQrError').addClass('d-none');
        if (!overrideQrCode) overrideQrCode = new Html5Qrcode('posOverrideCameraView');

        overrideQrCode.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 220, height: 220 } },
            function onScanSuccess(decodedText) {
                if (!overrideCameraRunning) return; // ignore stray callbacks after we've already stopped
                stopOverrideCamera();
                submitOverrideApproval('qr', { qr_code: decodedText });
            },
            function onScanFailure() { /* fired continuously while nothing is in frame - not an error */ }
        ).then(function () {
            overrideCameraRunning = true;
        }).catch(function (error) {
            overrideCameraRunning = false;
            $('#posOverrideQrError').removeClass('d-none').text('Could not access the camera. Grant camera permission, or use the password tab instead. (' + error + ')');
        });
    }

    /** Approval succeeded or failed for the current attempt - either way, request the popup close (or stays open on failure) via submitOverrideApproval below; #posOverrideModal's own 'hidden.bs.modal' handler (wired in the init block) does the actual parent-reshow + callback sequencing so it happens exactly once, from one place, regardless of how the popup closed. */
    function submitOverrideApproval(mode, extra) {
        const $btn = $('#btnPosOverrideApprove');
        $btn.prop('disabled', true);
        $('#posOverrideError').addClass('d-none').text('');

        const data = Object.assign({ action: 'authorize_override', mode: mode, reason: $('#posOverrideReason').text() }, extra || {});
        $.ajax({ url: ENDPOINT, method: 'POST', dataType: 'json', data: data })
            .done(function (res) {
                if (res.success) {
                    stopOverrideCamera();
                    overrideApprovedThisRound = true;
                    showToast('Approved by ' + res.approver_name);
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('posOverrideModal')).hide();
                } else {
                    $('#posOverrideError').removeClass('d-none').text(res.message || 'Could not approve this action.');
                    if (mode === 'qr') startOverrideCamera(); // keep scanning after a bad badge
                }
            })
            .fail(function (jq) {
                $('#posOverrideError').removeClass('d-none').text((jq.responseJSON && jq.responseJSON.message) || 'Could not approve this action.');
                if (mode === 'qr') startOverrideCamera();
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    }

    /** "Edit Price" modal - item name, old price, and a new price capped at catalog price (a bargain can only go down). */
    function openEditPriceModal(lineId) {
        const item = cart.find(function (i) { return Number(i.line_id) === lineId; });
        if (!item) return;

        requireOverrideApproval('Edit price - ' + item.product_name, function () {
            $('#editPriceItemName').text(item.product_name);
            $('#editPriceOld').text(money(item.unit_price) + ' / ' + item.unit);
            $('#editPriceCeiling').text(money(item.catalog_price));
            $('#editPriceNew').val(item.unit_price).attr('max', item.catalog_price).data('line-id', lineId);
            $('#editPriceError').addClass('d-none');

            bootstrap.Modal.getOrCreateInstance(document.getElementById('editPriceModal')).show();
        });
    }

    function applyEditPrice() {
        const lineId = Number($('#editPriceNew').data('line-id'));
        const item = cart.find(function (i) { return Number(i.line_id) === lineId; });
        if (!item) return;

        let value = Number($('#editPriceNew').val());
        if (!Number.isFinite(value) || value <= 0) {
            $('#editPriceError').removeClass('d-none').text('Enter a valid price.');
            return;
        }
        if (value > item.catalog_price) {
            // A bargained price can only go DOWN from the catalog price,
            // never above it - matches the server-side cap in
            // Sale::priceCart() so this preview never promises a total
            // checkout will then refuse to honor.
            $('#editPriceError').removeClass('d-none').text('Price cannot be higher than the catalog price of ' + money(item.catalog_price) + '.');
            return;
        }

        item.unit_price = Math.round(value * 100) / 100;
        renderCart();
        saveDraft();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editPriceModal')).hide();
    }

    /** "Add discount" modal - item name, original price, a %/₱ discount, and a live-computed new price. */
    function openEditDiscountModal(lineId) {
        const item = cart.find(function (i) { return Number(i.line_id) === lineId; });
        if (!item) return;

        requireOverrideApproval('Add discount - ' + item.product_name, function () {
            $('#editDiscountItemName').text(item.product_name);
            $('#editDiscountOriginal').text(money(item.unit_price) + ' / ' + item.unit);
            $('#editDiscountNew').data('line-id', lineId);
            $('#editDiscountError').addClass('d-none');

            // The existing per-item discount is stored as a total line amount;
            // show it back as a per-unit ₱ value so "Original Price -
            // Discount = New Price" reads naturally for a single unit.
            const perUnitDiscount = item.quantity > 0 ? (Number(item.discount) || 0) / item.quantity : 0;
            $('input[name="editDiscountType"][value="peso"]').prop('checked', true);
            $('#editDiscountValue').val(perUnitDiscount > 0 ? perUnitDiscount.toFixed(2) : '');
            updateEditDiscountPreview();

            bootstrap.Modal.getOrCreateInstance(document.getElementById('editDiscountModal')).show();
        });
    }

    function updateEditDiscountPreview() {
        const lineId = Number($('#editDiscountNew').data('line-id'));
        const item = cart.find(function (i) { return Number(i.line_id) === lineId; });
        if (!item) return;

        const type = $('input[name="editDiscountType"]:checked').val();
        const rawValue = Math.max(0, Number($('#editDiscountValue').val()) || 0);
        // Mirrors Sale::priceCart(): a manual discount can't cut into the
        // product's own automatic discount_rate twice - cap against what's
        // left of the unit price after that auto-discount, not the full
        // catalog/bargained price.
        const autoDiscountPerUnit = item.unit_price * (item.discount_rate / 100);
        const capPerUnit = Math.max(0, item.unit_price - autoDiscountPerUnit);
        const perUnitDiscount = type === 'percent'
            ? capPerUnit * Math.min(rawValue, 100) / 100
            : Math.min(rawValue, capPerUnit);
        const newPrice = Math.max(0, item.unit_price - autoDiscountPerUnit - perUnitDiscount);

        $('#editDiscountPreviewAmount').text('-' + money(perUnitDiscount) + ' / ' + item.unit);
        $('#editDiscountNewPrice').text(money(newPrice) + ' / ' + item.unit);
    }

    function applyEditDiscount() {
        const lineId = Number($('#editDiscountNew').data('line-id'));
        const item = cart.find(function (i) { return Number(i.line_id) === lineId; });
        if (!item) return;

        const type = $('input[name="editDiscountType"]:checked').val();
        const rawValue = Math.max(0, Number($('#editDiscountValue').val()) || 0);
        if (type === 'percent' && rawValue > 100) {
            $('#editDiscountError').removeClass('d-none').text('Percent discount cannot be more than 100%.');
            return;
        }
        const autoDiscountPerUnit = item.unit_price * (item.discount_rate / 100);
        const capPerUnit = Math.max(0, item.unit_price - autoDiscountPerUnit);
        const perUnitDiscount = type === 'percent'
            ? capPerUnit * Math.min(rawValue, 100) / 100
            : Math.min(rawValue, capPerUnit); // a line's discount can never exceed what's left after its own auto-discount

        item.discount = Math.round(perUnitDiscount * item.quantity * 100) / 100;
        renderCart();
        saveDraft();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editDiscountModal')).hide();
    }

    function saveDraft() {
        if (!draftReady || !draftKey) return;
        try {
            if (!cart.length) { localStorage.removeItem(draftKey); return; }
            // References are deliberately not saved in the browser draft.
            localStorage.setItem(draftKey, JSON.stringify({
                cart: cart,
                customer: selectedCustomer,
                discountMode: $('#posDiscountModePeso').hasClass('active') ? 'peso' : 'percent',
                discountValue: $('#posAdditionalDiscountValue').val(),
                pointsToRedeem: $('#posPointsToRedeem').val(),
                paymentMode: paymentMode,
                payments: paymentRows().map(function (payment) { return { method: payment.method, amount: payment.amount, reference: '' }; }),
                savedAt: new Date().toISOString()
            }));
        } catch (error) {
            // Private-mode browsers can deny local storage; POS can still run normally.
            console.warn('POS draft could not be saved.', error);
        }
    }

    function restoreDraft() {
        let draft;
        try { draft = JSON.parse(localStorage.getItem(draftKey) || 'null'); } catch (error) { localStorage.removeItem(draftKey); return; }
        if (!draft || !Array.isArray(draft.cart) || !draft.cart.length) return;
        const savedAtDate = draft.savedAt ? new Date(draft.savedAt) : null;
        if (!savedAtDate || Number.isNaN(savedAtDate.getTime()) || Date.now() - savedAtDate.getTime() > DRAFT_EXPIRY_MS) {
            localStorage.removeItem(draftKey);
            return;
        }
        const savedAt = savedAtDate.toLocaleString();
        if (!confirm('Restore the unfinished sale saved on this device (' + savedAt + ')?')) {
            localStorage.removeItem(draftKey);
            return;
        }
        cart = draft.cart.filter(function (item) { return Number(item.product_id) > 0 && Number(item.quantity) > 0; })
            .map(function (item) { if (!item.line_id) item.line_id = ++cartLineSeq; return item; });
        cartLineSeq = cart.reduce(function (max, item) { return Math.max(max, Number(item.line_id) || 0); }, cartLineSeq);
        selectedCustomer = draft.customer && Number(draft.customer.customer_id) > 0 ? draft.customer : null;
        setAdditionalDiscount(draft.discountMode || 'percent', draft.discountValue || '');
        $('#posPointsToRedeem').val(draft.pointsToRedeem || '');
        setPaymentMode(draft.paymentMode === 'split' ? 'split' : 'single');
        if (selectedCustomer) {
            $('#posCustomerSelected').text(selectedCustomer.full_name + ' — saved balance: ' + (Number(selectedCustomer.loyalty_points) || 0) + ' point(s)');
            $('#posAvailablePoints').text('— available: ' + (Number(selectedCustomer.loyalty_points) || 0));
            $('#posRedeemPointsWrap').removeClass('d-none');
        }
        renderPaymentRows(Array.isArray(draft.payments) && draft.payments.length ? draft.payments : undefined);
    }

    function paymentRows() {
        return $('#posPaymentRows .pos-payment-row-v2').map(function () {
            return { method: $(this).find('.payment-method').val(), amount: $(this).find('.payment-amount').val(), reference: String($(this).find('.payment-reference').val() || '').trim() };
        }).get();
    }

    function paymentRowHtml(payment, index, allRows) {
        const usedElsewhere = {};
        (allRows || []).forEach(function (row, i) { if (i !== index && row.method) usedElsewhere[row.method] = true; });
        const options = [['cash', 'Cash'], ['card', 'Card'], ['check', 'Check'], ['gcash', 'GCash'], ['maya', 'Maya']];
        const choices = options.filter(function (option) { return !cashPaymentOnly || option[0] === 'cash'; })
            .map(function (option) {
                // Each payment method can only be used once per sale - a
                // second "Card" (or "Cash", etc.) row is just the first
                // row with a different number, and lets the change-due
                // math double count it. Use the amount field on the
                // existing row instead of adding another of the same kind.
                const disabled = !!usedElsewhere[option[0]] && payment.method !== option[0];
                return `<option value="${option[0]}" ${payment.method === option[0] ? 'selected' : ''} ${disabled ? 'disabled' : ''}>${option[1]}${disabled ? ' (already used)' : ''}</option>`;
            }).join('');
        const isCash = payment.method === 'cash';
        const refPlaceholders = { gcash: 'GCash ref #', maya: 'Maya ref #', card: 'Card ref #', check: 'Check ref #' };
        const showBadge = paymentMode === 'split' && !cashPaymentOnly;
        const showRemove = index > 0 && !cashPaymentOnly;
        return `<div class="pos-payment-row-v2"><div class="pos-payment-row-main">${showBadge ? `<div class="pos-payment-row-badge">${index + 1}</div>` : ''}<select class="form-select payment-method">${choices}</select><div class="pos-payment-amount-wrap"><span class="pos-peso-prefix">₱</span><input type="number" class="form-control payment-amount" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" value="${escapeHtml(payment.amount)}"><button type="button" class="pos-fill-btn" title="Fill remaining balance">FILL</button></div>${showRemove ? '<button type="button" class="pos-remove-row-btn remove-payment" aria-label="Remove payment">&times;</button>' : ''}</div>${isCash ? '' : `<input type="text" class="form-control payment-reference pos-payment-ref-inline" placeholder="${refPlaceholders[payment.method] || 'Reference #'}" value="${escapeHtml(payment.reference)}">`}</div>`;
    }

    function renderPaymentRows(existingRows) {
        const rows = existingRows || paymentRows();
        if (!rows.length) rows.push({ method: 'cash', amount: '', reference: '' });
        if (cashPaymentOnly) {
            rows.splice(1);
            rows[0] = { method: 'cash', amount: rows[0].amount || '', reference: '' };
        } else if (paymentMode === 'single' && rows.length > 1) {
            rows.splice(1);
        }
        $('#posPaymentRows').html(rows.map(function (row, i) { return paymentRowHtml(row, i, rows); }).join(''));
        const allMethodsUsed = rows.length >= 5; // cash, card, check, gcash, maya
        $('#posPaymentModeToggle').toggleClass('d-none', cashPaymentOnly);
        $('#btnAddPayment').toggleClass('d-none', cashPaymentOnly || paymentMode !== 'split' || allMethodsUsed);
        $('#posSplitBanner').toggleClass('d-none', cashPaymentOnly || paymentMode !== 'split');
        $('#posCashKeypad').toggleClass('d-none', !cashPaymentOnly);
        updateChangeDue();
    }

    function setPaymentMode(mode) {
        paymentMode = mode === 'split' ? 'split' : 'single';
        $('#btnPaymentModeSingle').toggleClass('active', paymentMode === 'single');
        $('#btnPaymentModeSplit').toggleClass('active', paymentMode === 'split');
    }

    function updateChangeDue() {
        const paid = paymentRows().reduce(function (total, payment) { return total + (Number(payment.amount) || 0); }, 0);
        const total = computeTotals().grandTotal;
        const remaining = Math.max(0, total - paid);
        const change = Math.max(0, paid - total);
        $('#posPaymentPaidText').text(money(paid));
        $('#posPaymentDueText').text(money(total));
        $('#posSplitBannerTotal').text(money(total));
        $('#posPaymentRemainingText').text(remaining > 0 ? 'Remaining: ' + money(remaining) : 'Payment complete');
        $('#posChangeRow').toggle(paid > 0).find('#posChangeDue').text(money(change));
    }

    function resetCart() {
        cart = [];
        selectedCustomer = null;
        $('#posCustomerSelected').text('Walk-in customer');
        $('#posCustomerSearch').val('');
        setAdditionalDiscount('percent', '');
        $('#posPointsToRedeem').val('');
        $('#posRedeemPointsWrap').addClass('d-none');
        $('#posPaymentRows').empty();
        setPaymentMode('single');
        // Senior/PWD selection deliberately isn't carried into the browser
        // draft or the next sale - it includes a customer's ID number, and
        // each sale should require a fresh, deliberate choice rather than
        // silently reapplying to whoever's up next at the register.
        $('#posApplySeniorPwd').prop('checked', false);
        $('#posSeniorPwdFields').addClass('d-none');
        $('#posSeniorPwdIdNumber').val('');
        $('#posSeniorPwdTypeSenior').prop('checked', true);
        $('#posSeniorPwdPreview').text('');
        updateAdditionalDiscountAppliedView();
        updateSeniorPwdAppliedView();
        renderPaymentRows();
        renderCart();
    }

    // -----------------------------------------------------------------
    // Customer search
    // -----------------------------------------------------------------

    function searchCustomers(term) {
        if (!term) { $('#posCustomerResults').hide().empty(); return; }

        $.get(ENDPOINT, { action: 'customers', search: term })
            .done(function (res) {
                if (!res.success) return;
                const $results = $('#posCustomerResults');
                $results.empty();

                if (!res.customers.length) {
                    $results.append('<div class="list-group-item text-muted small">No matches</div>').show();
                    return;
                }

                res.customers.forEach(function (c) {
                    $results.append(`
                        <button type="button" class="list-group-item list-group-item-action" data-id="${c.customer_id}" data-name="${escapeHtml(c.full_name)}" data-points="${Number(c.loyalty_points) || 0}">
                            ${escapeHtml(c.full_name)} <span class="text-muted small">${escapeHtml(c.phone || '')} · ${Number(c.loyalty_points) || 0} point(s)</span>
                        </button>
                    `);
                });
                $results.show();
            })
            .fail(function (xhr) {
                console.error('POS: customer search request failed.', xhr.status, xhr.responseText);
                $('#posCustomerResults').empty()
                    .append('<div class="list-group-item text-danger small">Could not reach the server. Try again.</div>')
                    .show();
            });
    }

    // -----------------------------------------------------------------
    // Checkout / Hold
    // -----------------------------------------------------------------

    /**
     * @param totals Pass the SAME totals object doCheckout() already
     *   computed and validated against, rather than calling
     *   computeTotals() again here - two separate calls are supposed to
     *   be deterministic given identical DOM state, but sharing one
     *   object outright guarantees the guard check and what's actually
     *   sent to checkout() can never drift apart, however that might
     *   happen.
     */
    function buildPayload(totals, extra) {
        return $.extend({
            items: JSON.stringify(cart.map(function (i) { return { product_id: i.product_id, quantity: i.quantity, unit_price: i.unit_price, discount: i.discount || 0 }; })),
            customer_id: selectedCustomer ? selectedCustomer.customer_id : '',
            payments: JSON.stringify(paymentRows()),
            manual_discount: totals.manualDiscount,
            loyalty_points_redeemed: totals.redeemedPoints,
            senior_pwd_type: totals.seniorPwdApplied ? $('input[name="posSeniorPwdType"]:checked').val() : '',
            senior_pwd_id_number: totals.seniorPwdApplied ? $('#posSeniorPwdIdNumber').val().trim() : '',
        }, extra || {});
    }

    let isCheckingOut = false; // re-entrancy guard - a fast double-click/tap must not submit the sale twice
    function doCheckout() {
        if (isCheckingOut) return;
        if (!cart.length) { alert('The cart is empty.'); return; }
        const totals = computeTotals();
        const payments = paymentRows();
        const paid = payments.reduce(function (total, payment) { return total + (Number(payment.amount) || 0); }, 0);
        // Compare in whole centavos, not raw floats - "paid >= total" can
        // otherwise be rejected by a sub-centavo binary-float remainder
        // even when every displayed peso amount matches exactly.
        if (!payments.length || toCents(paid) < toCents(totals.grandTotal)) {
            alert('The combined payments are less than the total due.');
            return;
        }
        if (totals.seniorPwdApplied && !$('#posSeniorPwdIdNumber').val().trim()) {
            alert('Enter the Senior Citizen / PWD ID number to apply this discount.');
            return;
        }
        const missingReference = payments.find(function (payment) { return payment.method !== 'cash' && !payment.reference; });
        if (missingReference) {
            alert('Please enter reference details for every non-cash payment.');
            return;
        }

        isCheckingOut = true;
        $('#btnCheckout').prop('disabled', true);
        $.post(ENDPOINT, buildPayload(totals, { action: 'checkout' }))
            .done(function (res) {
                if (!res.success) { alert(res.message || 'Checkout failed.'); return; }
                lastSaleId = res.sale_id;
                const change = Math.max(0, paid - totals.grandTotal);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal')).hide();
                resetCart();
                loadProducts();
                if (window.POSShift) window.POSShift.refresh(); // nudge Total Sales/Cash Sales/Transactions immediately instead of waiting for the next poll
                // Receipt first, then the Payment Complete confirmation once
                // the cashier closes (or finishes printing) the receipt. If
                // receipts are turned off in Settings, skip straight to it.
                if (receiptPreferences.show) {
                    $('#receiptModal').off('hidden.bs.modal.paymentFlow').one('hidden.bs.modal.paymentFlow', function () {
                        showPaymentComplete(res.sale_id, res.invoice_no, paid, change);
                    });
                    showReceipt(res.sale_id, receiptPreferences.autoPrint);
                } else {
                    showPaymentComplete(res.sale_id, res.invoice_no, paid, change);
                }
            })
            .fail(function (xhr) {
                alert((xhr.responseJSON && xhr.responseJSON.message) || 'Checkout failed.');
            })
            .always(function () { $('#btnCheckout').prop('disabled', false); isCheckingOut = false; });
    }

    /**
     * "Payment Complete" confirmation shown after the receipt has been
     * shown/printed. Auto-closes after a 20s countdown (handy at a
     * counter where the cashier's hands are busy with cash/card), but the
     * Done button lets them dismiss it immediately.
     */
    let paymentCompleteTimer = null;
    function showPaymentComplete(saleId, invoiceNo, amountPaid, change) {
        $('#paymentCompleteInvoice').text(invoiceNo || ('#' + saleId));
        $('#paymentCompleteAmountPaid').text(money(amountPaid));
        $('#paymentCompleteChange').text(money(change));

        const totalSeconds = 20;
        let secondsLeft = totalSeconds;
        $('#paymentCompleteCountdownText').text('Continuing in ' + secondsLeft + 's…');
        $('#paymentCompleteProgressBar').css('width', '100%');

        const modalEl = document.getElementById('paymentCompleteModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        function finish() {
            clearInterval(paymentCompleteTimer);
            paymentCompleteTimer = null;
            modal.hide();
        }

        clearInterval(paymentCompleteTimer);
        paymentCompleteTimer = setInterval(function () {
            secondsLeft -= 1;
            $('#paymentCompleteProgressBar').css('width', Math.max(0, (secondsLeft / totalSeconds) * 100) + '%');
            if (secondsLeft <= 0) { finish(); return; }
            $('#paymentCompleteCountdownText').text('Continuing in ' + secondsLeft + 's…');
        }, 1000);

        $('#btnPaymentCompleteDone').off('click').on('click', finish);
        $(modalEl).off('hidden.bs.modal.paymentComplete').one('hidden.bs.modal.paymentComplete', function () {
            clearInterval(paymentCompleteTimer);
            paymentCompleteTimer = null;
        });

        modal.show();
    }

    function doHold() {
        if (!cart.length) { alert('The cart is empty.'); return; }

        $.post(ENDPOINT, buildPayload(computeTotals(), { action: 'hold' }))
            .done(function (res) {
                if (!res.success) { alert(res.message || 'Could not hold this sale.'); return; }
                resetCart();
                refreshHeldBadge();
            })
            .fail(function (xhr) {
                alert((xhr.responseJSON && xhr.responseJSON.message) || 'Could not hold this sale.');
            });
    }

    // -----------------------------------------------------------------
    // Held sales
    // -----------------------------------------------------------------

    function refreshHeldBadge() {
        $.get(ENDPOINT, { action: 'held_list' })
            .done(function (res) {
                if (!res.success) return;
                const count = res.held.length;
                $('#heldCountBadge').text(count).toggle(count > 0);
            })
            .fail(function (xhr) {
                console.error('POS: could not refresh held-sales badge.', xhr.status, xhr.responseText);
            });
    }

    function loadHeldList() {
        $('#heldSalesList').html('<div class="text-center text-muted py-4">Loading...</div>');
        $.get(ENDPOINT, { action: 'held_list' })
            .done(function (res) {
                if (!res.success) return;
                const $list = $('#heldSalesList');
                $list.empty();

                if (!res.held.length) {
                    $list.html('<div class="text-center text-muted py-4">No held sales.</div>');
                    return;
                }

                res.held.forEach(function (sale) {
                    $list.append(`
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div class="pos-cart-item-row">
                                <div class="pos-cart-thumb">${sale.thumbnail_url ? `<img src="${escapeHtml(sale.thumbnail_url)}" alt="">` : '<i class="bi bi-bag"></i>'}</div>
                                <div class="pos-cart-item-info">
                                    <div class="fw-medium">${escapeHtml(sale.invoice_no)}</div>
                                    <div class="text-muted small">${sale.item_count} item(s) · ${escapeHtml(sale.customer_name || 'Walk-in')} · ${money(sale.grand_total)}</div>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm pos-btn-primary btn-resume-held" data-id="${sale.sale_id}">Resume</button>
                                <button class="btn btn-sm btn-outline-danger btn-void-held" data-id="${sale.sale_id}">Void</button>
                            </div>
                        </div>
                    `);
                });
            })
            .fail(function (xhr) {
                console.error('POS: could not load held sales.', xhr.status, xhr.responseText);
                $('#heldSalesList').html('<div class="text-center text-danger py-4">Could not load held sales. Please try again.</div>');
            });
    }

    function resumeHeld(saleId) {
        $.get(ENDPOINT, { action: 'held_get', id: saleId }).done(function (res) {
            if (!res.success) { alert(res.message || 'Could not load that held sale.'); return; }

            const sale = res.sale;
            cart = sale.items.map(function (item) {
                const catalogPrice = Number(item.selling_price);
                const heldUnitPrice = Number(item.held_unit_price);
                // A held sale's unit_price/discount_amount reflect whatever
                // was bargained before it was put on hold - restore those
                // instead of quietly reverting to the current catalog price.
                const unitPrice = heldUnitPrice > 0 ? Math.min(heldUnitPrice, catalogPrice) : catalogPrice;
                const taxRate = Number(item.tax_rate) || 0;
                const discountRate = Number(item.discount_rate) || 0;
                const lineSubtotal = unitPrice * item.quantity;
                const autoDiscount = Math.round(lineSubtotal * (discountRate / 100) * 100) / 100;
                const manualDiscount = Math.max(0, Math.round((Number(item.held_discount || 0) - autoDiscount) * 100) / 100);
                const wholesalePrice = item.wholesale_price != null ? Number(item.wholesale_price) : null;
                // SaleDetails doesn't store which tier was used (the actual
                // charged price is what matters financially, and that's
                // already restored correctly above) - infer the badge by
                // matching the held price back to this product's wholesale
                // price, so it reappears unless it was bargained further.
                const inferredTier = (wholesalePrice && Math.abs(unitPrice - wholesalePrice) < 0.005) ? 'wholesale' : 'retail';
                return {
                    line_id: ++cartLineSeq,
                    product_id: item.product_id,
                    product_name: item.product_name,
                    unit_price: unitPrice,
                    catalog_price: catalogPrice,
                    tax_rate: taxRate, discount_rate: discountRate, discount: manualDiscount,
                    unit: item.unit,
                    quantity: item.quantity,
                    quantity_on_hand: Number(item.quantity_on_hand),
                    price_tier: inferredTier,
                    image_url: item.image_url || null,
                };
            });

            if (sale.customer_id) {
                selectedCustomer = { customer_id: sale.customer_id, full_name: sale.customer_name, loyalty_points: 0 };
                $('#posCustomerSelected').text(sale.customer_name);
            }

            // Clear the held row now that its cart has been restored into the active cart.
            $.post(ENDPOINT, { action: 'held_delete', id: saleId }).always(function () {
                refreshHeldBadge();
            });

            renderCart();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('heldSalesModal')).hide();
        }).fail(function (xhr) {
            alert((xhr.responseJSON && xhr.responseJSON.message) || 'Could not load that held sale.');
        });
    }

    function voidHeld(saleId) {
        if (!confirm('Void this held sale? This cannot be undone.')) return;

        $.post(ENDPOINT, { action: 'held_delete', id: saleId })
            .done(function (res) {
                if (res.success) { loadHeldList(); refreshHeldBadge(); }
            })
            .fail(function (xhr) {
                alert((xhr.responseJSON && xhr.responseJSON.message) || 'Could not void that held sale.');
            });
    }

    // -----------------------------------------------------------------
    // Receipt
    // -----------------------------------------------------------------

    function showReceipt(saleId, autoPrint) {
        $.get(ENDPOINT, { action: 'receipt', id: saleId })
            .done(function (res) {
                if (!res.success) return;
                window.POSReceipt.render($('#receiptContent'), res.sale, res.settings || {});
                const modalEl = document.getElementById('receiptModal');
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                if (autoPrint) {
                    $(modalEl).one('shown.bs.modal', function () { setTimeout(function () { window.print(); }, 250); });
                }
                modal.show();
            })
            .fail(function (xhr) {
                alert((xhr.responseJSON && xhr.responseJSON.message) || 'Could not load the receipt for that sale.');
            });
    }

    // -----------------------------------------------------------------
    // Events
    // -----------------------------------------------------------------

    $(function () {
        // Customer selection belongs to the final sale review, immediately above its totals.
        $('.pos-v2-payment-totals').first().before(`
            <section class="pos-v2-customer position-relative pos-v2-modal-customer">
                <label for="posCustomerSearch">Customer <span>optional</span></label>
                <div class="pos-v2-search-field"><i class="bi bi-person"></i><input type="text" id="posCustomerSearch" placeholder="Search customer"><button type="button" id="btnClearCustomer" title="Use walk-in customer" aria-label="Clear customer"><i class="bi bi-x"></i></button></div>
                <div class="pos-v2-selected-customer" id="posCustomerSelected">Walk-in customer</div>
                <div class="mt-2 d-none" id="posRedeemPointsWrap"><label class="form-label small mb-1" for="posPointsToRedeem">Redeem loyalty points <span id="posAvailablePoints"></span></label><input type="number" class="form-control form-control-sm" id="posPointsToRedeem" min="0" step="1" inputmode="numeric" placeholder="Points to use"><div class="form-text" id="posPointValueHint"></div></div>
                <div class="list-group position-absolute shadow-sm" id="posCustomerResults" style="z-index:20;width:100%;display:none;"></div>
            </section>
        `);
        $('body').append('<div class="pos-v2-toast" id="posItemToast" role="status" aria-live="polite"><i class="bi bi-check-circle-fill"></i><span id="posItemToastText"></span></div>');
        renderPaymentRows();
        loadFormData();
        loadProducts();
        renderCart();
        refreshHeldBadge();

        $('#posScanInput').on('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const code = $(this).val().trim();
            if (!code) return;
            lookupCode(code, { onDone: function () { $('#posScanInput').val(''); } });
        });

        $('#posScanInput').on('input', function () {
            $('#posScanStatus').addClass('d-none');
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(loadProducts, 300);
        });

        $('#posCategoryFilter').on('change', loadProducts);
        $('#posProductPagination').on('click', '[data-pos-page]', function () { productPage = Number($(this).data('pos-page')); loadProducts(); });
        $(document).on('click', '#btnRetryLoadProducts', loadProducts);

        $(document).on('click', '#posCartBody .btn-qty-plus', function (event) {
            event.preventDefault();
            changeQty(Number($(this).closest('tr').data('id')), 1);
        });
        $(document).on('click', '#posCartBody .btn-qty-minus', function (event) {
            event.preventDefault();
            changeQty(Number($(this).closest('tr').data('id')), -1);
        });
        $(document).on('click', '#posCartBody .btn-remove-item', function (event) {
            event.preventDefault();
            removeFromCart(Number($(this).closest('tr').data('id')));
        });
        $(document).on('change', '#posCartBody .cart-qty-input', function () {
            const item = cart.find(i => Number(i.line_id) === Number($(this).closest('tr').data('id')));
            const value = Math.floor(Number($(this).val()));
            if (!item || !Number.isFinite(value) || value < 1 || value > Math.min(item.quantity_on_hand, 10000)) { renderCart(); return; }
            item.quantity = value; renderCart();
        });
        // Per-item bargained price and per-item discount both open a
        // small confirmation modal rather than editing inline - clearer
        // at a glance for a cashier mid-negotiation, and hard to bump by
        // accident while scrolling the cart on a touchscreen.
        $(document).on('click', '#posCartBody [data-action="edit-price"]', function () {
            openEditPriceModal(Number($(this).closest('tr').data('id')));
        });
        $(document).on('click', '#posCartBody [data-action="edit-discount"]', function () {
            openEditDiscountModal(Number($(this).closest('tr').data('id')));
        });
        $('#btnApplyEditPrice').on('click', applyEditPrice);
        $('#editPriceNew').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); applyEditPrice(); } });
        $('#btnApplyEditDiscount').on('click', applyEditDiscount);
        $('#editDiscountValue').on('input', updateEditDiscountPreview);
        $('input[name="editDiscountType"]').on('change', updateEditDiscountPreview);

        // Manager approval popup (price override / discount)
        $('#btnPosOverrideApprove').on('click', function () {
            submitOverrideApproval('password', { username: $('#posOverrideUsername').val(), password: $('#posOverridePassword').val() });
        });
        $('#posOverridePassword').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#btnPosOverrideApprove').click(); } });
        $('#posOverrideQrTabBtn').on('shown.bs.tab', function () { startOverrideCamera(); });
        $('#posOverrideQrTabBtn').on('hide.bs.tab', function () { stopOverrideCamera(); });
        $('#posOverridePasswordTabBtn').on('shown.bs.tab', function () { stopOverrideCamera(); });
        $('#posOverrideModal').on('shown.bs.modal', function () {
            if ($('#posOverridePasswordTabBtn').hasClass('active')) {
                $('#posOverrideUsername').trigger('focus');
            }
        });
        $('#posOverrideModal').on('hidden.bs.modal', function () {
            stopOverrideCamera();
            const callback = pendingOverrideCallback;
            pendingOverrideCallback = null;
            const parentId = pendingOverrideParentModalId;
            pendingOverrideParentModalId = null;
            const approved = overrideApprovedThisRound;
            overrideApprovedThisRound = false;

            const runCallback = function () {
                if (approved && callback) callback();
            };

            if (parentId) {
                const parentEl = document.getElementById(parentId);
                parentEl.addEventListener('shown.bs.modal', function onParentShown() {
                    parentEl.removeEventListener('shown.bs.modal', onParentShown);
                    runCallback();
                }, { once: true });
                bootstrap.Modal.getOrCreateInstance(parentEl).show();
            } else {
                runCallback();
            }
        });

        $('#posPointsToRedeem').on('input', renderCart);
        $('#posAdditionalDiscountValue').on('input', function () { $('.pos-discount-preset').removeClass('active'); renderCart(); });
        $('.pos-discount-preset').on('click', function () {
            $('.pos-discount-preset').removeClass('active');
            $(this).addClass('active');
            setAdditionalDiscount('percent', $(this).data('value'));
            renderCart();
        });
        $('#posDiscountModePercent, #posDiscountModePeso').on('click', function () {
            if ($(this).data('mode') === 'peso') $('.pos-discount-preset').removeClass('active');
            setAdditionalDiscount($(this).data('mode'), $('#posAdditionalDiscountValue').val());
            renderCart();
        });
        $('#btnRemoveAdditionalDiscount').on('click', function () {
            $('.pos-discount-preset').removeClass('active');
            setAdditionalDiscount('percent', '');
            renderCart();
            updateAdditionalDiscountAppliedView();
            saveDraft();
        });
        $('#btnUnlockAdditionalDiscount').on('click', function () {
            requireOverrideApproval('Additional discount at checkout', function () {
                additionalDiscountUnlocked = true;
                updateAdditionalDiscountAppliedView();
            });
        });
        $('#btnApplyAdditionalDiscount').on('click', function () {
            renderCart();
            updateAdditionalDiscountAppliedView();
            saveDraft();
        });
        $('#btnRemoveSeniorPwd').on('click', function () {
            $('#posApplySeniorPwd').prop('checked', false);
            $('#posSeniorPwdFields').addClass('d-none');
            $('#posSeniorPwdIdNumber').val('');
            updatePaymentModalTotals();
            updateSeniorPwdAppliedView();
            saveDraft();
        });
        $('#btnApplySeniorPwd').on('click', function () {
            if (!$('#posSeniorPwdIdNumber').val().trim()) {
                alert('Enter the Senior Citizen / PWD ID number before applying this discount.');
                return;
            }
            updatePaymentModalTotals();
            updateSeniorPwdAppliedView();
            saveDraft();
        });
        $('#posApplySeniorPwd').on('change', function () {
            const checked = $(this).is(':checked');
            $('#posSeniorPwdFields').toggleClass('d-none', !checked);
            if (!checked) $('#posSeniorPwdIdNumber').val('');
            updatePaymentModalTotals();
        });
        $('input[name="posSeniorPwdType"], #posSeniorPwdIdNumber').on('change input', updatePaymentModalTotals);
        $('#posPaymentRows').on('input', '.payment-amount, .payment-reference', function () { updateChangeDue(); saveDraft(); });
        $('#posPaymentRows').on('change', '.payment-method', function () { renderPaymentRows(paymentRows()); saveDraft(); });
        $('#btnAddPayment').on('click', function () {
            const rows = paymentRows();
            const paid = rows.reduce(function (total, payment) { return total + (Number(payment.amount) || 0); }, 0);
            const usedMethods = rows.map(function (r) { return r.method; });
            // Cash is prioritised elsewhere via the keypad, so default a new
            // row to the next unused non-cash method instead of always
            // "card" - once card's already in use that option is disabled,
            // and defaulting to it would add a row nothing can be selected in.
            const nextMethod = ['card', 'gcash', 'maya', 'check', 'cash'].find(function (m) { return usedMethods.indexOf(m) === -1; }) || 'card';
            rows.push({ method: nextMethod, amount: Math.max(0, computeTotals().grandTotal - paid).toFixed(2), reference: '' });
            renderPaymentRows(rows);
            saveDraft();
        });
        $('#posPaymentRows').on('click', '.remove-payment', function () { $(this).closest('.pos-payment-row-v2').remove(); updateChangeDue(); saveDraft(); });
        $('#posPaymentRows').on('click', '.pos-fill-btn', function () {
            const $row = $(this).closest('.pos-payment-row-v2');
            const idx = $('#posPaymentRows .pos-payment-row-v2').index($row);
            const rows = paymentRows();
            const total = computeTotals().grandTotal;
            const othersPaid = rows.reduce(function (sum, row, i) { return i === idx ? sum : sum + (Number(row.amount) || 0); }, 0);
            const remaining = Math.max(0, Math.round((total - othersPaid) * 100) / 100);
            $row.find('.payment-amount').val(remaining.toFixed(2));
            updateChangeDue();
            saveDraft();
        });
        $('#btnPaymentModeSingle, #btnPaymentModeSplit').on('click', function () {
            const mode = $(this).data('mode');
            if (mode === paymentMode) return;
            setPaymentMode(mode);
            if (mode === 'single') {
                // Collapsing back to one method - default its amount to
                // cover the full total, since that's the point of picking
                // "Single payment" instead of splitting across tenders.
                const rows = paymentRows();
                renderPaymentRows([{ method: rows[0] ? rows[0].method : 'cash', amount: computeTotals().grandTotal.toFixed(2), reference: '' }]);
            } else {
                renderPaymentRows();
            }
            saveDraft();
        });
        $(document).on('click', '#posCashKeypad button', function () {
            const $amount = $('#posPaymentRows .payment-amount').first();
            const key = String($(this).data('key')); let value = $amount.val() || '';
            if (key === 'clear') value = ''; else if (key === 'back') value = value.slice(0, -1);
            else if (key === '.') { if (value.includes('.')) return; value = value ? value + '.' : '0.'; }
            else if (!value.includes('.') || value.split('.')[1].length < 2) value = value === '0' ? key : value + key;
            $amount.val(value).trigger('input');
        });

        $('#posCustomerSearch').on('input', function () {
            const term = $(this).val().trim();
            clearTimeout(customerDebounce);
            customerDebounce = setTimeout(function () { searchCustomers(term); }, 250);
        });
        $('#posCustomerResults').on('click', 'button', function () {
            selectedCustomer = { customer_id: Number($(this).data('id')), full_name: $(this).data('name'), loyalty_points: Number($(this).data('points')) || 0 };
            $('#posCustomerSelected').text(selectedCustomer.full_name + ' — current balance: ' + selectedCustomer.loyalty_points + ' point(s)');
            $('#posAvailablePoints').text('— available: ' + selectedCustomer.loyalty_points);
            $('#posPointValueHint').text('1 point = ' + money(loyaltyRule.pointValue) + ' discount.');
            $('#posRedeemPointsWrap').removeClass('d-none');
            autoFillRedeemablePoints();
            $('#posCustomerSearch').val('');
            $('#posCustomerResults').hide().empty();
            renderCart();
        });
        $('#btnClearCustomer').on('click', function () {
            selectedCustomer = null;
            $('#posCustomerSelected').text('Walk-in customer');
            $('#posPointsToRedeem').val('');
            $('#posRedeemPointsWrap').addClass('d-none');
            $('#posCustomerSearch').val('');
            $('#posCustomerResults').hide().empty();
            renderCart();
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#posCustomerSearch, #posCustomerResults').length) {
                $('#posCustomerResults').hide();
            }
        });

        $('#btnClearCart').on('click', function () {
            if (cart.length && !confirm('Clear the current cart?')) return;
            resetCart();
        });
        $('#btnOpenPayment').on('click', function () {
            if (!cart.length) { alert('The cart is empty.'); return; }
            // A shift is required before completing a sale (Reconciliation
            // and the End of Day figures depend on sales being tied to a
            // shift) - if the cashier cancelled the initial prompt, this
            // re-shows Start Shift instead of opening Payment. Skipped
            // entirely if the Shifts migration hasn't been run yet
            // (window.POSShift wouldn't exist / hasActiveShift undefined).
            if (window.POSShift && typeof window.POSShift.requireShift === 'function' && !window.POSShift.requireShift()) {
                return;
            }
            renderPaymentTotals(computeTotals());
            additionalDiscountUnlocked = false;
            updateAdditionalDiscountAppliedView();
            updateSeniorPwdAppliedView();
            renderPaymentRows();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal')).show();
        });
        $('#btnCheckout').on('click', doCheckout);
        $('#btnHoldSale').on('click', doHold);

        $('#btnHeldSales').on('click', function () {
            loadHeldList();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('heldSalesModal')).show();
        });
        $('#heldSalesList').on('click', '.btn-resume-held', function () { resumeHeld(Number($(this).data('id'))); });
        $('#heldSalesList').on('click', '.btn-void-held', function () { voidHeld(Number($(this).data('id'))); });

        $('#btnPrintReceipt').on('click', function () { window.print(); });

        $('#btnOpenCatalog').on('click', function () {
            loadProducts();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('catalogModal')).show();
        });

        $('#btnOpenScanner').on('click', function () {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('scannerModal')).show();
        });

        // -------------------------------------------------------------
        // Keyboard shortcuts
        // -------------------------------------------------------------
        // Every shortcut below just triggers the SAME button an actual
        // click would (so whatever confirms/guards that button already
        // has - e.g. "cart is empty" - still apply, nothing bypasses
        // them). Shortcuts that open a window refuse to fire while any
        // other window is already open, so a shortcut can never stack a
        // second window on top of one that's already showing - the
        // exact bug class fixed elsewhere in this file for the
        // approval popup.
        $('#btnShowShortcuts').on('click', function () {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('posShortcutsModal')).show();
        });

        $(document).on('keydown', function (e) {
            const key = e.key.toLowerCase();
            const noModalOpen = !document.querySelector('.modal.show');
            const paymentModalOpen = document.getElementById('paymentModal').classList.contains('show');

            // Ctrl+S - focus the search/barcode box, hands off the mouse
            // entirely for the most common action on this screen.
            if (e.ctrlKey && !e.altKey && key === 's') {
                e.preventDefault();
                if (noModalOpen) $('#posScanInput').trigger('focus').trigger('select');
                return;
            }

            // Ctrl+/ - this shortcuts reference itself.
            if (e.ctrlKey && !e.altKey && key === '/') {
                e.preventDefault();
                if (noModalOpen) bootstrap.Modal.getOrCreateInstance(document.getElementById('posShortcutsModal')).show();
                return;
            }

            // Ctrl+Enter - move the sale forward: open Payment, or if
            // it's already open, confirm it (same as clicking Save sale).
            if (e.ctrlKey && !e.altKey && e.key === 'Enter') {
                e.preventDefault();
                if (paymentModalOpen) {
                    $('#btnCheckout').trigger('click');
                } else if (noModalOpen) {
                    $('#btnOpenPayment').trigger('click');
                }
                return;
            }

            if (!e.ctrlKey || !e.altKey) return; // everything else is Ctrl+Alt+<letter>

            switch (key) {
                case 'b':
                    e.preventDefault();
                    if (noModalOpen) $('#btnOpenScanner').trigger('click');
                    break;
                case 'i':
                    e.preventDefault();
                    if (noModalOpen) $('#btnOpenCatalog').trigger('click');
                    break;
                case 'h':
                    e.preventDefault();
                    if (noModalOpen) $('#btnHoldSale').trigger('click');
                    break;
                case 'j':
                    e.preventDefault();
                    if (noModalOpen) $('#btnHeldSales').trigger('click');
                    break;
                case 'p':
                    e.preventDefault();
                    if (noModalOpen) $('#btnOpenPayment').trigger('click');
                    break;
                case 'c':
                    e.preventDefault();
                    if (noModalOpen) $('#btnClearCart').trigger('click');
                    break;
                case 'u':
                    e.preventDefault();
                    if (paymentModalOpen) $('#posCustomerSearch').trigger('focus');
                    break;
            }
        });

    });

    // Exposed so assets/js/pos-scanner.js (the camera/QR/manual-input
    // modal) can reuse the exact same lookup + add-to-cart pipeline as
    // the keyboard-wedge scanner input, instead of duplicating it.
    window.POSCart = { lookupCode: lookupCode, addToCart: addToCart, focusScanInput: focusScanInput };
})(jQuery);
