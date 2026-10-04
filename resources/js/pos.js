import { checkoutStorage, canDiscardCheckout } from './pending-checkout';

const posApp = document.querySelector('[data-pos-app]');

if (posApp) {
    let products = JSON.parse(posApp.dataset.products || '[]');
    let heldOrders = JSON.parse(posApp.dataset.heldOrders || '[]');
    const grid = posApp.querySelector('[data-product-grid]');
    const searchInput = posApp.querySelector('[data-pos-search]');
    const categoryContainer = posApp.querySelector('.pos-categories');
    let categoryButtons = [...posApp.querySelectorAll('[data-category]')];
    const cartContainer = posApp.querySelector('[data-cart-items]');
    const subtotalNode = posApp.querySelector('[data-subtotal]');
    const laborInput = posApp.querySelector('[data-labor-amount]');
    const laborTotalNode = posApp.querySelector('[data-labor-total]');
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
    const liveInventoryUrl = posApp.dataset.liveInventoryUrl;
    let inventoryVersion = posApp.dataset.inventoryVersion;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const emptyCartMarkup = 'No products selected.<br>Add a product or enter a labor charge.';
    let currentCategory = 'All';
    let cart = [];
    let activeHeldOrderId = null;
    let pendingCheckout = null;
    let checkoutLockedControls = [];
    const savedCheckout = checkoutStorage({
        getItem: (key) => window.localStorage.getItem(key),
        setItem: (key, value) => window.localStorage.setItem(key, value),
        removeItem: (key) => window.localStorage.removeItem(key),
    }, `motosync:checkout:${posApp.dataset.cashierId}:${checkoutUrl}`);
    let recoveryBlocked = false;
    try {
        pendingCheckout = savedCheckout.load();
        if (pendingCheckout) {
            cart = pendingCheckout.cart;
            const order = JSON.parse(pendingCheckout.body);
            laborInput.value = order.labor_amount || '';
            activeHeldOrderId = order.held_order_id;
        }
    } catch {
        recoveryBlocked = true;
    }

    function lockPendingCheckout() {
        if (!pendingCheckout && !recoveryBlocked) return;
        const paymentButton = posApp.querySelector('[data-process-payment]');
        if (!checkoutLockedControls.length) checkoutLockedControls = [...posApp.querySelectorAll('button, input')]
            .filter((control) => control !== paymentButton && !control.disabled);
        checkoutLockedControls.forEach((control) => { control.disabled = true; });
        paymentButton.disabled = recoveryBlocked;
        const notice = posApp.querySelector('[data-pending-checkout]');
        notice.hidden = false;
        notice.textContent = recoveryBlocked
            ? 'Checkout recovery data could not be read. Do not retry as a new sale; ask the owner to check recent receipts first.'
            : 'A payment is unresolved. Retry payment to recover the original receipt; do not create a new sale.';
    }

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
        const labor = Number(receipt.labor) || 0;
        const laborRow = receiptModal.querySelector('[data-receipt-labor-row]');
        laborRow.hidden = labor <= 0;
        receiptModal.querySelector('[data-receipt-labor]').textContent = peso(labor);
        receiptModal.querySelector('[data-receipt-total]').textContent = peso(receipt.total);
        receiptModal.querySelector('[data-receipt-print]').href = receipt.url;

        const items = receiptModal.querySelector('[data-receipt-items]');
        items.innerHTML = '';
        if (!receipt.items.length) {
            const row = document.createElement('div');
            row.className = 'receipt-preview-item labor-only-item';
            const name = document.createElement('span');
            name.textContent = 'Labor / service only';
            const details = document.createElement('small');
            details.textContent = 'No products purchased';
            row.append(name, details);
            items.appendChild(row);
        }
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
        const unitCount = receipt.items.reduce((sum, item) => sum + item.quantity, 0);
        const values = [
            `#${receipt.number}`,
            receipt.date,
            receipt.cashier,
            unitCount > 0 ? `${unitCount} unit${unitCount === 1 ? '' : 's'}` : 'Labor only',
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
        if (pendingCheckout) {
            showToast('Resolve the pending payment by retrying it first.');
            return;
        }
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

        laborInput.value = hold.labor > 0 ? hold.labor.toFixed(2) : '';
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
                laborInput.value = '';
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

    function renderProducts() {
        if (!grid) return;
        const query = (searchInput?.value || '').trim().toLowerCase();
        const filtered = products.filter((product) => {
            const categoryMatch = currentCategory === 'All' || product.categoryKey === currentCategory;
            const queryMatch = !query || `${product.name} ${product.sku || ''} ${product.category} ${product.shelfLocation || ''}`.toLowerCase().includes(query);
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
                stock.textContent = `${product.stock} in stock${product.shelfLocation ? ` · Shelf ${product.shelfLocation}` : ''}`;
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
                    promoBadge.textContent = product.promotion.bundleProduct
                        ? `Free with ${product.promotion.bundleProduct.name}`
                        : `${product.promotion.label} · ${product.promotion.discount}% off`;
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

    function laborAmount() {
        const amount = Number.parseFloat(laborInput?.value || '0');

        return Number.isFinite(amount) && amount > 0 ? Math.round(amount * 100) / 100 : 0;
    }

    function currentSubtotal() {
        return cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    }

    function updateTotals(subtotal) {
        const labor = laborAmount();
        const total = subtotal + labor;
        subtotalNode.textContent = peso(subtotal);
        laborTotalNode.textContent = peso(labor);
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
                    <h4></h4>
                    <p></p>
                </div>
                <div class="item-controls">
                    <button class="qty-btn" type="button" data-action="minus">-</button>
                    <span></span>
                    <button class="qty-btn" type="button" data-action="plus">+</button>
                    <strong class="item-total"></strong>
                </div>
            `;

            // Inventory text is untrusted, including names imported from supplier files.
            row.querySelector('h4').textContent = item.name;
            row.querySelector('.item-details p').textContent = peso(item.price);
            row.querySelector('.item-controls span').textContent = String(item.qty);
            row.querySelector('.item-total').textContent = peso(lineTotal);

            row.querySelector('[data-action="minus"]').addEventListener('click', () => changeQty(item.id, -1));
            row.querySelector('[data-action="plus"]').addEventListener('click', () => changeQty(item.id, 1));
            cartContainer.appendChild(row);
        });

        updateTotals(subtotal);
    }

    function addToCart(product) {
        const companionId = product.promotion?.bundleProduct?.id;
        const companion = companionId ? products.find((entry) => entry.id === companionId) : null;
        const additions = companion ? [companion, product] : [product];

        const unavailable = additions.find((entry) => {
            const existing = cart.find((item) => item.id === entry.id);
            return (existing?.qty || 0) >= entry.stock;
        });
        if (unavailable) {
            showToast(`Only ${unavailable.stock} stock available for ${unavailable.name}.`);
            return;
        }

        additions.forEach((entry) => {
            const existing = cart.find((item) => item.id === entry.id);
            if (existing) existing.qty += 1;
            else cart.push({ ...entry, qty: 1 });
        });

        if (companion) {
            showToast(`${product.name} added free with ${companion.name}.`);
        }
        renderCart();
    }

    function changeQty(id, delta) {
        const item = cart.find((entry) => entry.id === id);
        if (!item) return;

        const companionId = item.promotion?.bundleProduct?.id;
        const companion = companionId ? cart.find((entry) => entry.id === companionId) : null;
        if (delta > 0 && item.qty >= item.stock) {
            showToast(`Only ${item.stock} stock available for ${item.name}.`);
            return;
        }
        if (delta > 0 && companion && companion.qty >= companion.stock) {
            showToast(`Only ${companion.stock} stock available for ${companion.name}.`);
            return;
        }

        if (delta < 0 && !companionId) {
            const requiredByBundles = cart
                .filter((entry) => entry.promotion?.bundleProduct?.id === item.id)
                .reduce((sum, entry) => sum + entry.qty, 0);
            if ((item.qty + delta) < requiredByBundles) {
                showToast(`${item.name} is required by an active promo bundle.`);
                return;
            }
        }

        item.qty += delta;
        if (companion) companion.qty += delta;
        if (item.qty <= 0) {
            cart = cart.filter((entry) => entry.id !== id);
        }
        if (companion && companion.qty <= 0) {
            cart = cart.filter((entry) => entry.id !== companion.id);
        }
        renderCart();
    }

    function bindCategoryButtons() {
        categoryButtons.forEach((button) => {
            button.addEventListener('click', () => {
                currentCategory = button.dataset.category || 'All';
                categoryButtons.forEach((entry) => entry.classList.remove('active'));
                button.classList.add('active');
                renderProducts();
            });
        });
    }

    function renderCategoryButtons() {
        if (!categoryContainer) return;
        const categories = new Map(products.map((product) => [product.categoryKey, product.category]));
        if (currentCategory !== 'All' && !categories.has(currentCategory)) currentCategory = 'All';
        categoryContainer.innerHTML = '';

        [['All', 'All Items'], ...categories.entries()].forEach(([key, label]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `cat-btn${currentCategory === key ? ' active' : ''}`;
            button.dataset.category = key;
            button.textContent = label;
            categoryContainer.appendChild(button);
        });
        categoryButtons = [...categoryContainer.querySelectorAll('[data-category]')];
        bindCategoryButtons();
    }

    async function syncLiveInventory() {
        if (pendingCheckout) return;
        if (!liveInventoryUrl || document.hidden) return;
        try {
            const response = await fetch(liveInventoryUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) return;
            const snapshot = await response.json();
            if (snapshot.version === inventoryVersion) return;

            const freshProducts = Array.isArray(snapshot.products) ? snapshot.products : [];
            let adjusted = false;
            cart = cart.flatMap((item) => {
                const fresh = freshProducts.find((product) => product.id === item.id);
                if (!fresh || fresh.stock < 1) {
                    adjusted = true;
                    return [];
                }
                const quantity = Math.min(item.qty, fresh.stock);
                if (quantity !== item.qty || fresh.price !== item.price) adjusted = true;
                return [{ ...fresh, qty: quantity }];
            });
            products = freshProducts;
            inventoryVersion = snapshot.version;
            renderCategoryButtons();
            renderProducts();
            renderCart();
            if (adjusted) showToast('Live inventory changed; the active order was updated.');
        } catch (error) {
            // Keep checkout usable and retry automatically.
        }
    }

    searchInput?.addEventListener('input', renderProducts);
    laborInput?.addEventListener('input', () => updateTotals(currentSubtotal()));
    laborInput?.addEventListener('blur', () => {
        laborInput.value = laborAmount() > 0 ? laborAmount().toFixed(2) : '';
        updateTotals(currentSubtotal());
    });
    posApp.querySelector('[data-clear-cart]')?.addEventListener('click', () => {
        cart = [];
        laborInput.value = '';
        renderCart();
        showToast('Order cleared in UI preview.');
    });
    holdButton?.addEventListener('click', async () => {
        if (activeHeldOrderId) {
            cart = [];
            laborInput.value = '';
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
                    labor_amount: laborAmount(),
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
            laborInput.value = '';
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
          if (recoveryBlocked) return;
          if (!pendingCheckout && !cart.length && laborAmount() <= 0) {
            showToast('Add a product or enter a labor charge before checkout.');
            return;
        }
        const button = event.currentTarget;
        button.disabled = true;
        button.classList.add('is-loading');
        try {
        if (!pendingCheckout && savedCheckout.load()) {
            recoveryBlocked = true;
            throw new Error('Another tab has a pending checkout');
        }
        // Keep the exact request after an uncertain response, even if the UI changes.
        pendingCheckout ??= {
            key: crypto.randomUUID(),
            cart: cart.map((item) => ({ ...item })),
            body: JSON.stringify({
                payment_method: 'cash',
                held_order_id: activeHeldOrderId,
                labor_amount: laborAmount(),
                items: cart.map((item) => ({ product_id: item.id, quantity: item.qty })),
            }),
        };
        // Fail closed if durable recovery storage is unavailable: do not send a sale.
        savedCheckout.save(pendingCheckout);
        if (!checkoutLockedControls.length) {
            checkoutLockedControls = [...posApp.querySelectorAll('button, input')]
                .filter((control) => control !== button && !control.disabled);
            checkoutLockedControls.forEach((control) => { control.disabled = true; });
        }
            const response = await fetch(checkoutUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Idempotency-Key': pendingCheckout.key,
                },
                body: pendingCheckout.body,
            });
            const payload = await response.json();
            if (!response.ok) {
                const error = payload.message || Object.values(payload.errors || {})?.flat()?.[0] || 'Checkout failed.';
                showToast(error);
                if (canDiscardCheckout(response.status)) {
                    savedCheckout.clear(pendingCheckout);
                    pendingCheckout = null;
                }
                return;
            }
            if (!payload.sale_id || !payload.receipt?.number || !Array.isArray(payload.receipt.items)) {
                throw new Error('Invalid checkout response');
            }
            savedCheckout.clear(pendingCheckout);
            pendingCheckout = null;
            inventoryVersion = null;
            cart = [];
            laborInput.value = '';
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
            syncLiveInventory();
        } catch (error) {
            showToast('Payment status is uncertain. Click payment again to safely retry the same order.');
        } finally {
            if (!pendingCheckout) {
                checkoutLockedControls.forEach((control) => { control.disabled = false; });
                checkoutLockedControls = [];
            }
            button.disabled = false;
            button.classList.remove('is-loading');
            if (pendingCheckout || recoveryBlocked) lockPendingCheckout();
            else posApp.querySelector('[data-pending-checkout]').hidden = true;
        }
    });

    bindCategoryButtons();
    renderProducts();
    renderCart();
    renderHeldOrders();
    updateActiveHold();
    lockPendingCheckout();
    window.setInterval(syncLiveInventory, 5000);
    window.setTimeout(syncLiveInventory, 800);
}
