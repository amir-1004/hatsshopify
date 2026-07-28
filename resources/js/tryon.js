// Virtual try-on: turn a shopper's photo into a 3D model of their head
// wearing the hat, which they can rotate with the mouse.
//
// Everything image-related happens on the shopper's device. Only two numbers
// (the eye distance and face width, in pixels) are POSTed to the server,
// which owns the geometry that turns them into centimetres and a size.

import * as THREE from 'three';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';
import { detectFace, measureFace } from './tryon/face.js';
import { buildHat } from './tryon/hat-model.js';
import { loadHatAsset, stylesWithAssets } from './tryon/hat-asset.js';
import { appearanceFromPhoto } from './tryon/hat-appearance.js';
import { buildHeadMesh } from './tryon/head-mesh.js';

// Portraits are shot on long lenses, and the virtual camera has to agree
// with the one that took the photo. A wide FOV looks steeply up at a head
// near the top of the frame; ~20° is an 85mm-equivalent portrait lens.
const FOV = 20;

// A single photo only knows the front of a head, so the orbit stops before
// the mesh would show its edges. (Completing the skull is a v2 job.)
const MAX_YAW = 0.72;
const MAX_PITCH = 0.42;

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
        provenance: document.getElementById('tryon-provenance'),
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
        hatFit: null, // where the hat sits relative to the head centre
        orbit: { yaw: 0, pitch: 0, zoom: 1 },
        nudge: { x: 0, y: 0 },
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

    async function swapHat() {
        const hat = currentHat();

        if (el.hatImage) {
            el.hatImage.src = hat?.image ?? '';
            el.hatImage.alt = hat ? `${hat.style} hat` : '';
        }

        if (!hat) {
            view.setHat(null);
            applyTransforms();
            return;
        }

        // Something on screen immediately, before any of the reading below.
        view.setHat(buildHat(hat.style, hat.hex));
        applyTransforms();

        // Geometry and material are resolved independently. Geometry comes
        // from a scan where one exists and procedural shapes otherwise;
        // material is always read off this hat's own product photo, which is
        // the one asset every hat is guaranteed to have. So a hat added years
        // from now still renders as the fabric it's actually made of, with no
        // per-product work.
        const [asset, appearance] = await Promise.all([
            loadHatAsset(hat.style),
            appearanceFromPhoto(hat.image),
        ]);

        // Guard against the shopper changing hats while those were loading.
        if (currentHat()?.id !== hat.id) return;

        // A scan carries its own photographed materials — don't overwrite them.
        view.setHat(asset ?? buildHat(hat.style, hat.hex, appearance));
        applyTransforms();

        markProvenance(Boolean(asset), Boolean(appearance?.patch));
    }

    /**
     * Say plainly whether this hat is a real scan or generated geometry.
     * Overstating what a preview shows is how you get returns.
     */
    function markProvenance(isScan, fromPhoto) {
        if (!el.provenance) return;

        const [label, tone] = isScan
            ? ['📷 Photoreal 3D scan', 'badge-success']
            : fromPhoto
              ? ['🧵 Fabric from product photo', 'badge-info']
              : ['⚙️ Generated 3D preview', 'badge-ghost'];

        el.provenance.textContent = label;
        el.provenance.className = `badge badge-sm ${tone}`;
    }

    el.hatSelect?.addEventListener('change', () => {
        swapHat();
        if (state.measurement) refreshRecommendation();
    });

    // -------------------------------------------------------------- layout

    /**
     * Where the photo lands inside the stage once `object-contain` has
     * letterboxed it. World units are CSS pixels, so this is also the photo's
     * size in the 3D scene.
     */
    function photoRect() {
        const { clientWidth: sw, clientHeight: sh } = el.stage;

        if (!state.image) return { scale: 1, offsetX: 0, offsetY: 0, stageWidth: sw, stageHeight: sh };

        const scale = Math.min(sw / state.image.naturalWidth, sh / state.image.naturalHeight);

        return {
            scale,
            offsetX: (sw - state.image.naturalWidth * scale) / 2,
            offsetY: (sh - state.image.naturalHeight * scale) / 2,
            worldWidth: state.image.naturalWidth * scale,
            worldHeight: state.image.naturalHeight * scale,
            stageWidth: sw,
            stageHeight: sh,
        };
    }

    /**
     * Rebuild the 3D head from the current photo + landmarks, and work out
     * where the hat sits on it.
     */
    function buildModel() {
        const { worldWidth, worldHeight } = photoRect();

        if (!state.measurement || !state.image) return;

        const head = buildHeadMesh(
            state.measurement.landmarks,
            state.image,
            worldWidth,
            worldHeight,
        );

        view.setHead(head.group, head.center);

        // Hat geometry is normalised to a crown radius of 1, and the hat sits
        // over the hair rather than against the skull, so it's a touch wider.
        state.hatFit = {
            position: head.bandOffset,
            quaternion: head.basis,
            radius: head.radius * 1.05,
        };

        applyTransforms();
    }

    function applyTransforms() {
        view.apply(state.orbit, state.hatFit, state.nudge);

        // The flat photo is only there to make the first frame seamless —
        // once the model turns, it would be a second, un-rotated head.
        const turned = Math.min(1, (Math.abs(state.orbit.yaw) + Math.abs(state.orbit.pitch)) / 0.1);
        el.photo.style.opacity = state.measurement ? String(1 - turned) : '1';
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

        try {
            await new Promise((resolve, reject) => {
                image.onload = resolve;
                image.onerror = () => reject(new Error('Could not read that image.'));
                image.src = src;
            });
        } catch (error) {
            setStatus("That file didn't load as an image", 'badge-error');
            return;
        }

        state.image = image;
        state.measurement = null;
        state.hatFit = null;
        state.orbit = { yaw: 0, pitch: 0, zoom: 1 };
        state.nudge = { x: 0, y: 0 };

        view.setHead(null);

        el.photo.src = src;
        el.photo.style.opacity = '1';
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
            return;
        }

        if (!landmarks) {
            setStatus('No face found — try a clearer, front-on photo', 'badge-warning');
            return;
        }

        state.measurement = measureFace(landmarks, state.image.naturalWidth, state.image.naturalHeight);

        setStatus('Face mapped ✓ — drag to turn your head', 'badge-success');
        window.setTimeout(() => setStatus(null), 3200);

        buildModel();

        // The scan flourish is decoration, so it runs alongside the result:
        // requestAnimationFrame doesn't fire in a background tab, and a
        // shopper who switched tabs mid-scan should still get their model.
        runScanAnimation(state.measurement);

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
     * landmark cloud as it passes, then fade it out.
     */
    function runScanAnimation(measurement) {
        const context = sizeOverlay();
        const { scale, offsetX, offsetY } = photoRect();

        const points = measurement.points.map((point) => ({
            x: offsetX + point.x * scale,
            y: offsetY + point.y * scale,
        }));

        const ys = points.map((point) => point.y);
        const from = Math.min(...ys) - 20;
        const to = Math.max(...ys) + 20;

        const duration = 1100;
        const start = performance.now();

        const frame = (now) => {
            const t = Math.min(1, (now - start) / duration);
            const band = from + (to - from) * t;
            const fade = t > 0.85 ? 1 - (t - 0.85) / 0.15 : 1;

            context.clearRect(0, 0, el.stage.clientWidth, el.stage.clientHeight);

            for (const point of points) {
                const lit = Math.max(0, 1 - Math.abs(point.y - band) / 90);

                context.beginPath();
                context.arc(point.x, point.y, 0.7 + lit * 1.8, 0, Math.PI * 2);
                context.fillStyle = `rgba(125, 211, 252, ${(0.18 + lit * 0.8) * fade})`;
                context.fill();
            }

            if (t < 1) {
                requestAnimationFrame(frame);
                return;
            }

            context.clearRect(0, 0, el.stage.clientWidth, el.stage.clientHeight);
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

        showFit(
            data.note,
            data.hat_fits === null || data.hat_fits === undefined
                ? 'alert-info'
                : data.hat_fits ? 'alert-success' : 'alert-warning',
        );
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
        state.orbit = { yaw: 0, pitch: 0, zoom: 1 };
        state.nudge = { x: 0, y: 0 };
        applyTransforms();
    });

    el.manual?.addEventListener('input', () => {
        el.manualValue.textContent = `${Number(el.manual.value).toFixed(1)} cm`;
    });

    el.manualBtn?.addEventListener('click', () => refreshRecommendation(Number(el.manual.value)));

    // ----------------------------------------------------- mouse interaction

    let drag = null;

    el.stage.addEventListener('pointerdown', (event) => {
        drag = { x: event.clientX, y: event.clientY, nudge: event.shiftKey || event.button === 2 };
        el.stage.setPointerCapture(event.pointerId);
        el.stage.style.cursor = drag.nudge ? 'move' : 'grabbing';
    });

    el.stage.addEventListener('pointermove', (event) => {
        if (!drag) return;

        const dx = event.clientX - drag.x;
        const dy = event.clientY - drag.y;

        drag.x = event.clientX;
        drag.y = event.clientY;

        if (drag.nudge) {
            state.nudge.x += dx;
            state.nudge.y -= dy;
        } else {
            state.orbit.yaw = clamp(state.orbit.yaw + dx * 0.006, -MAX_YAW, MAX_YAW);
            state.orbit.pitch = clamp(state.orbit.pitch + dy * 0.005, -MAX_PITCH, MAX_PITCH);
        }

        applyTransforms();
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
            event.preventDefault();
            state.orbit.zoom = clamp(state.orbit.zoom * (1 - event.deltaY * 0.0012), 0.6, 2.4);
            applyTransforms();
        },
        { passive: false },
    );

    window.addEventListener('resize', () => {
        view.resize();
        if (state.measurement) buildModel();
        clearOverlay();
    });

    // Before there's a photo, idle-spin the hat on its own so the shopper can
    // see what they're about to try on.
    let idleFrame = null;

    function startIdle() {
        if (idleFrame !== null) return;

        const step = () => {
            if (state.image) {
                idleFrame = null;
                view.setPreviewSpin(null);
                return;
            }

            view.setPreviewSpin(performance.now() / 1400);
            idleFrame = requestAnimationFrame(step);
        };

        idleFrame = requestAnimationFrame(step);
    }

    view.resize();
    startIdle();

    // Unless the shopper asked for a specific hat, open on one we can render
    // from a real scan rather than generated geometry — first impressions of
    // a try-on are the whole product.
    (async () => {
        if (!el.hatSelect?.dataset.preselected) {
            const scanned = await stylesWithAssets();
            const best = [...(el.hatSelect?.options ?? [])].find((option) =>
                scanned.includes(option.dataset.style),
            );

            if (best) el.hatSelect.value = best.value;
        }

        swapHat();
    })();
}

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

