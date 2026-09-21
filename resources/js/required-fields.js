const requiredControlSelector = 'input:required:not([type="hidden"]), select:required, textarea:required';

function syncRequiredLabel(label) {
    const requiredControl = label.querySelector(requiredControlSelector);
    let marker = label.querySelector('[data-required-marker]');

    if (!requiredControl) {
        marker?.remove();
        return;
    }

    if (marker) return;

    marker = document.createElement('span');
    marker.className = 'required-field-marker';
    marker.dataset.requiredMarker = '';
    marker.setAttribute('aria-hidden', 'true');
    marker.textContent = '*';

    const directCaption = Array.from(label.children).find((child) =>
        child.tagName === 'SPAN' && !child.matches('[data-required-marker]')
    );

    if (directCaption && !directCaption.contains(requiredControl)) {
        directCaption.append(' ', marker);
    } else {
        label.insertBefore(marker, requiredControl);
    }
}

function syncRequiredLabels(root = document) {
    root.querySelectorAll('label').forEach(syncRequiredLabel);
}

syncRequiredLabels();

const requiredFieldsObserver = new MutationObserver((mutations) => {
    const labels = new Set();

    mutations.forEach((mutation) => {
        const target = mutation.target instanceof Element ? mutation.target : mutation.target.parentElement;
        const label = target?.closest('label');
        if (label) labels.add(label);

        mutation.addedNodes.forEach((node) => {
            if (! (node instanceof Element)) return;
            if (node.matches('label')) labels.add(node);
            node.querySelectorAll?.('label').forEach((addedLabel) => labels.add(addedLabel));
        });
    });

    labels.forEach(syncRequiredLabel);
});

requiredFieldsObserver.observe(document.body, {
    subtree: true,
    childList: true,
    attributes: true,
    attributeFilter: ['required'],
});
