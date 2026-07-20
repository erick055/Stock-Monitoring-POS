const filterButtons = document.querySelectorAll('[data-result-filter]');
const resultCards = document.querySelectorAll('[data-result-status]');
const filterEmpty = document.querySelector('[data-filter-empty]');

filterButtons.forEach((button) => {
    button.addEventListener('click', () => {
        const filter = button.dataset.resultFilter;
        let visible = 0;

        filterButtons.forEach((item) => item.classList.toggle('active', item === button));
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

const motorcycleProfile = document.querySelector('[data-motorcycle-profile]');

motorcycleProfile?.addEventListener('change', () => {
    const option = motorcycleProfile.selectedOptions[0];

    ['brand', 'model', 'year', 'engine', 'variant'].forEach((field) => {
        const input = document.querySelector(`[name="${field}"]`);
        if (input) input.value = option?.dataset[field] || '';
    });
});
