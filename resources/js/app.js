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
    const imageField = document.getElementById('hat-image-url');
    const imagePreview = document.getElementById('hat-image-preview');
    const imageEmpty = document.getElementById('hat-image-empty');
    const imageNote = document.getElementById('hat-image-note');
    const imageFile = document.getElementById('hat-image-file');
    const formError = document.getElementById('hat-form-error');
    const aiNote = document.getElementById('ai-unavailable-note');
    const submitButton = document.getElementById('hat-form-submit');
    const generateButton = document.getElementById('generate-description-btn');

    function setImage(url) {
        imageField.value = url ?? '';
        const hasImage = Boolean(imageField.value.trim());

        imagePreview.src = hasImage ? imageField.value.trim() : '';
        imagePreview.classList.toggle('hidden', !hasImage);
        imageEmpty.classList.toggle('hidden', hasImage);
    }

    function showImageNote(message, tone = 'warning') {
        imageNote.textContent = message;
        imageNote.className = `text-xs mt-1 text-${tone}`;
    }

    function resetForm() {
        form.reset();
        idField.value = '';
        formError.classList.add('hidden');
        formError.textContent = '';
        aiNote.classList.add('hidden');
        imageNote.classList.add('hidden');
        setImage('');
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
        setImage(button.dataset.image ?? '');
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

    imageField.addEventListener('input', () => setImage(imageField.value));

    // "Use generated art" — server-rendered SVG matching this hat's style
    // and color, so a merchant without a photo still ships a real image.
    document.getElementById('hat-image-generate-btn')?.addEventListener('click', () => {
        const style = styleField.value || 'Baseball';
        const color = colorNameField.value.trim() || colorPicker.value;

        setImage(`/hat-art/${encodeURIComponent(style)}?color=${encodeURIComponent(color)}`);
        imageNote.classList.add('hidden');
    });

    document.getElementById('hat-image-upload-btn')?.addEventListener('click', () => imageFile.click());

    // Uploads reuse the design-file store: bytes live in the database
    // (no persistent disk on Render) and come back out as a public URL.
    imageFile.addEventListener('change', async () => {
        const file = imageFile.files?.[0];
        if (!file) return;

        imageNote.classList.remove('hidden');
        showImageNote('Uploading…', 'info');

        const body = new FormData();
        body.append('file', file);

        try {
            const response = await fetch('/api/design-files', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body,
            });

            const data = await response.json();

            if (!response.ok || !data.url) {
                showImageNote(
                    Object.values(data.errors ?? {}).flat().join(' ') || 'Upload failed — try another image.',
                    'error',
                );
                return;
            }

            setImage(data.url);
            showImageNote('Uploaded.', 'success');
        } catch (error) {
            showImageNote('Upload failed — check your connection.', 'error');
        } finally {
            imageFile.value = '';
        }
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
            image_url: imageField.value.trim(),
            description: descriptionField.value.trim() || null,
        };

        if (!payload.image_url) {
            formError.textContent = 'Every hat needs an image — upload a photo or use generated art.';
            formError.classList.remove('hidden');
            return;
        }

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
