const posApp = document.querySelector('[data-pos-app]');

if (posApp) {
    const products = JSON.parse(posApp.dataset.products || '[]');
    const grid = posApp.querySelector('[data-product-grid]');
    const searchInput = posApp.querySelector('[data-pos-search]');
    const categoryButtons = [...posApp.querySelectorAll('[data-category]')];
    const cartContainer = posApp.querySelector('[data-cart-items]');
    const subtotalNode = posApp.querySelector('[data-subtotal]');
    const taxNode = posApp.querySelector('[data-tax]');
    const totalNode = posApp.querySelector('[data-total]');
    const payTotalNode = posApp.querySelector('[data-pay-total]');
    const toast = document.querySelector('[data-pos-toast]');
    const checkoutUrl = posApp.dataset.checkoutUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const emptyCartMarkup = 'Cart is empty.<br>Select items to begin.';
    let currentCategory = 'All';
    let cart = [];

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.hidden = false;
        window.setTimeout(() => {
            toast.hidden = true;
        }, 2200);
    }

    function peso(amount) {
        return `P${amount.toFixed(2)}`;
    }

    function renderProducts() {
        if (!grid) return;
        const query = (searchInput?.value || '').trim().toLowerCase();
        const filtered = products.filter((product) => {
            const categoryMatch = currentCategory === 'All' || product.category === currentCategory;
            const queryMatch = !query || `${product.name} ${product.sku || ''} ${product.category}`.toLowerCase().includes(query);
            return categoryMatch && queryMatch;
        });

        grid.innerHTML = '';

        filtered.forEach((product) => {
            const card = document.createElement('article');
            card.className = 'product-card';
            card.innerHTML = `
                <div class="product-meta">
                    <small>${product.category} · ${product.stock} in stock</small>
                    <div class="product-title">${product.name}</div>
                </div>
                <div class="product-price">${peso(product.price)}</div>
            `;
            card.addEventListener('click', () => addToCart(product));
            grid.appendChild(card);
        });
    }

    function updateTotals(subtotal) {
        const tax = subtotal * 0.12;
        const total = subtotal + tax;
        subtotalNode.textContent = peso(subtotal);
        taxNode.textContent = peso(tax);
        totalNode.textContent = peso(total);
        payTotalNode.textContent = peso(total);
    }

    function renderCart() {
        if (!cartContainer) return;

        if (!cart.length) {
            cartContainer.innerHTML = `<div class="empty-cart">${emptyCartMarkup}</div>`;
            updateTotals(0);
            return;
        }

        cartContainer.innerHTML = '';
        let subtotal = 0;

        cart.forEach((item) => {
            const lineTotal = item.price * item.qty;
            subtotal += lineTotal;

            const row = document.createElement('div');
            row.className = 'cart-item';
            row.innerHTML = `
                <div class="item-details">
                    <h4>${item.name}</h4>
                    <p>${peso(item.price)}</p>
                </div>
                <div class="item-controls">
                    <button class="qty-btn" type="button" data-action="minus">-</button>
                    <span>${item.qty}</span>
                    <button class="qty-btn" type="button" data-action="plus">+</button>
                    <strong class="item-total">${peso(lineTotal)}</strong>
                </div>
            `;

            row.querySelector('[data-action="minus"]').addEventListener('click', () => changeQty(item.id, -1));
            row.querySelector('[data-action="plus"]').addEventListener('click', () => changeQty(item.id, 1));
            cartContainer.appendChild(row);
        });

        updateTotals(subtotal);
    }

    function addToCart(product) {
        const existing = cart.find((item) => item.id === product.id);
        if (existing) {
            if (existing.qty >= product.stock) {
                showToast(`Only ${product.stock} stock available for ${product.name}.`);
                return;
            }
            existing.qty += 1;
        } else {
            cart.push({ ...product, qty: 1 });
        }
        renderCart();
    }

    function changeQty(id, delta) {
        const item = cart.find((entry) => entry.id === id);
        if (!item) return;
        if (delta > 0 && item.qty >= item.stock) {
            showToast(`Only ${item.stock} stock available for ${item.name}.`);
            return;
        }
        item.qty += delta;
        if (item.qty <= 0) {
            cart = cart.filter((entry) => entry.id !== id);
        }
        renderCart();
    }

    categoryButtons.forEach((button) => {
        button.addEventListener('click', () => {
            currentCategory = button.dataset.category || 'All';
            categoryButtons.forEach((entry) => entry.classList.remove('active'));
            button.classList.add('active');
            renderProducts();
        });
    });

    searchInput?.addEventListener('input', renderProducts);
    posApp.querySelector('[data-clear-cart]')?.addEventListener('click', () => {
        cart = [];
        renderCart();
        showToast('Order cleared in UI preview.');
    });
    posApp.querySelector('[data-hold-order]')?.addEventListener('click', () => {
        showToast('Order placed on hold in UI preview.');
    });
    posApp.querySelector('[data-process-payment]')?.addEventListener('click', async (event) => {
        if (!cart.length) {
            showToast('Cart is empty.');
            return;
        }
        const button = event.currentTarget;
        button.disabled = true;
        button.classList.add('is-loading');
        try {
            const response = await fetch(checkoutUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    payment_method: 'cash',
                    items: cart.map((item) => ({ product_id: item.id, quantity: item.qty })),
                }),
            });
            const payload = await response.json();
            if (!response.ok) {
                const error = payload.message || Object.values(payload.errors || {})?.flat()?.[0] || 'Checkout failed.';
                showToast(error);
                return;
            }
            cart.forEach((item) => {
                const product = products.find((entry) => entry.id === item.id);
                if (product) product.stock -= item.qty;
            });
            renderProducts();
            cart = [];
            renderCart();
            showToast(payload.message || 'Payment processed and saved.');
        } catch (error) {
            showToast('Could not connect to checkout backend.');
        } finally {
            button.disabled = false;
            button.classList.remove('is-loading');
        }
    });

    renderProducts();
    renderCart();
}
