import '../css/login-stock-alert.css';

const stockAlert = document.querySelector('[data-login-stock-alert]');
if (stockAlert) {
    stockAlert.showModal();
    stockAlert.addEventListener('click', (event) => {
        if (event.target !== stockAlert) return;
        const bounds = stockAlert.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom) stockAlert.close();
    });
}
