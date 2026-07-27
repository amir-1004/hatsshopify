// Dashboard interactivity: hat CRUD, AI description generation, AI insights.
// Vanilla JS + fetch — no framework, kept deliberately simple.

document.addEventListener('DOMContentLoaded', () => {
    initInsightsPanel();
    initHatModal();
});

/**
 * "Generate insights" button — fetches the AI/fallback order summary.
 */
function initInsightsPanel() {
    const button = document.getElementById('generate-insights-btn');
    const spinner = document.getElementById('insights-spinner');
    const result = document.getElementById('insights-result');
    const placeholder = document.getElementById('insights-placeholder');
    const badge = document.getElementById('insights-badge');
    const summary = document.getElementById('insights-summary');

    if (!button) return;

    button.addEventListener('click', async () => {
        button.disabled = true;
        spinner.classList.remove('hidden');
        spinner.classList.add('flex');
        result.classList.add('hidden');
        placeholder.classList.add('hidden');

        try {
            const response = await fetch('/api/orders/insights');
            const data = await response.json();

            summary.textContent = data.summary ?? 'No insights available.';

            if (data.ai) {
                badge.textContent = 'AI';
                badge.className = 'badge badge-success';
            } else {
                badge.textContent = 'fallback';
                badge.className = 'badge badge-warning';
            }

            result.classList.remove('hidden');
        } catch (error) {
            summary.textContent = 'Could not load insights right now.';
            badge.textContent = 'error';
            badge.className = 'badge badge-error';
            result.classList.remove('hidden');
        } finally {
            button.disabled = false;
            spinner.classList.add('hidden');
            spinner.classList.remove('flex');
        }
    });
}

/**
 * Hat create/edit modal + delete buttons + AI description generation.
 */
function initHatModal() {
    const modal = document.getElementById('hat-modal');
    if (!modal) return;

    const modalTitle = document.getElementById('hat-modal-title');
    const form = document.getElementById('hat-form');
    const idField = document.getElementById('hat-id');
    const nameField = document.getElementById('hat-name');
    const colorPicker = document.getElementById('hat-color-picker');
    const colorNameField = document.getElementById('hat-color-name');
    const styleField = document.getElementById('hat-style');
    const sizeField = document.getElementById('hat-size');
    const priceField = document.getElementById('hat-price');
    const descriptionField = document.getElementById('hat-description');
    const formError = document.getElementById('hat-form-error');
    const aiNote = document.getElementById('ai-unavailable-note');
    const submitButton = document.getElementById('hat-form-submit');
    const generateButton = document.getElementById('generate-description-btn');

    function resetForm() {
        form.reset();
        idField.value = '';
        formError.classList.add('hidden');
        formError.textContent = '';
        aiNote.classList.add('hidden');
    }

    function openForCreate() {
        resetForm();
        modalTitle.textContent = 'Add hat';
        modal.showModal();
    }

    function openForEdit(button) {
        resetForm();
        modalTitle.textContent = 'Edit hat';
        idField.value = button.dataset.id;
        nameField.value = button.dataset.name ?? '';
        colorNameField.value = button.dataset.color ?? '';
        styleField.value = button.dataset.style ?? 'Baseball';
        sizeField.value = button.dataset.size ?? 'Universal';
        priceField.value = button.dataset.price ?? '';
        descriptionField.value = button.dataset.description ?? '';
        modal.showModal();
    }

    document.getElementById('add-hat-btn')?.addEventListener('click', openForCreate);
    document.getElementById('hat-modal-cancel')?.addEventListener('click', () => modal.close());

    document.querySelectorAll('.edit-hat-btn').forEach((button) => {
        button.addEventListener('click', () => openForEdit(button));
    });

    document.querySelectorAll('.delete-hat-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm('Delete this hat? This cannot be undone.')) return;

            const response = await fetch(`/api/hats/${button.dataset.id}`, {
                method: 'DELETE',
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                location.reload();
            } else {
                alert('Could not delete this hat.');
            }
        });
    });

    // Keep the visible color-name text field in sync with the color picker,
    // while still allowing the merchant to type a plain color name by hand.
    colorPicker?.addEventListener('input', () => {
        colorNameField.value = colorPicker.value;
    });

    generateButton?.addEventListener('click', async () => {
        const name = nameField.value.trim();
        const color = colorNameField.value.trim();
        const style = styleField.value;

        if (!name || !color || !style) {
            aiNote.textContent = 'Fill in name, color, and style first.';
            aiNote.classList.remove('hidden');
            return;
        }

        aiNote.classList.add('hidden');
        generateButton.disabled = true;
        const originalLabel = generateButton.textContent;
        generateButton.innerHTML = '<span class="loading loading-spinner loading-xs"></span> Generating…';

        try {
            const response = await fetch('/api/hats/generate-description', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ name, color, style }),
            });

            const data = await response.json();

            if (data.description) {
                descriptionField.value = data.description;
            } else {
                aiNote.textContent = 'AI unavailable — write manually.';
                aiNote.classList.remove('hidden');
            }
        } catch (error) {
            aiNote.textContent = 'AI unavailable — write manually.';
            aiNote.classList.remove('hidden');
        } finally {
            generateButton.disabled = false;
            generateButton.textContent = originalLabel;
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        formError.classList.add('hidden');
        formError.textContent = '';

        const payload = {
            name: nameField.value.trim(),
            color: colorNameField.value.trim(),
            style: styleField.value,
            size: sizeField.value,
            price: priceField.value,
            description: descriptionField.value.trim() || null,
        };

        const isEdit = Boolean(idField.value);
        const url = isEdit ? `/api/hats/${idField.value}` : '/api/hats';
        const method = isEdit ? 'PUT' : 'POST';

        submitButton.disabled = true;

        try {
            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify(payload),
            });

            if (response.ok) {
                location.reload();
                return;
            }

            if (response.status === 422) {
                const data = await response.json();
                const messages = Object.values(data.errors ?? {}).flat();
                formError.textContent = messages.join(' ') || 'Please check the form for errors.';
            } else {
                formError.textContent = 'Something went wrong saving this hat.';
            }

            formError.classList.remove('hidden');
        } catch (error) {
            formError.textContent = 'Something went wrong saving this hat.';
            formError.classList.remove('hidden');
        } finally {
            submitButton.disabled = false;
        }
    });
}
