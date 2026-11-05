import { money } from './formatters.js';
export function paymentState(method, tendered, reference, total) {
    const totalCents = Math.round(total * 100);
    const cash = method === 'cash';
    const validAmount = /^\d+(\.\d{1,2})?$/.test(String(tendered));
    const tenderedCents = validAmount ? Math.round(Number(tendered) * 100) : 0;
    return {
        cash,
        valid: cash
            ? validAmount && tenderedCents >= totalCents && tenderedCents <= 999999999999
            : ['gcash', 'maya', 'card'].includes(method) && reference.trim().length > 0 && reference.trim().length <= 255,
        change: (tenderedCents - totalCents) / 100,
    };
}

export function createPaymentDialog(dialog, { getTotal, onConfirm }) {
    const form = dialog.querySelector('form');
    const method = dialog.querySelector('[data-payment-method]');
    const tendered = dialog.querySelector('[data-payment-tendered]');
    const reference = dialog.querySelector('[data-payment-reference]');
    const confirm = dialog.querySelector('[data-confirm-payment]');
    const cancel = dialog.querySelector('[data-cancel-payment]');
    let pending = null;
    let submitting = false;
    let error = '';

    function update() {
        const total = pending ? Number(pending.payment_total ?? getTotal()) : getTotal();
        const state = paymentState(method.value, tendered.value, reference.value, total);
        dialog.querySelector('[data-payment-total]').textContent = money.format(total);
        dialog.querySelector('[data-cash-fields]').hidden = !state.cash;
        dialog.querySelector('[data-reference-fields]').hidden = state.cash;
        dialog.querySelector('[data-payment-change]').textContent = money.format(Math.max(0, state.change));
        dialog.querySelector('[data-payment-hint]').textContent = error || (pending
            ? 'Retry the original payment to recover its receipt. Payment details are locked.'
            : state.cash && !state.valid ? 'Enter enough cash to cover the total.' : !state.cash ? 'Verify the payment and enter its reference number.' : 'Review the payment, then confirm to complete this sale.');
        for (const field of [method, tendered, reference]) field.disabled = submitting || !!pending;
        cancel.disabled = submitting;
        confirm.disabled = submitting || (!pending && !state.valid);
        confirm.textContent = submitting ? 'Processing…' : pending ? 'Retry payment' : 'Confirm payment';
    }

    function setPending(order) {
        pending = order;
        if (order) {
            method.value = order.payment_method;
            tendered.value = order.amount_tendered ?? '';
            reference.value = order.payment_reference ?? '';
        }
        update();
    }

    function close() {
        if (submitting) return;
        dialog.close();
    }

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting || confirm.disabled) return;
        const details = {
            payment_method: method.value,
            amount_tendered: method.value === 'cash' ? Number(tendered.value) : null,
            payment_reference: method.value === 'cash' ? null : reference.value.trim(),
        };
        error = '';
        submitting = true;
        update();
        try {
            const completed = await onConfirm(details);
            if (completed) dialog.close();
        } finally {
            submitting = false;
            update();
        }
    });
    cancel.addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => { if (submitting) event.preventDefault(); });
    dialog.addEventListener('close', () => {
        document.body.classList.remove('modal-open');
        if (document.querySelector('[data-receipt-modal]')?.hidden !== false) document.querySelector('[data-process-payment]')?.focus();
    });

    return {
        setPending,
        setError(message) { error = message; update(); },
        open(order = null) {
            if (dialog.open || submitting) return;
            error = '';
            method.value = 'cash';
            tendered.value = '';
            reference.value = '';
            setPending(order);
            dialog.showModal();
            document.body.classList.add('modal-open');
            (order ? confirm : tendered).focus();
        },
    };
}
