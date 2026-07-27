// Virtual try-on: measure a shopper's head from a photo, then put a 3D hat
// on it that they can push around with the mouse.
//
// Everything image-related happens on the shopper's device. Only two numbers
// (the eye distance and face width, in pixels) are POSTed to the server,
// which owns the geometry that turns them into centimetres and a size.

import * as THREE from 'three';
import { detectFace, measureFace } from './tryon/face.js';
import { buildHat } from './tryon/hat-model.js';

const FOV = 35;

document.addEventListener('DOMContentLoaded', () => {
    const stage = document.getElementById('tryon-stage');

    if (stage) initTryOn(stage);
});

function initTryOn(stage) {
    const el = {
        stage,
        canvas: document.getElementById('tryon-canvas'),
        overlay: document.getElementById('tryon-overlay'),
        photo: document.getElementById('tryon-photo-img'),
        placeholder: document.getElementById('tryon-placeholder'),
        status: document.getElementById('tryon-status'),
        video: document.getElementById('tryon-video'),
        hatSelect: document.getElementById('tryon-hat'),
        hatImage: document.getElementById('tryon-hat-image'),
        fileInput: document.getElementById('tryon-photo'),
        cameraBtn: document.getElementById('tryon-camera-btn'),
        captureBtn: document.getElementById('tryon-capture-btn'),
        rescanBtn: document.getElementById('tryon-rescan-btn'),
        resetBtn: document.getElementById('tryon-reset-btn'),
        measurements: document.getElementById('tryon-measurements'),
        circumference: document.getElementById('tryon-circumference'),
        size: document.getElementById('tryon-size'),
        faceWidth: document.getElementById('tryon-face-width'),
        ipd: document.getElementById('tryon-ipd'),
        depth: document.getElementById('tryon-depth'),
        fit: document.getElementById('tryon-fit'),
        manual: document.getElementById('tryon-manual'),
        manualValue: document.getElementById('tryon-manual-value'),
        manualBtn: document.getElementById('tryon-manual-btn'),
    };

    const state = {
        image: null, // HTMLImageElement of the shopper's photo
        measurement: null, // output of measureFace(), in photo pixels
        placement: null, // where the hat sits, in stage CSS pixels
        stream: null,
    };

    const view = createScene(el.canvas);

    // ---------------------------------------------------------------- hats

    function currentHat() {
        const option = el.hatSelect?.selectedOptions?.[0];

        if (!option || !option.value) return null;

        return {
            id: option.value,
            style: option.dataset.style || 'Baseball',
            hex: option.dataset.hex || '#4c6ef5',
            size: option.dataset.size || 'Universal',
            image: option.dataset.image || '',
        };
    }

    function swapHat() {
        const hat = currentHat();

        if (el.hatImage) {
            el.hatImage.src = hat?.image ?? '';
            el.hatImage.alt = hat ? `${hat.style} hat` : '';
        }

        view.setHat(hat ? buildHat(hat.style, hat.hex) : null);
        applyPlacement();
    }

    el.hatSelect?.addEventListener('change', () => {
        swapHat();
        if (state.measurement || state.placement) refreshRecommendation();
    });

    // -------------------------------------------------------------- layout

    /**
     * Where the photo actually lands inside the stage once `object-contain`
     * has letterboxed it — the mapping from photo pixels to stage pixels.
     */
    function photoRect() {
        const { clientWidth: sw, clientHeight: sh } = el.stage;

        if (!state.image) return { scale: 1, offsetX: 0, offsetY: 0, stageWidth: sw, stageHeight: sh };

        const scale = Math.min(sw / state.image.naturalWidth, sh / state.image.naturalHeight);

        return {
            scale,
            offsetX: (sw - state.image.naturalWidth * scale) / 2,
            offsetY: (sh - state.image.naturalHeight * scale) / 2,
            stageWidth: sw,
            stageHeight: sh,
        };
    }

    /**
     * Recompute where the hat sits from the last face measurement.
     */
    function autoPlace() {
        const measurement = state.measurement;

        if (!measurement) {
            const { stageWidth, stageHeight } = photoRect();

            state.placement = {
                x: stageWidth / 2,
                y: stageHeight * 0.42,
                radius: Math.min(stageWidth, stageHeight) * 0.2,
                yaw: 0,
                pitch: 0,
                roll: 0,
                scale: 1,
            };

            return;
        }

        const { scale, offsetX, offsetY } = photoRect();

        // Skull half-breadth, in stage pixels. The face oval is narrower than
        // the skull it sits on, hence the widening factor.
        const radius = (measurement.faceWidthPx * 1.12 * scale) / 2;

        state.placement = {
            x: offsetX + measurement.center.x * scale,
            // Sit the band just below the top of the forehead.
            y: offsetY + (measurement.top.y + measurement.faceHeightPx * 0.06) * scale,
            radius,
            yaw: measurement.yaw,
            pitch: measurement.pitch,
            roll: measurement.roll,
            scale: 1,
        };
    }

    function applyPlacement() {
        if (!state.placement) autoPlace();

        view.place(state.placement, photoRect());
    }

    // ------------------------------------------------------------- scanning

    function setStatus(text, tone = 'badge-neutral') {
        if (!el.status) return;

        if (!text) {
            el.status.classList.add('hidden');
            return;
        }

        el.status.className = `absolute top-3 left-3 badge badge-lg gap-2 ${tone}`;
        el.status.textContent = text;
    }

    async function useImage(src) {
        stopCamera();

        const image = new Image();
        image.decoding = 'async';

        await new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = () => reject(new Error('Could not read that image.'));
            image.src = src;
        });

        state.image = image;
        state.measurement = null;
        state.placement = null;

        el.photo.src = src;
        el.photo.classList.remove('hidden');
        el.placeholder.classList.add('hidden');
        el.rescanBtn.classList.remove('hidden');

        view.resize();
        await scan();
    }

    async function scan() {
        if (!state.image) return;

        setStatus('Finding your face…', 'badge-info');
        clearOverlay();

        let landmarks = null;

        try {
            landmarks = await detectFace(state.image);
        } catch (error) {
            setStatus('Face scanner unavailable — set your size by hand', 'badge-warning');
            autoPlace();
            applyPlacement();
            return;
        }

        if (!landmarks) {
            setStatus('No face found — try a clearer, front-on photo', 'badge-warning');
            autoPlace();
            applyPlacement();
            return;
        }

        state.measurement = measureFace(landmarks, state.image.naturalWidth, state.image.naturalHeight);

        await runScanAnimation(state.measurement);

        setStatus('Face mapped ✓', 'badge-success');
        window.setTimeout(() => setStatus(null), 2200);

        autoPlace();
        applyPlacement();
        await refreshRecommendation();
    }

    // ------------------------------------------------- face-scan animation

    function clearOverlay() {
        const context = el.overlay.getContext('2d');

        context.clearRect(0, 0, el.overlay.width, el.overlay.height);
    }

    function sizeOverlay() {
        const ratio = Math.min(window.devicePixelRatio || 1, 2);

        el.overlay.width = el.stage.clientWidth * ratio;
        el.overlay.height = el.stage.clientHeight * ratio;

        const context = el.overlay.getContext('2d');
        context.setTransform(ratio, 0, 0, ratio, 0, 0);

        return context;
    }

    /**
     * The dot-projector moment: sweep a band down the face, lighting up the
     * landmark cloud as it passes, then draw the two measurements we took.
     */
    function runScanAnimation(measurement) {
        const context = sizeOverlay();
        const { scale, offsetX, offsetY } = photoRect();

        const toStage = (point) => ({
            x: offsetX + point.x * scale,
            y: offsetY + point.y * scale,
        });

        const points = measurement.points.map(toStage);
        const ys = points.map((point) => point.y);
        const from = Math.min(...ys) - 20;
        const to = Math.max(...ys) + 20;

        const duration = 1100;
        const start = performance.now();

        return new Promise((resolve) => {
            const frame = (now) => {
                const t = Math.min(1, (now - start) / duration);
                const band = from + (to - from) * t;

                context.clearRect(0, 0, el.stage.clientWidth, el.stage.clientHeight);

                for (const point of points) {
                    const distance = Math.abs(point.y - band);
                    const lit = Math.max(0, 1 - distance / 90);

                    context.beginPath();
                    context.arc(point.x, point.y, 0.7 + lit * 1.8, 0, Math.PI * 2);
                    context.fillStyle = `rgba(125, 211, 252, ${0.18 + lit * 0.8})`;
                    context.fill();
                }

                context.strokeStyle = 'rgba(125, 211, 252, 0.55)';
                context.lineWidth = 2;
                context.beginPath();
                context.moveTo(offsetX, band);
                context.lineTo(offsetX + (state.image?.naturalWidth ?? 0) * scale, band);
                context.stroke();

                if (t < 1) {
                    requestAnimationFrame(frame);
                    return;
                }

                drawMeasurements(context, measurement, toStage);
                window.setTimeout(() => fadeOverlay(context, measurement, toStage), 1400);
                resolve();
            };

            requestAnimationFrame(frame);
        });
    }

    function drawMeasurements(context, measurement, toStage) {
        const left = toStage({ x: measurement.center.x - measurement.faceWidthPx / 2, y: measurement.eyeLine.y });
        const right = toStage({ x: measurement.center.x + measurement.faceWidthPx / 2, y: measurement.eyeLine.y });

        context.clearRect(0, 0, el.stage.clientWidth, el.stage.clientHeight);

        context.strokeStyle = 'rgba(125, 211, 252, 0.9)';
        context.lineWidth = 2;
        context.setLineDash([6, 5]);
        context.beginPath();
        context.moveTo(left.x, left.y);
        context.lineTo(right.x, right.y);
        context.stroke();
        context.setLineDash([]);

        for (const point of [left, right]) {
            context.beginPath();
            context.arc(point.x, point.y, 4, 0, Math.PI * 2);
            context.fillStyle = 'rgba(125, 211, 252, 0.95)';
            context.fill();
        }
    }

    function fadeOverlay(context, measurement, toStage) {
        const start = performance.now();

        const frame = (now) => {
            const t = Math.min(1, (now - start) / 500);

            context.clearRect(0, 0, el.stage.clientWidth, el.stage.clientHeight);
            context.globalAlpha = 1 - t;
            drawMeasurements(context, measurement, toStage);
            context.globalAlpha = 1;

            if (t < 1) requestAnimationFrame(frame);
            else context.clearRect(0, 0, el.stage.clientWidth, el.stage.clientHeight);
        };

        requestAnimationFrame(frame);
    }

    // ------------------------------------------------------ size lookup API

    async function refreshRecommendation(overrideCm = null) {
        const hat = currentHat();

        const payload = overrideCm
            ? { head_circumference_cm: overrideCm }
            : {
                  interpupillary_px: state.measurement?.ipdPx,
                  face_width_px: state.measurement?.faceWidthPx,
              };

        if (!overrideCm && !payload.interpupillary_px) return;

        if (hat) payload.hat_id = hat.id;

        try {
            const response = await fetch('/api/try-on/recommend', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload),
            });

            const data = await response.json();

            if (!response.ok) {
                showFit(data.message ?? 'Could not work out a size from that photo.', 'alert-warning');
                return;
            }

            showMeasurements(data);
        } catch (error) {
            showFit('Could not reach the size service.', 'alert-warning');
        }
    }

    function showMeasurements(data) {
        el.measurements.classList.remove('hidden');
        el.circumference.textContent = `${data.head_circumference_cm} cm`;
        el.size.textContent = data.recommended_size;
        el.depth.textContent = data.skull_depth_cm ? `${data.skull_depth_cm} cm` : '—';

        el.faceWidth.textContent = state.measurement
            ? `${Math.round(state.measurement.faceWidthPx)} px`
            : 'set by hand';
        el.ipd.textContent = state.measurement ? `${Math.round(state.measurement.ipdPx)} px` : '—';

        if (data.hat_fits === null || data.hat_fits === undefined) {
            showFit(data.note, 'alert-info');
        } else {
            showFit(data.note, data.hat_fits ? 'alert-success' : 'alert-warning');
        }
    }

    function showFit(message, tone) {
        el.fit.className = `alert text-sm py-2 ${tone}`;
        el.fit.textContent = message;
    }

    // ------------------------------------------------------------- controls

    el.fileInput?.addEventListener('change', () => {
        const file = el.fileInput.files?.[0];

        if (!file) return;

        const reader = new FileReader();
        reader.onload = () => useImage(reader.result);
        reader.readAsDataURL(file);
    });

    el.cameraBtn?.addEventListener('click', async () => {
        if (state.stream) {
            stopCamera();
            return;
        }

        try {
            state.stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 1280 } },
                audio: false,
            });
        } catch (error) {
            setStatus('Camera unavailable — upload a photo instead', 'badge-warning');
            return;
        }

        el.video.srcObject = state.stream;
        el.video.classList.remove('hidden');
        el.placeholder.classList.add('hidden');
        el.captureBtn.classList.remove('hidden');
        el.cameraBtn.textContent = '✕ Stop camera';
        await el.video.play();
    });

    el.captureBtn?.addEventListener('click', () => {
        const canvas = document.createElement('canvas');

        canvas.width = el.video.videoWidth;
        canvas.height = el.video.videoHeight;
        canvas.getContext('2d').drawImage(el.video, 0, 0);

        useImage(canvas.toDataURL('image/jpeg', 0.92));
    });

    function stopCamera() {
        state.stream?.getTracks().forEach((track) => track.stop());
        state.stream = null;

        el.video.srcObject = null;
        el.video.classList.add('hidden');
        el.captureBtn.classList.add('hidden');
        el.cameraBtn.textContent = '📷 Use camera';
    }

    el.rescanBtn?.addEventListener('click', () => scan());

    el.resetBtn?.addEventListener('click', () => {
        autoPlace();
        applyPlacement();
    });

    el.manual?.addEventListener('input', () => {
        el.manualValue.textContent = `${Number(el.manual.value).toFixed(1)} cm`;
    });

    el.manualBtn?.addEventListener('click', () => refreshRecommendation(Number(el.manual.value)));

    // ----------------------------------------------------- mouse interaction

    let drag = null;

    el.stage.addEventListener('pointerdown', (event) => {
        if (!state.placement) return;

        drag = { x: event.clientX, y: event.clientY, move: event.shiftKey || event.button === 2 };
        el.stage.setPointerCapture(event.pointerId);
        el.stage.style.cursor = drag.move ? 'move' : 'grabbing';
    });

    el.stage.addEventListener('pointermove', (event) => {
        if (!drag) return;

        const dx = event.clientX - drag.x;
        const dy = event.clientY - drag.y;

        drag.x = event.clientX;
        drag.y = event.clientY;

        if (drag.move) {
            state.placement.x += dx;
            state.placement.y += dy;
        } else {
            state.placement.yaw = clamp(state.placement.yaw + dx * 0.008, -1.2, 1.2);
            state.placement.pitch = clamp(state.placement.pitch + dy * 0.006, -0.7, 0.7);
        }

        applyPlacement();
    });

    const endDrag = (event) => {
        if (!drag) return;

        drag = null;
        el.stage.style.cursor = '';
        el.stage.releasePointerCapture?.(event.pointerId);
    };

    el.stage.addEventListener('pointerup', endDrag);
    el.stage.addEventListener('pointercancel', endDrag);
    el.stage.addEventListener('contextmenu', (event) => event.preventDefault());

    el.stage.addEventListener(
        'wheel',
        (event) => {
            if (!state.placement) return;

            event.preventDefault();
            state.placement.scale = clamp(state.placement.scale * (1 - event.deltaY * 0.0012), 0.45, 2.6);
            applyPlacement();
        },
        { passive: false },
    );

    window.addEventListener('resize', () => {
        view.resize();
        autoPlace();
        applyPlacement();
        clearOverlay();
    });

    // Before there's a photo, idle-spin the hat so the shopper can see what
    // they're about to try on (and that it really is 3D).
    let idleFrame = null;

    function startIdle() {
        if (idleFrame !== null) return;

        const step = () => {
            if (state.image || !state.placement) {
                idleFrame = null;
                return;
            }

            state.placement.yaw += 0.007;
            view.place(state.placement, photoRect());
            idleFrame = requestAnimationFrame(step);
        };

        idleFrame = requestAnimationFrame(step);
    }

    // Kick things off with whatever hat is preselected.
    view.resize();
    swapHat();
    startIdle();
}

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

