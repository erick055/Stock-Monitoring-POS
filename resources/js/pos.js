const posApp = document.querySelector('[data-pos-app]');

if (posApp) {
    const products = JSON.parse(posApp.dataset.products || '[]');
    let heldOrders = JSON.parse(posApp.dataset.heldOrders || '[]');
    const grid = posApp.querySelector('[data-product-grid]');
    const searchInput = posApp.querySelector('[data-pos-search]');
    const categoryButtons = [...posApp.querySelectorAll('[data-category]')];
    const cartContainer = posApp.querySelector('[data-cart-items]');
    const subtotalNode = posApp.querySelector('[data-subtotal]');
    const taxNode = posApp.querySelector('[data-tax]');
    const totalNode = posApp.querySelector('[data-total]');
    const payTotalNode = posApp.querySelector('[data-pay-total]');
    const toast = document.querySelector('[data-pos-toast]');
    const receiptModal = document.querySelector('[data-receipt-modal]');
    const checkoutLogs = document.querySelector('[data-checkout-logs]');
    const heldOrderList = document.querySelector('[data-held-order-list]');
    const heldCount = document.querySelector('[data-held-count]');
    const activeHoldBadge = document.querySelector('[data-active-hold]');
    const holdButton = posApp.querySelector('[data-hold-order]');
    const checkoutUrl = posApp.dataset.checkoutUrl;
    const holdUrl = posApp.dataset.holdUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const emptyCartMarkup = 'Cart is empty.<br>Select items to begin.';
    let currentCategory = 'All';
    let cart = [];
    let activeHeldOrderId = null;

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

    function closeReceipt() {
        if (!receiptModal) return;
        receiptModal.hidden = true;
        document.body.classList.remove('receipt-open');
    }

    function showReceipt(receipt) {
        if (!receiptModal || !receipt) return;

        receiptModal.querySelector('[data-receipt-number]').textContent = `#${receipt.number}`;
        receiptModal.querySelector('[data-receipt-date]').textContent = receipt.date;
        receiptModal.querySelector('[data-receipt-cashier]').textContent = `Cashier: ${receipt.cashier}`;
        receiptModal.querySelector('[data-receipt-subtotal]').textContent = peso(receipt.subtotal);
        receiptModal.querySelector('[data-receipt-tax]').textContent = peso(receipt.tax);
        receiptModal.querySelector('[data-receipt-total]').textContent = peso(receipt.total);
        receiptModal.querySelector('[data-receipt-print]').href = receipt.url;

        const items = receiptModal.querySelector('[data-receipt-items]');
        items.innerHTML = '';
        receipt.items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'receipt-preview-item';

            const name = document.createElement('span');
            name.textContent = item.name;
            const details = document.createElement('small');
            details.textContent = `${item.quantity} x ${peso(item.unit_price)} | ${item.sku}`;
            const total = document.createElement('strong');
            total.textContent = peso(item.line_total);
            row.append(name, details, total);
            items.appendChild(row);
        });

        receiptModal.hidden = false;
        document.body.classList.add('receipt-open');
        receiptModal.querySelector('[data-receipt-print]').focus();
    }

    function prependCheckoutLog(receipt) {
        if (!checkoutLogs || !receipt) return;
        checkoutLogs.querySelector('[data-empty-log]')?.remove();

        const row = document.createElement('tr');
        const values = [
            `#${receipt.number}`,
            receipt.date,
            receipt.cashier,
            `${receipt.items.reduce((sum, item) => sum + item.quantity, 0)} units`,
            receipt.payment_method,
            peso(receipt.total),
        ];

        values.forEach((value, index) => {
            const cell = document.createElement('td');
            if (index === 0 || index === 5) {
                const strong = document.createElement('strong');
                strong.textContent = value;
                cell.appendChild(strong);
            } else if (index === 4) {
                const pill = document.createElement('span');
                pill.className = 'payment-pill';
                pill.textContent = value;
                cell.appendChild(pill);
            } else {
                cell.textContent = value;
            }
            row.appendChild(cell);
        });

        const documentCell = document.createElement('td');
        const link = document.createElement('a');
        link.className = 'receipt-link';
        link.href = receipt.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = 'View receipt';
        documentCell.appendChild(link);
        row.appendChild(documentCell);
        checkoutLogs.prepend(row);
    }

    function updateActiveHold() {
        const activeHold = heldOrders.find((hold) => hold.id === activeHeldOrderId);
        activeHoldBadge.hidden = !activeHold;
        activeHoldBadge.textContent = activeHold ? `Resumed: ${activeHold.number}` : '';
        holdButton.textContent = activeHold ? 'Return to Hold' : 'Hold Order';
    }

    function resumeHeldOrder(hold) {
        const unavailable = [];
        cart = hold.items.flatMap((item) => {
            const product = products.find((entry) => entry.id === item.product_id);
            if (!product || product.stock < 1) {
                unavailable.push(item.name);
                return [];
            }

            if (product.stock < item.quantity) unavailable.push(item.name);
            return [{ ...product, qty: Math.min(item.quantity, product.stock) }];
        });

        activeHeldOrderId = hold.id;
        renderCart();
        renderHeldOrders();
        updateActiveHold();
        showToast(unavailable.length
            ? `${hold.number} resumed; unavailable quantities were adjusted.`
            : `${hold.number} resumed.`);
        posApp.querySelector('.pos-cart')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function cancelHeldOrder(hold) {
        try {
            const response = await fetch(hold.cancel_url, {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            });
            const payload = await response.json();
            if (!response.ok) {
                showToast(payload.message || 'Could not cancel this held order.');
                return;
            }

            heldOrders = heldOrders.filter((entry) => entry.id !== hold.id);
            if (activeHeldOrderId === hold.id) {
                activeHeldOrderId = null;
                cart = [];
                renderCart();
            }
            renderHeldOrders();
            updateActiveHold();
            showToast(payload.message);
        } catch (error) {
            showToast('Could not connect to the held order service.');
        }
    }

    function renderHeldOrders() {
        if (!heldOrderList) return;
        heldOrderList.innerHTML = '';
        heldCount.textContent = `${heldOrders.length} active`;

        if (!heldOrders.length) {
            const empty = document.createElement('div');
            empty.className = 'empty-holds';
            empty.textContent = 'No active held orders.';
            heldOrderList.appendChild(empty);
            return;
        }

        heldOrders.forEach((hold) => {
            const card = document.createElement('article');
            card.className = `held-order-card${hold.id === activeHeldOrderId ? ' active' : ''}`;

            const head = document.createElement('div');
            head.className = 'held-order-head';
            const number = document.createElement('strong');
            number.textContent = hold.number;
            const total = document.createElement('strong');
            total.textContent = peso(hold.total);
            head.append(number, total);

            const meta = document.createElement('p');
            meta.textContent = `${hold.items.reduce((sum, item) => sum + item.quantity, 0)} units | ${hold.cashier} | ${hold.date}`;
            const names = document.createElement('small');
            names.textContent = hold.items.map((item) => `${item.quantity}x ${item.name}`).join(', ');

            const actions = document.createElement('div');
            actions.className = 'held-order-actions';
            const resume = document.createElement('button');
            resume.type = 'button';
            resume.className = 'resume-hold';
            resume.textContent = hold.id === activeHeldOrderId ? 'Loaded in cart' : 'Resume';
            resume.disabled = hold.id === activeHeldOrderId;
            resume.addEventListener('click', () => resumeHeldOrder(hold));
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'cancel-hold';
            cancel.textContent = 'Cancel hold';
            cancel.addEventListener('click', () => cancelHeldOrder(hold));
            actions.append(resume, cancel);

            card.append(head, meta, names, actions);
            heldOrderList.appendChild(card);
        });
    }

    function renderFlatProducts() {
        if (!grid) return;
        const query = (searchInput?.value || '').trim().toLowerCase();
        const filtered = products.filter((product) => {
            const categoryMatch = currentCategory === 'All' || product.categoryKey === currentCategory;
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
                <div class="product-price">${product.promotion ? `<small class="regular-price">${peso(product.basePrice)}</small><span>${peso(product.price)}</span><em>${product.promotion.label} · ${product.promotion.discount}% off</em>` : peso(product.price)}</div>
            `;
            card.addEventListener('click', () => addToCart(product));
            grid.appendChild(card);
        });
    }

    function renderProducts() {
        if (!grid) return;
        const query = (searchInput?.value || '').trim().toLowerCase();
        const filtered = products.filter((product) => {
            const categoryMatch = currentCategory === 'All' || product.categoryKey === currentCategory;
            const queryMatch = !query || `${product.name} ${product.sku || ''} ${product.category}`.toLowerCase().includes(query);
            return categoryMatch && queryMatch;
        });

        grid.innerHTML = '';

        if (!filtered.length) {
            const empty = document.createElement('div');
            empty.className = 'empty-products';
            empty.textContent = 'No in-stock products match this search.';
            grid.appendChild(empty);
            return;
        }

        const categoryGroups = new Map();
        filtered.forEach((product) => {
            if (!categoryGroups.has(product.categoryKey)) {
                categoryGroups.set(product.categoryKey, { label: product.category, products: [] });
            }
            categoryGroups.get(product.categoryKey).products.push(product);
        });

        categoryGroups.forEach((group) => {
            const column = document.createElement('section');
            column.className = 'category-column';

            const heading = document.createElement('div');
            heading.className = 'category-column-head';
            const title = document.createElement('h3');
            title.textContent = group.label;
            const count = document.createElement('span');
            count.textContent = `${group.products.length} item${group.products.length === 1 ? '' : 's'}`;
            heading.append(title, count);

            const productList = document.createElement('div');
            productList.className = 'category-products';

            group.products.forEach((product) => {
                const card = document.createElement('article');
                card.className = 'product-card';
                card.tabIndex = 0;
                card.setAttribute('role', 'button');

                const meta = document.createElement('div');
                meta.className = 'product-meta';
                const stock = document.createElement('small');
                stock.textContent = `${product.stock} in stock`;
                const productTitle = document.createElement('div');
                productTitle.className = 'product-title';
                productTitle.textContent = product.name;
                meta.append(stock, productTitle);

                const price = document.createElement('div');
                price.className = 'product-price';
                if (product.promotion) {
                    const regularPrice = document.createElement('small');
                    regularPrice.className = 'regular-price';
                    regularPrice.textContent = peso(product.basePrice);
                    const promoPrice = document.createElement('span');
                    promoPrice.textContent = peso(product.price);
                    const promoBadge = document.createElement('em');
                    promoBadge.textContent = `${product.promotion.label} · ${product.promotion.discount}% off`;
                    price.append(regularPrice, promoPrice, promoBadge);
                } else {
                    price.textContent = peso(product.price);
                }
                card.append(meta, price);
                card.addEventListener('click', () => addToCart(product));
                card.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        addToCart(product);
                    }
                });
                productList.appendChild(card);
            });

            column.append(heading, productList);
            grid.appendChild(column);
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
    holdButton?.addEventListener('click', async () => {
        if (activeHeldOrderId) {
            cart = [];
            activeHeldOrderId = null;
            renderCart();
            renderHeldOrders();
            updateActiveHold();
            showToast('Order returned to the held list.');
            return;
        }
        if (!cart.length) {
            showToast('Add items before holding an order.');
            return;
        }

        holdButton.disabled = true;
        try {
            const response = await fetch(holdUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    items: cart.map((item) => ({ product_id: item.id, quantity: item.qty })),
                }),
            });
            const payload = await response.json();
            if (!response.ok) {
                showToast(payload.message || Object.values(payload.errors || {})?.flat()?.[0] || 'Could not hold this order.');
                return;
            }
            heldOrders.unshift(payload.hold);
            cart = [];
            renderCart();
            renderHeldOrders();
            showToast(payload.message);
        } catch (error) {
            showToast('Could not connect to the held order service.');
        } finally {
            holdButton.disabled = false;
        }
    });
    document.querySelectorAll('[data-close-receipt]').forEach((button) => button.addEventListener('click', closeReceipt));
    receiptModal?.addEventListener('click', (event) => {
        if (event.target === receiptModal) closeReceipt();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && receiptModal && !receiptModal.hidden) closeReceipt();
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
                    held_order_id: activeHeldOrderId,
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
            if (activeHeldOrderId) {
                heldOrders = heldOrders.filter((hold) => hold.id !== activeHeldOrderId);
                activeHeldOrderId = null;
                renderHeldOrders();
                updateActiveHold();
            }
            renderCart();
            prependCheckoutLog(payload.receipt);
            showReceipt(payload.receipt);
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
    renderHeldOrders();
    updateActiveHold();
}
