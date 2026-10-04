export function checkoutStorage(storage, key) {
    return {
        load() {
            const raw = storage.getItem(key);
            if (!raw) return null;
            const pending = JSON.parse(raw);
            if (!pending || typeof pending.key !== 'string' || typeof pending.body !== 'string'
                || !Array.isArray(pending.cart) || !/^[0-9a-f-]{36}$/i.test(pending.key)) {
                throw new Error('Invalid pending checkout record');
            }
            JSON.parse(pending.body);
            return pending;
        },
        save(pending) {
            storage.setItem(key, JSON.stringify(pending));
        },
        clear(pending) {
            // Never erase another tab's newer pending request.
            if (this.load()?.key === pending.key) storage.removeItem(key);
        },
    };
}

// Only a validation rejection is known not to have completed checkout.
export function canDiscardCheckout(status) {
    return status === 422;
}
