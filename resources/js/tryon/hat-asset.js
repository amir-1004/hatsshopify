// Photoreal hat models, when we have them.
//
// The procedural hats in hat-model.js are honest geometry but they read as
// CG. Where a real model exists — authored once from the hat's own product
// photo by an image-to-3D model, then committed — it's loaded instead.
//
// Nothing here is required: a missing model, a failed fetch or a corrupt file
// all fall back to the procedural hat, so the try-on never breaks because an
// asset didn't ship.

import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';

const MANIFEST_URL = '/models/hats/manifest.json';

let manifestPromise = null;
const modelCache = new Map();

/**
 * Per-style calibration, so aligning a downloaded model is data rather than
 * code. Generated meshes come out in arbitrary orientations and scales; the
 * manifest says how to get each one into our convention.
 *
 * {
 *   "Baseball": {
 *     "file": "baseball.glb",
 *     "rotation": [0, 3.14159, 0],   // radians, applied first
 *     "scale": 1.0,                  // extra scale after normalisation
 *     "offset": [0, 0, 0]            // in crown radii, applied last
 *   }
 * }
 */
function loadManifest() {
    if (manifestPromise) return manifestPromise;

    manifestPromise = fetch(MANIFEST_URL, { headers: { Accept: 'application/json' } })
        .then((response) => (response.ok ? response.json() : {}))
        .catch(() => ({}));

    return manifestPromise;
}

/**
 * Rescale and recentre a loaded model into the convention the try-on expects:
 * crown radius 1, band sitting on y = 0, bill pointing along +z.
 */
function normalise(root, entry) {
    const group = new THREE.Group();

    if (entry.rotation) root.rotation.set(...entry.rotation);

    group.add(root);

    const box = new THREE.Box3().setFromObject(group);
    const size = new THREE.Vector3();
    const center = new THREE.Vector3();

    box.getSize(size);
    box.getCenter(center);

    // Width is the reliable dimension to normalise on: a bill makes depth
    // vary wildly between hat styles, and height varies with the crown.
    const scale = (size.x > 0 ? 2 / size.x : 1) * (entry.scale ?? 1);

    root.position.sub(center);
    group.scale.setScalar(scale);

    // Drop the band onto y = 0 — the model is positioned by its band, and
    // half the height is a good enough proxy for where that sits.
    const scaled = new THREE.Box3().setFromObject(group);
    group.position.y = -scaled.min.y;

    if (entry.offset) {
        group.position.x += entry.offset[0] ?? 0;
        group.position.y += entry.offset[1] ?? 0;
        group.position.z += entry.offset[2] ?? 0;
    }

    const wrapper = new THREE.Group();
    wrapper.add(group);

    return wrapper;
}

/**
 * A photoreal hat for this style, or null if we don't have one.
 *
 * @param {string} style one of the catalog styles
 * @returns {Promise<THREE.Object3D|null>}
 */
export async function loadHatAsset(style) {
    if (modelCache.has(style)) {
        const cached = modelCache.get(style);

        return cached ? cached.clone(true) : null;
    }

    let model = null;

    try {
        const manifest = await loadManifest();
        const entry = manifest[style];

        if (entry?.file) {
            const gltf = await new GLTFLoader().loadAsync(`/models/hats/${entry.file}`);

            model = normalise(gltf.scene, entry);
        }
    } catch (error) {
        model = null; // fall back to procedural geometry
    }

    modelCache.set(style, model);

    return model ? model.clone(true) : null;
}

/**
 * Which styles have a real scanned model behind them, as opposed to the
 * procedural geometry. Used to open the try-on on a product we can render
 * photorealistically, and to say so honestly in the UI.
 *
 * @returns {Promise<string[]>}
 */
export async function stylesWithAssets() {
    const manifest = await loadManifest();

    return Object.entries(manifest)
        .filter(([style, entry]) => !style.startsWith('_') && entry?.file)
        .map(([style]) => style);
}
