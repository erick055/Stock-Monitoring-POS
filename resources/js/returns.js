import { currency, plural } from './formatters';
const returnButtons = document.querySelectorAll('[data-return-action], .mini-button');
const returnsToast = document.querySelector('[data-returns-toast]');
const receiptDataElement = document.querySelector('#receipt-selector-data');

const openReturnsDialog = (dialog) => {
    if (!dialog || dialog.open) return;
    dialog.showModal();
    document.body.classList.add('modal-open');
};

document.querySelectorAll('[data-open-returns-dialog]').forEach((button) => {
    button.addEventListener('click', () => openReturnsDialog(document.getElementById(button.dataset.openReturnsDialog)));
});
document.querySelectorAll('[data-returns-dialog]').forEach((dialog) => {
    dialog.querySelectorAll('[data-close-returns-dialog]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('close', () => {
        if (!document.querySelector('dialog[open]')) document.body.classList.remove('modal-open');
    });
});

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
        quantity.setAttribute('aria-description', `Maximum ${plural(available, 'item')} from this receipt.`);

        if (refundValue) {
            refundValue.innerHTML = `
                <span>Selected item value</span>
                <strong>${currency(unitPrice)} each</strong>
                <small>${selectedQuantity} × ${currency(unitPrice)} = <b>${currency(maximumRefund)}</b> maximum refundable value</small>
            `;
            refundValue.hidden = false;
        }

        if (refund) {
            refund.max = maximumRefund.toFixed(2);
            refund.placeholder = `Up to ${currency(maximumRefund)}`;
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
                `${item.sku} — ${item.name} — ${currency(Number(item.unit_price))} each (available ${item.available_quantity})`,
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
                <div class="receipt-item-value"><strong>${currency(Number(item.unit_price))}</strong><small>each · ${currency(Number(item.available_value))} available value</small></div>
            </div>
        `).join('');
        preview.innerHTML = `
            <div class="receipt-preview-header">
                <strong>${escapeHtml(receipt.number)}</strong>
                <span>${escapeHtml(receipt.date)}</span>
                <span>Cashier: ${escapeHtml(receipt.cashier)}</span>
                <span>Receipt total: ${currency(receipt.total)}</span>
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

openReturnsDialog(document.querySelector('[data-returns-dialog][data-open-on-error="true"]'));

returnButtons.forEach((button) => {
    button.addEventListener('click', () => {
        if (!returnsToast) return;
        returnsToast.hidden = false;
        window.setTimeout(() => {
            returnsToast.hidden = true;
        }, 2200);
    });
});
