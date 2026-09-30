import './inventory-live-reload';

const filterForm = document.querySelector('[data-products-filter]');

document.querySelectorAll('[data-auto-submit]').forEach((select) => select.addEventListener('change', () => filterForm?.submit()));

if (typeof HTMLElement.prototype.showPopover !== 'function') {
    document.documentElement.classList.add('no-popover-support');

    document.querySelectorAll('[popovertarget]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            const popup = document.getElementById(button.getAttribute('popovertarget'));
            if (!popup) return;

            if (button.getAttribute('popovertargetaction') === 'hide') {
                popup.removeAttribute('data-fallback-open');
                return;
            }

            document.querySelectorAll('.product-details[data-fallback-open]').forEach((openPopup) => openPopup.removeAttribute('data-fallback-open'));
            popup.setAttribute('data-fallback-open', '');
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.product-details[data-fallback-open]').forEach((popup) => popup.removeAttribute('data-fallback-open'));
        }
    });
}
