const returnButtons = document.querySelectorAll('[data-return-action], .mini-button');
const returnsToast = document.querySelector('[data-returns-toast]');
const receiptDataElement = document.querySelector('#receipt-selector-data');

let receipts = [];
if (receiptDataElement) {
    try {
        receipts = JSON.parse(receiptDataElement.textContent);
    } catch (error) {
        receipts = [];
    }
}

const findReceipt = (value) => {
    const normalized = value.trim().toLowerCase();
    return receipts.find((receipt) => (
        receipt.label.toLowerCase() === normalized || receipt.number.toLowerCase() === normalized
    ));
};

document.querySelectorAll('[data-receipt-form]').forEach((form) => {
    const search = form.querySelector('[data-receipt-search]');
    const receiptId = form.querySelector('[data-receipt-id]');
    const product = form.querySelector('[data-receipt-product]');
    const preview = form.querySelector('[data-receipt-preview]');
    const quantity = form.querySelector('[data-item-quantity]');
    const refund = form.querySelector('[data-refund-amount]');
    const refundValue = form.querySelector('[data-refund-value]');

    if (!search || !receiptId || !product || !preview || !quantity) return;

    const updateLimits = () => {
        const option = product.selectedOptions[0];
        if (!option?.dataset.available) {
            if (refundValue) refundValue.hidden = true;
            return;
        }

        const available = Number(option.dataset.available);
        const unitPrice = Number(option.dataset.unitPrice);
        const selectedQuantity = Math.max(Number(quantity.value) || 1, 1);
        const maximumRefund = unitPrice * selectedQuantity;
        quantity.max = String(available);
        quantity.setAttribute('aria-description', `Maximum ${available} item(s) from this receipt.`);

        if (refundValue) {
            refundValue.innerHTML = `
                <span>Selected item value</span>
                <strong>₱${unitPrice.toFixed(2)} each</strong>
                <small>${selectedQuantity} × ₱${unitPrice.toFixed(2)} = <b>₱${maximumRefund.toFixed(2)}</b> maximum refundable value</small>
            `;
            refundValue.hidden = false;
        }

        if (refund) {
            refund.max = maximumRefund.toFixed(2);
            refund.placeholder = `Up to ₱${maximumRefund.toFixed(2)}`;
        }
    };

    const showReceipt = (receipt) => {
        receiptId.value = receipt?.id ?? '';
        product.innerHTML = '';

        if (!receipt) {
            product.append(new Option('Select a valid receipt first', ''));
            product.disabled = true;
            preview.hidden = true;
            return;
        }

        product.append(new Option('Select an item from this receipt', ''));
        receipt.items.forEach((item) => {
            const option = new Option(
                `${item.sku} — ${item.name} — ₱${Number(item.unit_price).toFixed(2)} each (available ${item.available_quantity})`,
                item.product_id,
            );
            option.dataset.available = item.available_quantity;
            option.dataset.unitPrice = item.unit_price;
            product.append(option);
        });
        product.disabled = receipt.items.length === 0;
        const itemRows = receipt.items.map((item) => `
            <div class="receipt-preview-item">
                <div><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.sku)} · ${item.available_quantity} of ${item.quantity_sold} available</small></div>
                <div class="receipt-item-value"><strong>₱${Number(item.unit_price).toFixed(2)}</strong><small>each · ₱${Number(item.available_value).toFixed(2)} available value</small></div>
            </div>
        `).join('');
        preview.innerHTML = `
            <div class="receipt-preview-header">
                <strong>${escapeHtml(receipt.number)}</strong>
                <span>${escapeHtml(receipt.date)}</span>
                <span>Cashier: ${escapeHtml(receipt.cashier)}</span>
                <span>Receipt total: ₱${escapeHtml(receipt.total)}</span>
            </div>
            <div class="receipt-preview-items">${itemRows}</div>
        `;
        preview.hidden = false;

        const oldValue = product.dataset.oldValue;
        if (oldValue && [...product.options].some((option) => option.value === oldValue)) {
            product.value = oldValue;
            product.dataset.oldValue = '';
            updateLimits();
        }
    };

    const syncReceipt = () => showReceipt(findReceipt(search.value));
    search.addEventListener('input', syncReceipt);
    search.addEventListener('change', syncReceipt);
    product.addEventListener('change', updateLimits);
    quantity.addEventListener('input', updateLimits);


    form.addEventListener('submit', (event) => {
        const receipt = findReceipt(search.value);
        if (!receipt) {
            event.preventDefault();
            search.setCustomValidity('Choose a receipt from the search suggestions.');
            search.reportValidity();
            return;
        }
        search.setCustomValidity('');
        receiptId.value = receipt.id;
    });
    search.addEventListener('input', () => search.setCustomValidity(''));

    const initialReceipt = receipts.find((receipt) => String(receipt.id) === receiptId.value);
    if (initialReceipt) showReceipt(initialReceipt);
});

function escapeHtml(value) {
    const element = document.createElement('span');
    element.textContent = String(value ?? '');
    return element.innerHTML;
}

returnButtons.forEach((button) => {
    button.addEventListener('click', () => {
        if (!returnsToast) return;
        returnsToast.hidden = false;
        window.setTimeout(() => {
            returnsToast.hidden = true;
        }, 2200);
    });
});
