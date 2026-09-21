const filterButtons = document.querySelectorAll('[data-result-filter]');
const resultCards = document.querySelectorAll('[data-result-status]');
const filterEmpty = document.querySelector('[data-filter-empty]');

filterButtons.forEach((button) => {
    button.setAttribute('aria-pressed', String(button.classList.contains('active')));
    button.addEventListener('click', () => {
        const filter = button.dataset.resultFilter;
        let visible = 0;

        filterButtons.forEach((item) => {
            item.classList.toggle('active', item === button);
            item.setAttribute('aria-pressed', String(item === button));
        });
        resultCards.forEach((card) => {
            const show = filter === 'all'
                || (filter === 'recommended' && card.dataset.recommended === 'true')
                || card.dataset.resultStatus === filter;
            card.hidden = !show;
            if (show) visible += 1;
        });

        if (filterEmpty) filterEmpty.hidden = visible !== 0;
    });
});

const checkerForm = document.querySelector('.checker-form');
const submitButton = checkerForm?.querySelector('[type="submit"]');
const searchProgress = document.querySelector('[data-search-progress]');
const submitLabel = submitButton?.textContent;

checkerForm?.addEventListener('submit', (event) => {
    if (checkerForm.getAttribute('aria-busy') === 'true') {
        event.preventDefault();
        return;
    }
    checkerForm.setAttribute('aria-busy', 'true');
    submitButton.disabled = true;
    submitButton.textContent = 'Assessing compatibility…';
    if (searchProgress) searchProgress.hidden = false;
});

window.addEventListener('pageshow', () => {
    checkerForm?.removeAttribute('aria-busy');
    if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = submitLabel;
    }
    if (searchProgress) searchProgress.hidden = true;
});
