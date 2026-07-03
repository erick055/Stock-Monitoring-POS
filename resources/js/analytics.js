const periodSelect = document.querySelector('[data-period-select]');
const statValues = {
    Today: ['P8,420', '42', 'P201', '31%'],
    'This Week': ['P45,320', '320', 'P320', '35%'],
    'This Month': ['P182,640', '1,208', 'P357', '38%'],
};

periodSelect?.addEventListener('change', () => {
    const values = statValues[periodSelect.value];
    if (!values) return;

    const cards = document.querySelectorAll('.analytics-stats .stat-card strong');
    cards.forEach((card, index) => {
        card.textContent = values[index];
    });
});
