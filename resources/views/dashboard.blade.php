@extends('layouts.app')

@section('title', 'Dashboard — HatShop')

@section('content')
    <div class="navbar bg-base-100 shadow-md px-4 sm:px-8">
        <div class="flex-1">
            <span class="text-xl font-semibold">🧢 HatShop — Shopify Order Listener</span>
        </div>
        <div class="flex-none gap-2">
            <a href="{{ route('try-on') }}" class="btn btn-sm btn-outline">🪞 Virtual try-on</a>
            <a href="{{ route('studio') }}" class="btn btn-sm btn-outline">🎨 Design Studio</a>
        </div>
    </div>

    <main class="max-w-7xl mx-auto px-4 sm:px-8 py-8 space-y-10">
        {{-- Stat cards --}}
        <div class="stats stats-vertical sm:stats-horizontal shadow w-full bg-base-100">
            <div class="stat">
                <div class="stat-title">Total orders</div>
                <div class="stat-value text-primary">{{ $stats['total_orders'] }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">Revenue</div>
                <div class="stat-value text-secondary text-2xl">
                    @forelse ($stats['revenue_by_currency'] as $currency => $total)
                        <div>{{ number_format($total, 2) }} {{ $currency }}</div>
                    @empty
                        <div>—</div>
                    @endforelse
                </div>
            </div>
            <div class="stat">
                <div class="stat-title">Hats in catalog</div>
                <div class="stat-value">{{ $stats['hats_count'] }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">Orders today</div>
                <div class="stat-value">{{ $stats['orders_today'] }}</div>
            </div>
        </div>

        {{-- AI insights panel --}}
        <section class="card bg-base-100 shadow">
            <div class="card-body">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="card-title">AI order insights</h2>
                    <button id="generate-insights-btn" type="button" class="btn btn-primary btn-sm">
                        Generate insights
                    </button>
                </div>

                <div id="insights-spinner" class="hidden items-center gap-2 text-sm opacity-70 mt-2">
                    <span class="loading loading-spinner loading-sm"></span>
                    Talking to Claude…
                </div>

                <div id="insights-result" class="hidden mt-2">
                    <div class="flex items-center gap-2 mb-2">
                        <span id="insights-badge" class="badge"></span>
                    </div>
                    <p id="insights-summary" class="leading-relaxed"></p>
                </div>

                <p id="insights-placeholder" class="text-sm opacity-60 mt-2">
                    Click "Generate insights" for an AI-written summary of recent order trends.
                </p>
            </div>
        </section>

        {{-- Orders table --}}
        <section>
            <h2 class="text-lg font-semibold mb-3">Recent orders</h2>
            <div class="card bg-base-100 shadow overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Hat</th>
                            <th>Qty</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            @php
                                $statusClass = match ($order->status) {
                                    'paid' => 'badge-success',
                                    'pending' => 'badge-warning',
                                    'refunded' => 'badge-error',
                                    default => 'badge-neutral',
                                };
                            @endphp
                            <tr>
                                <td>{{ $order->order_number ?? '—' }}</td>
                                <td>
                                    <div class="font-medium">{{ $order->customer_name ?? '—' }}</div>
                                    <div class="text-xs opacity-60">{{ $order->customer_email ?? '—' }}</div>
                                </td>
                                <td>
                                    @if ($order->hat)
                                        <div class="flex items-center gap-2">
                                            <span class="inline-block w-3 h-3 rounded-full ring ring-base-300" style="background-color: {{ $order->hat->color }}"></span>
                                            <div>
                                                <div>{{ $order->hat->name }}</div>
                                                <div class="text-xs opacity-60">{{ $order->hat->style }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="opacity-60">—</span>
                                    @endif
                                </td>
                                <td>{{ $order->quantity }}</td>
                                <td>{{ number_format($order->total_price, 2) }} {{ $order->currency }}</td>
                                <td><span class="badge {{ $statusClass }}">{{ $order->status }}</span></td>
                                <td>{{ $order->created_at?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center opacity-60 py-6">No orders yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Hats admin --}}
        <section>
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-lg font-semibold">Hat catalog</h2>
                <button id="add-hat-btn" type="button" class="btn btn-primary btn-sm">+ Add hat</button>
            </div>

            <div id="hats-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($hats as $hat)
                    <div class="card bg-base-100 shadow" data-hat-card="{{ $hat->id }}">
                        {{-- image_url is NOT NULL and validated non-blank, so this always renders. --}}
                        <figure class="bg-base-200">
                            <img src="{{ $hat->image_url }}" alt="{{ $hat->name }}" class="h-40 w-full object-contain">
                        </figure>
                        <div class="card-body">
                            <div class="flex items-center gap-4">
                                <span
                                    class="w-14 h-14 rounded-full ring ring-2 ring-base-300 ring-offset-2 ring-offset-base-100 shrink-0"
                                    style="background-color: {{ $hat->color }}"
                                ></span>
                                <div>
                                    <h3 class="card-title text-base">{{ $hat->name }}</h3>
                                    <span class="badge badge-outline badge-sm">{{ $hat->style }}</span>
                                    <span class="badge badge-ghost badge-sm">{{ $hat->size }}</span>
                                </div>
                            </div>
                            <p class="font-semibold mt-2">${{ number_format($hat->price, 2) }}</p>
                            <p class="text-sm opacity-70 line-clamp-3">{{ $hat->description ?? 'No description yet.' }}</p>
                            <div class="card-actions justify-end mt-2">
                                <a href="{{ route('try-on', ['hat' => $hat->id]) }}" class="btn btn-sm btn-secondary">
                                    🪞 Try it on
                                </a>
                                <a href="{{ route('studio') }}?hat={{ $hat->id }}" class="btn btn-sm btn-outline">
                                    🎨 Design
                                </a>
                                <button
                                    type="button"
                                    class="btn btn-sm edit-hat-btn"
                                    data-id="{{ $hat->id }}"
                                    data-name="{{ $hat->name }}"
                                    data-color="{{ $hat->color }}"
                                    data-style="{{ $hat->style }}"
                                    data-size="{{ $hat->size }}"
                                    data-price="{{ $hat->price }}"
                                    data-image="{{ $hat->image_url }}"
                                    data-description="{{ $hat->description }}"
                                >
                                    Edit
                                </button>
                                <button type="button" class="btn btn-sm btn-error btn-outline delete-hat-btn" data-id="{{ $hat->id }}">
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="opacity-60">No hats yet — add one to get started.</p>
                @endforelse
            </div>
        </section>
    </main>

    {{-- Hat create/edit modal --}}
    <dialog id="hat-modal" class="modal">
        <div class="modal-box">
            <h3 id="hat-modal-title" class="font-bold text-lg mb-4">Add hat</h3>
            <form id="hat-form" class="space-y-3">
                <input type="hidden" id="hat-id" value="">

                <div>
                    <label class="label" for="hat-name"><span class="label-text">Name</span></label>
                    <input type="text" id="hat-name" class="input input-bordered w-full" required maxlength="255">
                </div>

                <div>
                    <label class="label" for="hat-color-name"><span class="label-text">Color</span></label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="hat-color-picker" class="w-12 h-10 p-1 border rounded" value="#ff0000">
                        <input type="text" id="hat-color-name" class="input input-bordered w-full" required maxlength="100" placeholder="e.g. Red">
                    </div>
                </div>

                <div>
                    <label class="label" for="hat-style"><span class="label-text">Style</span></label>
                    <select id="hat-style" class="select select-bordered w-full" required>
                        <option value="Baseball">Baseball</option>
                        <option value="Snapback">Snapback</option>
                        <option value="Trucker">Trucker</option>
                        <option value="Beanie">Beanie</option>
                        <option value="Bucket">Bucket</option>
                    </select>
                </div>

                <div>
                    <label class="label" for="hat-size"><span class="label-text">Size</span></label>
                    <select id="hat-size" class="select select-bordered w-full" required>
                        @foreach (\App\Models\Hat::SIZES as $size)
                            <option value="{{ $size }}">{{ $size }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label" for="hat-price"><span class="label-text">Price</span></label>
                    <input type="number" id="hat-price" class="input input-bordered w-full" required min="0" step="0.01">
                </div>

                {{-- Required: a hat product without a picture of the hat isn't a product. --}}
                <div>
                    <label class="label" for="hat-image-url">
                        <span class="label-text">Hat image <span class="text-error">*</span></span>
                    </label>
                    <div class="flex items-start gap-3">
                        <div class="w-24 h-24 shrink-0 rounded-lg bg-base-200 grid place-items-center overflow-hidden">
                            <img id="hat-image-preview" src="" alt="" class="w-full h-full object-contain hidden">
                            <span id="hat-image-empty" class="text-3xl opacity-30">🧢</span>
                        </div>
                        <div class="flex-1 space-y-2">
                            <input
                                type="text"
                                id="hat-image-url"
                                class="input input-bordered w-full"
                                required
                                maxlength="2048"
                                placeholder="https://… or upload a photo"
                            >
                            <div class="flex flex-wrap gap-2">
                                <button type="button" id="hat-image-upload-btn" class="btn btn-xs btn-outline">
                                    ⬆️ Upload photo
                                </button>
                                <button type="button" id="hat-image-generate-btn" class="btn btn-xs btn-outline">
                                    🎨 Use generated art
                                </button>
                                <input type="file" id="hat-image-file" accept="image/*" class="hidden">
                            </div>
                            <p id="hat-image-note" class="hidden text-xs mt-1"></p>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <label class="label" for="hat-description"><span class="label-text">Description</span></label>
                        <button type="button" id="generate-description-btn" class="btn btn-xs btn-outline">
                            ✨ Generate with AI
                        </button>
                    </div>
                    <textarea id="hat-description" class="textarea textarea-bordered w-full" rows="3"></textarea>
                    <p id="ai-unavailable-note" class="hidden text-xs text-warning mt-1">AI unavailable — write manually.</p>
                </div>

                <div id="hat-form-error" class="hidden alert alert-error text-sm py-2"></div>

                <div class="modal-action">
                    <button type="button" id="hat-modal-cancel" class="btn">Cancel</button>
                    <button type="submit" id="hat-form-submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
@endsection