/**
 * The WebGL stage.
 *
 * One world unit is one CSS pixel on the z = 0 plane, so landmarks convert to
 * scene coordinates directly. The head mesh and the hat live in a single
 * group that rotates together — turning the model turns both.
 */
function createScene(canvas) {
    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
    renderer.setClearColor(0x000000, 0);

    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(FOV, 1, 1, 40000);

    // Fabric only looks like fabric when there's a room for it to reflect.
    // RoomEnvironment ships with three, so this costs no download.
    const pmrem = new THREE.PMREMGenerator(renderer);
    scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;

    scene.add(new THREE.HemisphereLight(0xffffff, 0x1b2430, 0.45));

    const key = new THREE.DirectionalLight(0xffffff, 0.75);
    key.position.set(-400, 700, 900);
    scene.add(key);

    const rim = new THREE.DirectionalLight(0x9ec5fe, 0.3);
    rim.position.set(600, 300, -500);
    scene.add(rim);

    // Everything that belongs to "the shopper's head" — mesh and hat.
    const headGroup = new THREE.Group();
    scene.add(headGroup);

    const hatPivot = new THREE.Group();
    headGroup.add(hatPivot);

    let head = null;
    let hat = null;
    let width = 1;
    let height = 1;
    let previewSpin = null;

    const ORIGIN = new THREE.Vector3(0, 0, 0);
    const anchor = new THREE.Vector3(); // where the head sits in the photo

    // How far the model has been turned away from the photo, 0..1 — the same
    // ramp the flat photo fades on.
    const turnedFraction = (orbit) =>
        Math.min(1, (Math.abs(orbit.yaw) + Math.abs(orbit.pitch)) / 0.1);

    function resize() {
        const stage = canvas.parentElement;

        width = stage.clientWidth || 1;
        height = stage.clientHeight || 1;

        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setSize(width, height, false);

        camera.aspect = width / height;
        // Distance at which the z = 0 plane spans exactly `height` world units.
        camera.position.set(0, 0, height / (2 * Math.tan((FOV * Math.PI) / 360)));
        camera.lookAt(0, 0, 0);
        camera.updateProjectionMatrix();

        render();
    }

    function setHead(mesh, center) {
        if (head) {
            headGroup.remove(head);
            disposeTree(head);
        }

        head = mesh;

        if (head) {
            headGroup.add(head);
            anchor.copy(center);
        } else {
            anchor.set(0, 0, 0);
        }

        headGroup.position.copy(anchor);

        render();
    }

    function setHat(group) {
        if (hat) {
            hatPivot.remove(hat);
            disposeTree(hat);
        }

        hat = group;

        if (hat) hatPivot.add(hat);

        render();
    }

    /**
     * @param {{yaw:number,pitch:number,zoom:number}} orbit  applied to head + hat together
     * @param {?{position:THREE.Vector3,quaternion:THREE.Quaternion,radius:number}} fit
     * @param {{x:number,y:number}} nudge manual hat offset, in CSS pixels
     */
    function apply(orbit, fit, nudge) {
        headGroup.rotation.set(orbit.pitch, orbit.yaw, 0, 'YXZ');
        headGroup.scale.setScalar(orbit.zoom);

        // While the flat photo is still showing, the head has to stay exactly
        // where it is in that photo. Once the model turns and the photo goes,
        // there's nothing to line up with — so it drifts to the middle of the
        // stage, where there's room to zoom into it.
        headGroup.position.copy(anchor).lerp(ORIGIN, turnedFraction(orbit));

        if (hat) {
            if (fit) {
                hatPivot.position.copy(fit.position).add(new THREE.Vector3(nudge.x, nudge.y, 0));
                hatPivot.scale.setScalar(fit.radius);
                hatPivot.quaternion.copy(fit.quaternion);
            } else {
                // No face yet: park the hat mid-stage as a preview.
                const radius = Math.min(width, height) * 0.2;

                hatPivot.position.set(0, height * 0.08, 0);
                hatPivot.scale.setScalar(radius);
                hatPivot.rotation.set(0.12, previewSpin ?? 0, 0, 'YXZ');
            }
        }

        render();
    }

    function setPreviewSpin(angle) {
        previewSpin = angle;

        if (!head && hat) {
            hatPivot.rotation.y = angle ?? 0;
            render();
        }
    }

    function render() {
        renderer.render(scene, camera);
    }

    return { resize, setHead, setHat, apply, setPreviewSpin, render };
}

function disposeTree(root) {
    root.traverse((child) => {
        if (!child.isMesh) return;

        child.geometry?.dispose();

        const materials = Array.isArray(child.material) ? child.material : [child.material];
        materials.forEach((material) => {
            material?.map?.dispose();
            material?.dispose();
        });
    });
}
