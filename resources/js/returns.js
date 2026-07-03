const returnButtons = document.querySelectorAll('[data-return-action], .mini-button');
const returnsToast = document.querySelector('[data-returns-toast]');

returnButtons.forEach((button) => {
    button.addEventListener('click', () => {
        if (!returnsToast) return;
        returnsToast.hidden = false;
        window.setTimeout(() => {
            returnsToast.hidden = true;
        }, 2200);
    });
});
