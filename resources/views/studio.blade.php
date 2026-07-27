@extends('layouts.app')

@section('title', 'Design Studio — HatShop')

@section('content')
    <div class="navbar bg-base-100 shadow-md px-4 sm:px-8">
        <div class="flex-1">
            <span class="text-xl font-semibold">🎨 Design Studio</span>
        </div>
        <div class="flex-none">
            <a href="{{ route('dashboard') }}" class="btn btn-sm btn-outline">← Back to dashboard</a>
        </div>
    </div>

    <main class="max-w-5xl mx-auto px-4 sm:px-8 py-8 space-y-8">
        {{-- Hat picker --}}
        <section class="card bg-base-100 shadow">
            <div class="card-body">
                <h2 class="card-title">Designing for</h2>
                <select id="hat-select" class="select select-bordered w-full max-w-md">
                    <option value="">Loading hats…</option>
                </select>
                <p id="hat-select-note" class="text-sm opacity-60 mt-1">
                    Pick which hat in your catalog this design/mockup belongs to.
                </p>
            </div>
        </section>

        {{-- Step 1: base hat --}}
        <section class="card bg-base-100 shadow">
            <div class="card-body">
                <h2 class="card-title">1. Pick a Printful base product</h2>
                <p id="catalog-live-note" class="hidden text-xs text-warning mb-2">
                    Printful API unavailable — showing a fallback shortlist.
                </p>
                <div id="product-spinner" class="flex items-center gap-2 text-sm opacity-70">
                    <span class="loading loading-spinner loading-sm"></span> Loading catalog…
                </div>
                <div id="product-grid" class="grid grid-cols-2 sm:grid-cols-3 gap-3"></div>

                <div id="variant-section" class="hidden mt-4">
                    <h3 class="font-semibold mb-2">Color / variant</h3>
                    <div id="variant-grid" class="flex flex-wrap gap-2"></div>
                </div>
            </div>
        </section>

        {{-- Step 2: design --}}
        <section class="card bg-base-100 shadow">
            <div class="card-body">
                <h2 class="card-title">2. Create your design</h2>

                <div role="tablist" class="tabs tabs-bordered">
                    <a role="tab" id="tab-upload" class="tab tab-active">Upload image</a>
                    <a role="tab" id="tab-text" class="tab">Text designer</a>
                </div>

                <div id="panel-upload" class="mt-4 space-y-3">
                    <input type="file" id="design-file-input" accept="image/*" class="file-input file-input-bordered w-full max-w-md">
                </div>

                <div id="panel-text" class="hidden mt-4 space-y-3">
                    <input type="text" id="design-text" maxlength="60" placeholder="Your text" class="input input-bordered w-full max-w-md" value="HATSHOP">

                    <div class="flex flex-wrap items-center gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            Size
                            <input type="range" id="design-font-size" min="24" max="220" value="120" class="range range-sm w-40">
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            Color
                            <input type="color" id="design-text-color" value="#ffffff" class="w-10 h-8 p-0.5 border rounded">
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" id="design-bold" class="checkbox checkbox-sm"> Bold
                        </label>
                    </div>
                </div>

                <div class="mt-4">
                    <p class="text-sm opacity-60 mb-2">Preview</p>
                    <div class="border border-base-300 rounded-lg inline-block p-2 bg-base-200">
                        <canvas id="design-canvas" width="1024" height="1024" style="width:260px;height:260px;"></canvas>
                    </div>
                </div>
            </div>
        </section>

        {{-- Step 3: mockup --}}
        <section class="card bg-base-100 shadow">
            <div class="card-body">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="card-title">3. Generate a real mockup</h2>
                    <button id="generate-mockup-btn" type="button" class="btn btn-primary btn-sm">Generate real mockup</button>
                </div>

                <div id="mockup-spinner" class="hidden items-center gap-2 text-sm opacity-70 mt-2">
                    <span class="loading loading-spinner loading-sm"></span>
                    <span id="mockup-spinner-text">Sending design to Printful…</span>
                </div>

                <div id="mockup-error" class="hidden alert alert-error text-sm mt-2"></div>

                <div id="mockup-gallery" class="hidden grid grid-cols-2 sm:grid-cols-3 gap-3 mt-3"></div>
            </div>
        </section>

        {{-- Step 4: supplier order --}}
        <section class="card bg-base-100 shadow">
            <div class="card-body">
                <h2 class="card-title">4. Order from supplier (draft only)</h2>
                <p class="text-sm opacity-60">
                    Creates a draft order in Printful — nothing is confirmed or paid for automatically.
                    You review and pay in your own Printful dashboard.
                </p>

                <form id="supplier-order-form" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                    <input type="text" id="recipient-name" placeholder="Full name" class="input input-bordered w-full" required>
                    <input type="text" id="recipient-address1" placeholder="Address" class="input input-bordered w-full" required>
                    <input type="text" id="recipient-city" placeholder="City" class="input input-bordered w-full" required>
                    <input type="text" id="recipient-zip" placeholder="ZIP / postal code" class="input input-bordered w-full" required>

                    <select id="recipient-country" class="select select-bordered w-full" required>
                        <option value="US">United States</option>
                        <option value="IL">Israel</option>
                        <option value="GB">United Kingdom</option>
                        <option value="DE">Germany</option>
                        <option value="CA">Canada</option>
                        <option value="AU">Australia</option>
                    </select>
                    <input type="number" id="order-quantity" min="1" max="100" value="1" class="input input-bordered w-full" required>

                    <div class="sm:col-span-2">
                        <button type="submit" id="supplier-order-submit" class="btn btn-primary btn-sm">Create draft order</button>
                    </div>
                </form>

                <div id="supplier-order-error" class="hidden alert alert-error text-sm mt-2"></div>
                <div id="supplier-order-success" class="hidden alert alert-success text-sm mt-2"></div>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const state = {
                catalogLive: null,
                hatId: null,
                printfulProductId: null,
                printfulVariantId: null,
                designFileId: null,
                designDataUrl: null,
                taskKey: null,
            };

            const params = new URLSearchParams(window.location.search);
            const preselectedHat = params.get('hat');
            if (preselectedHat) {
                state.hatId = Number(preselectedHat);
            }

            initHatPicker();
            initProductPicker();
            initDesignTabs();
            initTextDesigner();
            initMockupFlow();
            initSupplierOrderForm();

            /**
             * Populate the "designing for" hat select from /api/hats.
             */
            function initHatPicker() {
                const select = document.getElementById('hat-select');

                fetch('/api/hats', { headers: { Accept: 'application/json' } })
                    .then((response) => response.json())
                    .then((data) => {
                        const hats = data.data ?? [];
                        select.innerHTML = '<option value="">Choose a hat…</option>';

                        hats.forEach((hat) => {
                            const option = document.createElement('option');
                            option.value = hat.id;
                            option.textContent = `${hat.name} (${hat.color} ${hat.style})`;
                            if (state.hatId && Number(hat.id) === state.hatId) {
                                option.selected = true;
                            }
                            select.appendChild(option);
                        });
                    })
                    .catch(() => {
                        select.innerHTML = '<option value="">Could not load hats</option>';
                    });

                select.addEventListener('change', () => {
                    state.hatId = select.value ? Number(select.value) : null;
                });
            }

            /**
             * Step 1: fetch the Printful catalog, render selectable cards,
             * then fetch + render variant swatches on selection.
             */
            function initProductPicker() {
                const spinner = document.getElementById('product-spinner');
                const grid = document.getElementById('product-grid');
                const liveNote = document.getElementById('catalog-live-note');
                const variantSection = document.getElementById('variant-section');
                const variantGrid = document.getElementById('variant-grid');

                fetch('/api/printful/products', { headers: { Accept: 'application/json' } })
                    .then((response) => response.json())
                    .then((data) => {
                        spinner.classList.add('hidden');

                        state.catalogLive = data.live !== false;

                        if (data.live === false) {
                            liveNote.classList.remove('hidden');
                        }

                        const products = data.products ?? [];

                        if (products.length === 0) {
                            grid.innerHTML = '<p class="opacity-60">No products available.</p>';
                            return;
                        }

                        products.forEach((product) => {
                            const card = document.createElement('button');
                            card.type = 'button';
                            card.className = 'card bg-base-200 shadow-sm text-left product-card';
                            card.dataset.id = product.id;
                            card.innerHTML = `
                                <div class="card-body p-3">
                                    ${product.image ? `<img src="${product.image}" alt="${product.title ?? ''}" class="rounded w-full h-24 object-contain bg-base-100">` : ''}
                                    <p class="text-sm font-medium mt-1">${product.title ?? 'Product #' + product.id}</p>
                                </div>
                            `;

                            card.addEventListener('click', () => {
                                document.querySelectorAll('.product-card').forEach((el) => el.classList.remove('ring', 'ring-2', 'ring-primary'));
                                card.classList.add('ring', 'ring-2', 'ring-primary');

                                state.printfulProductId = Number(product.id);
                                state.printfulVariantId = null;
                                loadVariants(product.id);
                            });

                            grid.appendChild(card);
                        });
                    })
                    .catch(() => {
                        spinner.classList.add('hidden');
                        grid.innerHTML = '<p class="text-error">Could not load the Printful catalog.</p>';
                    });

                function loadVariants(productId) {
                    variantSection.classList.remove('hidden');

                    if (state.catalogLive === false) {
                        variantGrid.innerHTML = '<div class="alert alert-warning text-sm">Printful is not connected yet — color variants and real hat mockups require a <code>PRINTFUL_API_KEY</code> on the server. Once it\'s added, reload this page to design on real products.</div>';
                        return;
                    }

                    variantGrid.innerHTML = '<span class="loading loading-spinner loading-sm"></span>';

                    fetch(`/api/printful/products/${productId}`, { headers: { Accept: 'application/json' } })
                        .then((response) => response.json())
                        .then((data) => {
                            const variants = data.variants ?? [];
                            variantGrid.innerHTML = '';

                            if (variants.length === 0) {
                                variantGrid.innerHTML = '<p class="opacity-60 text-sm">No variants available.</p>';
                                return;
                            }

                            variants.forEach((variant) => {
                                const swatch = document.createElement('button');
                                swatch.type = 'button';
                                swatch.className = 'btn btn-sm variant-swatch';
                                swatch.dataset.id = variant.id;
                                swatch.textContent = variant.color ?? variant.name ?? ('#' + variant.id);

                                swatch.addEventListener('click', () => {
                                    document.querySelectorAll('.variant-swatch').forEach((el) => el.classList.remove('btn-primary'));
                                    swatch.classList.add('btn-primary');
                                    state.printfulVariantId = Number(variant.id);
                                });

                                variantGrid.appendChild(swatch);
                            });
                        })
                        .catch(() => {
                            variantGrid.innerHTML = '<p class="text-error text-sm">Could not load variants.</p>';
                        });
                }
            }

            /**
             * Step 2 tabs: Upload image vs. Text designer.
             */
            function initDesignTabs() {
                const tabUpload = document.getElementById('tab-upload');
                const tabText = document.getElementById('tab-text');
                const panelUpload = document.getElementById('panel-upload');
                const panelText = document.getElementById('panel-text');

                tabUpload.addEventListener('click', () => {
                    tabUpload.classList.add('tab-active');
                    tabText.classList.remove('tab-active');
                    panelUpload.classList.remove('hidden');
                    panelText.classList.add('hidden');
                });

                tabText.addEventListener('click', () => {
                    tabText.classList.add('tab-active');
                    tabUpload.classList.remove('tab-active');
                    panelText.classList.remove('hidden');
                    panelUpload.classList.add('hidden');
                    renderTextDesign();
                });

                const fileInput = document.getElementById('design-file-input');
                fileInput.addEventListener('change', () => {
                    const file = fileInput.files[0];
                    if (!file) return;

                    const reader = new FileReader();
                    reader.onload = () => {
                        state.designDataUrl = reader.result;
                        drawImageToCanvas(reader.result);
                    };
                    reader.readAsDataURL(file);
                });
            }

            function drawImageToCanvas(dataUrl) {
                const canvas = document.getElementById('design-canvas');
                const ctx = canvas.getContext('2d');
                const img = new Image();
                img.onload = () => {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    const scale = Math.min(canvas.width / img.width, canvas.height / img.height);
                    const w = img.width * scale;
                    const h = img.height * scale;
                    ctx.drawImage(img, (canvas.width - w) / 2, (canvas.height - h) / 2, w, h);
                };
                img.src = dataUrl;
            }

            /**
             * Step 2 text tab: render text onto the 1024x1024 canvas and
             * keep `state.designDataUrl` in sync as a live preview.
             */
            function initTextDesigner() {
                ['design-text', 'design-font-size', 'design-text-color', 'design-bold'].forEach((id) => {
                    document.getElementById(id).addEventListener('input', renderTextDesign);
                });

                renderTextDesign();
            }

            function renderTextDesign() {
                if (!document.getElementById('tab-text').classList.contains('tab-active')) {
                    return;
                }

                const canvas = document.getElementById('design-canvas');
                const ctx = canvas.getContext('2d');
                const text = document.getElementById('design-text').value || '';
                const fontSize = document.getElementById('design-font-size').value;
                const color = document.getElementById('design-text-color').value;
                const bold = document.getElementById('design-bold').checked;

                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.fillStyle = color;
                ctx.font = `${bold ? 'bold' : 'normal'} ${fontSize}px sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(text, canvas.width / 2, canvas.height / 2);

                state.designDataUrl = canvas.toDataURL('image/png');
            }

            /**
             * Step 3: upload the current design, create a mockup task, then
             * poll it until it completes/fails/times out.
             */
            function initMockupFlow() {
                const button = document.getElementById('generate-mockup-btn');
                const spinner = document.getElementById('mockup-spinner');
                const spinnerText = document.getElementById('mockup-spinner-text');
                const errorBox = document.getElementById('mockup-error');
                const gallery = document.getElementById('mockup-gallery');

                button.addEventListener('click', async () => {
                    errorBox.classList.add('hidden');
                    gallery.classList.add('hidden');
                    gallery.innerHTML = '';

                    if (!state.hatId) {
                        showError('Pick a hat first.');
                        return;
                    }
                    if (!state.printfulProductId || !state.printfulVariantId) {
                        showError('Pick a Printful base product and color/variant first.');
                        return;
                    }
                    if (!state.designDataUrl) {
                        showError('Upload an image or create a text design first.');
                        return;
                    }

                    button.disabled = true;
                    spinner.classList.remove('hidden');
                    spinner.classList.add('flex');
                    spinnerText.textContent = 'Uploading design…';

                    try {
                        const uploadResponse = await fetch('/api/design-files', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                            body: JSON.stringify({ data_url: state.designDataUrl }),
                        });

                        if (!uploadResponse.ok) {
                            throw new Error('Could not upload the design.');
                        }

                        const uploadData = await uploadResponse.json();
                        state.designFileId = uploadData.id;

                        spinnerText.textContent = 'Starting mockup generation…';

                        const mockupResponse = await fetch(`/api/hats/${state.hatId}/mockup`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                            body: JSON.stringify({
                                printful_product_id: state.printfulProductId,
                                printful_variant_id: state.printfulVariantId,
                                design_file_id: state.designFileId,
                            }),
                        });

                        const mockupData = await mockupResponse.json();

                        if (!mockupResponse.ok || !mockupData.task_key) {
                            throw new Error(mockupData.error ?? 'Could not start mockup generation.');
                        }

                        state.taskKey = mockupData.task_key;
                        await pollMockupTask(state.taskKey);
                    } catch (error) {
                        showError(error.message ?? 'Something went wrong generating the mockup.');
                    } finally {
                        button.disabled = false;
                        spinner.classList.add('hidden');
                        spinner.classList.remove('flex');
                    }
                });

                async function pollMockupTask(taskKey) {
                    const maxAttempts = 20; // ~60s at 3s intervals
                    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
                        spinnerText.textContent = `Waiting for Printful mockup… (${attempt}/${maxAttempts})`;

                        const response = await fetch(`/api/mockup-tasks/${taskKey}?hat_id=${state.hatId}`, {
                            headers: { Accept: 'application/json' },
                        });
                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.error ?? 'Could not check mockup status.');
                        }

                        if (data.status === 'completed') {
                            renderGallery(data.mockups ?? []);
                            return;
                        }

                        if (data.status === 'failed') {
                            throw new Error('Printful reported the mockup generation failed.');
                        }

                        await sleep(3000);
                    }

                    throw new Error('Timed out waiting for the mockup. Try again shortly.');
                }

                function renderGallery(mockups) {
                    gallery.innerHTML = '';

                    if (mockups.length === 0) {
                        showError('Mockup completed but returned no images.');
                        return;
                    }

                    mockups.forEach((mockup) => {
                        if (!mockup.mockup_url) return;
                        const img = document.createElement('img');
                        img.src = mockup.mockup_url;
                        img.className = 'rounded w-full object-contain bg-base-200';
                        gallery.appendChild(img);
                    });

                    gallery.classList.remove('hidden');
                }

                function showError(message) {
                    errorBox.textContent = message;
                    errorBox.classList.remove('hidden');
                }

                function sleep(ms) {
                    return new Promise((resolve) => setTimeout(resolve, ms));
                }
            }

            /**
             * Step 4: create a draft (never confirmed) Printful order.
             */
            function initSupplierOrderForm() {
                const form = document.getElementById('supplier-order-form');
                const submitButton = document.getElementById('supplier-order-submit');
                const errorBox = document.getElementById('supplier-order-error');
                const successBox = document.getElementById('supplier-order-success');

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    errorBox.classList.add('hidden');
                    successBox.classList.add('hidden');

                    if (!state.hatId) {
                        errorBox.textContent = 'Pick a hat first.';
                        errorBox.classList.remove('hidden');
                        return;
                    }

                    const payload = {
                        recipient: {
                            name: document.getElementById('recipient-name').value.trim(),
                            address1: document.getElementById('recipient-address1').value.trim(),
                            city: document.getElementById('recipient-city').value.trim(),
                            country_code: document.getElementById('recipient-country').value,
                            zip: document.getElementById('recipient-zip').value.trim(),
                        },
                        quantity: Number(document.getElementById('order-quantity').value) || 1,
                    };

                    submitButton.disabled = true;

                    try {
                        const response = await fetch(`/api/hats/${state.hatId}/supplier-order`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                            body: JSON.stringify(payload),
                        });

                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.error ?? 'Could not create the supplier order.');
                        }

                        const orderId = data.order?.id ?? '—';
                        successBox.innerHTML = `Draft order <strong>#${orderId}</strong> created. ${data.note ?? ''} `
                            + `<a href="https://www.printful.com/dashboard" target="_blank" rel="noopener" class="link">Open Printful dashboard</a>`;
                        successBox.classList.remove('hidden');
                    } catch (error) {
                        errorBox.textContent = error.message ?? 'Could not create the supplier order.';
                        errorBox.classList.remove('hidden');
                    } finally {
                        submitButton.disabled = false;
                    }
                });
            }
        });
    </script>
@endsection
