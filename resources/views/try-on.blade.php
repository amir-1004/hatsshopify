@extends('layouts.app')

@section('title', 'Virtual try-on — HatShop')

@push('head')
    @vite('resources/js/tryon.js')
@endpush

@php
    // Resolve each hat's color server-side so the 3D model and the generated
    // artwork agree on what "Olive" means.
    $art = app(\App\Services\HatArtService::class);
@endphp

@section('content')
    <div class="navbar bg-base-100 shadow-md px-4 sm:px-8">
        <div class="flex-1">
            <span class="text-xl font-semibold">🪞 Try it on</span>
        </div>
        <div class="flex-none gap-2">
            <a href="{{ route('dashboard') }}" class="btn btn-sm btn-outline">← Dashboard</a>
        </div>
    </div>

    <main class="max-w-7xl mx-auto px-4 sm:px-8 py-8">
        <div class="grid grid-cols-1 lg:grid-cols-[360px_1fr] gap-6 items-start">
            {{-- Controls --}}
            <div class="space-y-4">
                <section class="card bg-base-100 shadow">
                    <div class="card-body gap-3">
                        <h2 class="card-title text-base">1. Pick a hat</h2>

                        <select
                            id="tryon-hat"
                            class="select select-bordered w-full"
                            data-preselected="{{ $selected ? '1' : '' }}"
                        >
                            @forelse ($hats as $hat)
                                <option
                                    value="{{ $hat->id }}"
                                    data-color="{{ $hat->color }}"
                                    data-hex="{{ $art->resolveColor($hat->color) }}"
                                    data-style="{{ $art->normalizeStyle($hat->style) }}"
                                    data-size="{{ $hat->size }}"
                                    data-image="{{ $hat->image_url }}"
                                    @selected($selected && $selected->id === $hat->id)
                                >
                                    {{ $hat->name }} — {{ $hat->style }} ({{ $hat->size }})
                                </option>
                            @empty
                                <option value="">No hats in the catalog yet</option>
                            @endforelse
                        </select>

                        <div class="flex items-center gap-3">
                            <div class="w-16 h-16 rounded-lg bg-base-200 overflow-hidden shrink-0">
                                <img id="tryon-hat-image" src="" alt="" class="w-full h-full object-contain">
                            </div>
                            <div class="space-y-1">
                                <span id="tryon-provenance" class="badge badge-sm badge-ghost">⚙️ Generated 3D preview</span>
                                <p class="text-sm opacity-70">
                                    Scanned products show their real fabric; the rest use generated geometry.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 shadow">
                    <div class="card-body gap-3">
                        <h2 class="card-title text-base">2. Give it your face</h2>

                        <input type="file" id="tryon-photo" accept="image/*" class="file-input file-input-bordered w-full">

                        <div class="flex flex-wrap gap-2">
                            <button type="button" id="tryon-camera-btn" class="btn btn-sm btn-outline">📷 Use camera</button>
                            <button type="button" id="tryon-capture-btn" class="btn btn-sm btn-primary hidden">Capture</button>
                            <button type="button" id="tryon-rescan-btn" class="btn btn-sm btn-outline hidden">↻ Rescan face</button>
                        </div>

                        <p class="text-xs opacity-60 leading-relaxed">
                            🔒 Your photo is measured inside your browser and is never uploaded — only the
                            resulting measurements (two numbers) are sent, to look up your size.
                        </p>
                    </div>
                </section>

                <section id="tryon-measurements" class="card bg-base-100 shadow hidden">
                    <div class="card-body gap-3">
                        <h2 class="card-title text-base">3. Your measurements</h2>

                        <div class="stats stats-vertical bg-base-200">
                            <div class="stat py-3">
                                <div class="stat-title text-xs">Head circumference</div>
                                <div class="stat-value text-2xl" id="tryon-circumference">—</div>
                            </div>
                            <div class="stat py-3">
                                <div class="stat-title text-xs">Recommended size</div>
                                <div class="stat-value text-2xl text-primary" id="tryon-size">—</div>
                            </div>
                        </div>

                        <div class="text-sm space-y-1 opacity-80">
                            <div class="flex justify-between"><span>Face width</span><span id="tryon-face-width">—</span></div>
                            <div class="flex justify-between"><span>Eye distance</span><span id="tryon-ipd">—</span></div>
                            <div class="flex justify-between"><span>Skull depth (est.)</span><span id="tryon-depth">—</span></div>
                        </div>

                        <div id="tryon-fit" class="alert text-sm py-2"></div>
                    </div>
                </section>

                <section class="card bg-base-100 shadow">
                    <div class="card-body gap-2">
                        <h2 class="card-title text-base">Manual measurement</h2>
                        <p class="text-xs opacity-60">
                            Know your size already, or the scan couldn't find a face? Set it by hand.
                        </p>
                        <input type="range" id="tryon-manual" class="range range-sm" min="48" max="68" step="0.5" value="57">
                        <div class="flex justify-between text-xs opacity-60">
                            <span>48 cm</span><span id="tryon-manual-value">57.0 cm</span><span>68 cm</span>
                        </div>
                        <button type="button" id="tryon-manual-btn" class="btn btn-sm btn-outline">Use this measurement</button>
                    </div>
                </section>
            </div>

            {{-- 3D stage --}}
            <div class="space-y-3">
                <div id="tryon-stage" class="relative w-full aspect-[4/3] rounded-2xl bg-base-100 shadow overflow-hidden">
                    {{-- Photo underneath, WebGL hat above it, face-scan dots on top. --}}
                    <img id="tryon-photo-img" src="" alt="" class="absolute inset-0 w-full h-full object-contain hidden">

                    <canvas id="tryon-canvas" class="absolute inset-0 w-full h-full"></canvas>
                    <canvas id="tryon-overlay" class="absolute inset-0 w-full h-full pointer-events-none"></canvas>

                    {{-- Sits at the bottom so the idling 3D hat has the stage to itself. --}}
                    <div id="tryon-placeholder" class="absolute inset-x-0 bottom-0 text-center px-6 pb-6 pointer-events-none">
                        <div class="space-y-1">
                            <p class="text-lg font-semibold">Add a photo of your face</p>
                            <p class="text-sm opacity-60 max-w-md mx-auto">
                                Look straight at the camera with your whole head in frame. We'll find your face,
                                measure your skull, and put the hat on you in 3D.
                            </p>
                        </div>
                    </div>

                    <div id="tryon-status" class="absolute top-3 left-3 badge badge-lg gap-2 hidden"></div>

                    <video id="tryon-video" class="absolute inset-0 w-full h-full object-cover hidden pointer-events-none" playsinline muted></video>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-sm opacity-70">
                    <span class="badge badge-ghost">🖱️ Drag to turn your head</span>
                    <span class="badge badge-ghost">⇧ + drag to nudge the hat</span>
                    <span class="badge badge-ghost">Scroll to zoom</span>
                    <button type="button" id="tryon-reset-btn" class="btn btn-xs btn-outline ml-auto">Reset view</button>
                </div>
            </div>
        </div>
    </main>
@endsection