/**
 * The WebGL stage. The camera is set up so that one world unit equals one
 * CSS pixel on the z = 0 plane (where the photo is), which makes placing the
 * hat from face landmarks a straight coordinate conversion — while still
 * being a real perspective camera, so the hat reads as a solid object.
 */
function createScene(canvas) {
    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
    renderer.setClearColor(0x000000, 0);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(FOV, 1, 1, 20000);

    scene.add(new THREE.HemisphereLight(0xffffff, 0x1b2430, 1.35));

    const key = new THREE.DirectionalLight(0xffffff, 1.55);
    key.position.set(-400, 700, 900);
    scene.add(key);

    const rim = new THREE.DirectionalLight(0x9ec5fe, 0.7);
    rim.position.set(600, 300, -500);
    scene.add(rim);

    const pivot = new THREE.Group();
    scene.add(pivot);

    let hat = null;
    let width = 1;
    let height = 1;

    function resize() {
        const stage = canvas.parentElement;

        width = stage.clientWidth || 1;
        height = stage.clientHeight || 1;

        const ratio = Math.min(window.devicePixelRatio || 1, 2);

        renderer.setPixelRatio(ratio);
        renderer.setSize(width, height, false);

        camera.aspect = width / height;
        // Distance at which the z = 0 plane spans exactly `height` world units.
        camera.position.set(0, 0, height / (2 * Math.tan((FOV * Math.PI) / 360)));
        camera.lookAt(0, 0, 0);
        camera.updateProjectionMatrix();

        render();
    }

    function setHat(group) {
        if (hat) {
            pivot.remove(hat);
            disposeTree(hat);
        }

        hat = group;

        if (hat) pivot.add(hat);

        render();
    }

    /**
     * @param {{x:number,y:number,radius:number,yaw:number,pitch:number,roll:number,scale:number}} placement
     *   in stage CSS pixels, origin top-left
     */
    function place(placement, rect) {
        if (!hat || !placement) {
            render();
            return;
        }

        const radius = placement.radius * placement.scale;

        pivot.position.set(
            placement.x - rect.stageWidth / 2,
            rect.stageHeight / 2 - placement.y,
            // Push the hat toward the viewer by roughly the head's own depth,
            // so perspective makes it sit in front of the face, not on it.
            radius * 0.35,
        );

        pivot.scale.setScalar(radius);
        pivot.rotation.set(placement.pitch, placement.yaw, -placement.roll, 'YXZ');

        render();
    }

    function render() {
        renderer.render(scene, camera);
    }

    return { resize, setHat, place, render };
}

function disposeTree(root) {
    root.traverse((child) => {
        if (!child.isMesh) return;

        child.geometry?.dispose();

        const materials = Array.isArray(child.material) ? child.material : [child.material];
        materials.forEach((material) => material?.dispose());
    });
}
