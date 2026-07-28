// Where a hat's 3D appearance comes from.
//
// Hand-authoring a scanned model per product doesn't scale — a shop adds hats
// forever, and nobody is going to photogrammetry-scan each one. But every hat
// is already guaranteed a product photo (`hats.image_url` is NOT NULL and the
// API rejects blanks), so the photo is the one asset we can always count on.
//
// So instead of inventing a colour and a weave, this reads the real thing off
// the merchant's own photograph: the true fabric colour, a tileable patch of
// the actual cloth, and a normal map derived from that patch's own shading.
// The result is that a tweed cap looks like tweed and a mesh trucker looks
// like mesh, with no per-product work — and the better the merchant's
// photography gets, the better the 3D preview gets. That's the right
// incentive for a hat shop to have.

import * as THREE from 'three';

/** Analysis runs on a downscale — we're after material, not detail. */
const ANALYSIS_SIZE = 256;

/** Size of the cloth patch lifted out of the photo, before mirroring. */
const PATCH_SIZE = 96;

const cache = new Map();

/**
 * Read a hat's material off its product photo.
 *
 * Never throws and never blocks a render: a cross-origin photo that taints
 * the canvas, a 404, or a picture with no recognisable hat in it all resolve
 * to null, and the caller falls back to the flat product colour.
 *
 * Returns raw canvases rather than textures so the caller can composite them
 * — the cap crown needs the cloth and its panel seams in one map.
 *
 * @param {string} url the hat's image_url
 * @returns {Promise<?{color: THREE.Color, patch: ?HTMLCanvasElement, normal: ?HTMLCanvasElement}>}
 */
export async function appearanceFromPhoto(url) {
    if (!url) return null;
    if (cache.has(url)) return cache.get(url);

    const result = await derive(url).catch(() => null);

    cache.set(url, result);

    return result;
}

async function derive(url) {
    const image = await loadImage(url);
    const pixels = analysisPixels(image);

    if (!pixels) return null;

    const { data, width, height } = pixels;
    const background = backgroundColor(data, width, height);
    const mask = hatMask(data, background);

    // A photo where almost nothing differs from the corners is a logo, a
    // blank, or a picture of something that isn't a hat.
    const coverage = mask.reduce((sum, on) => sum + on, 0) / mask.length;
    if (coverage < 0.04) return null;

    const color = medianColor(data, mask);
    const patch = bestPatch(image, mask, width, height);

    if (!patch) return { color, patch: null, normal: null };

    const tile = mirrorTile(patch);

    return { color, patch: tile, normal: normalFromHeight(tile) };
}

function loadImage(url) {
    return new Promise((resolve, reject) => {
        const image = new Image();

        // Same-origin for uploads (/design-files/…) and generated art
        // (/hat-art/…); this only matters for a merchant's external CDN.
        image.crossOrigin = 'anonymous';
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error(`Could not load ${url}`));
        image.src = url;
    });
}

function analysisPixels(image) {
    try {
        const canvas = document.createElement('canvas');

        canvas.width = ANALYSIS_SIZE;
        canvas.height = ANALYSIS_SIZE;

        const context = canvas.getContext('2d', { willReadFrequently: true });
        context.drawImage(image, 0, 0, ANALYSIS_SIZE, ANALYSIS_SIZE);

        const { data } = context.getImageData(0, 0, ANALYSIS_SIZE, ANALYSIS_SIZE);

        return { data, width: ANALYSIS_SIZE, height: ANALYSIS_SIZE };
    } catch (error) {
        return null; // tainted canvas — cross-origin photo without CORS
    }
}

/** Product shots are shot on a backdrop, and the corners are that backdrop. */
function backgroundColor(data, width, height) {
    const corners = [
        [4, 4],
        [width - 5, 4],
        [4, height - 5],
        [width - 5, height - 5],
    ];

    const channels = [0, 0, 0];

    for (const [x, y] of corners) {
        const i = (y * width + x) * 4;

        channels[0] += data[i];
        channels[1] += data[i + 1];
        channels[2] += data[i + 2];
    }

    return channels.map((c) => c / corners.length);
}

/** 1 where the pixel looks like hat rather than backdrop. */
function hatMask(data, background) {
    const mask = new Uint8Array(data.length / 4);

    for (let p = 0; p < mask.length; p += 1) {
        const i = p * 4;

        if (data[i + 3] < 40) continue; // transparent

        const distance =
            Math.abs(data[i] - background[0]) +
            Math.abs(data[i + 1] - background[1]) +
            Math.abs(data[i + 2] - background[2]);

        mask[p] = distance > 60 ? 1 : 0;
    }

    return mask;
}

/**
 * Median rather than mean: a mean is dragged around by specular highlights
 * and shadow, a median lands on the cloth's actual colour.
 */
function medianColor(data, mask) {
    const channels = [[], [], []];

    for (let p = 0; p < mask.length; p += 1) {
        if (!mask[p]) continue;

        const i = p * 4;

        channels[0].push(data[i]);
        channels[1].push(data[i + 1]);
        channels[2].push(data[i + 2]);
    }

    const median = (values) => {
        values.sort((a, b) => a - b);

        return values[Math.floor(values.length / 2)] / 255;
    };

    return new THREE.Color(median(channels[0]), median(channels[1]), median(channels[2]));
}

/**
 * Find the squarest, most solidly-hat window in the photo and cut it out at
 * full resolution — that's the cleanest look at the cloth the photo offers.
 */
function bestPatch(image, mask, width, height) {
    const window = 32; // in analysis pixels
    const step = 8;

    let best = null;
    let bestScore = 0;

    for (let y = 0; y + window < height; y += step) {
        for (let x = 0; x + window < width; x += step) {
            let filled = 0;

            for (let dy = 0; dy < window; dy += 2) {
                for (let dx = 0; dx < window; dx += 2) {
                    filled += mask[(y + dy) * width + (x + dx)];
                }
            }

            if (filled > bestScore) {
                bestScore = filled;
                best = { x, y };
            }
        }
    }

    // Require the window to be almost entirely hat, or we'd be sampling an edge.
    if (!best || bestScore < (window / 2) * (window / 2) * 0.92) return null;

    const scaleX = image.naturalWidth / width;
    const scaleY = image.naturalHeight / height;

    const canvas = document.createElement('canvas');
    canvas.width = PATCH_SIZE;
    canvas.height = PATCH_SIZE;

    canvas.getContext('2d').drawImage(
        image,
        best.x * scaleX,
        best.y * scaleY,
        window * scaleX,
        window * scaleY,
        0,
        0,
        PATCH_SIZE,
        PATCH_SIZE,
    );

    return canvas;
}

/**
 * Mirror the patch into a 2x2 block so its edges match when tiled. Cheap, and
 * for cloth the symmetry it introduces reads as weave rather than as a seam.
 */
function mirrorTile(patch) {
    const size = patch.width;
    const canvas = document.createElement('canvas');

    canvas.width = size * 2;
    canvas.height = size * 2;

    const context = canvas.getContext('2d');

    context.drawImage(patch, 0, 0);

    context.save();
    context.scale(-1, 1);
    context.drawImage(patch, -size * 2, 0);
    context.restore();

    context.save();
    context.scale(1, -1);
    context.drawImage(patch, 0, -size * 2);
    context.restore();

    context.save();
    context.scale(-1, -1);
    context.drawImage(patch, -size * 2, -size * 2);
    context.restore();

    return canvas;
}

/**
 * Treat the patch's own brightness as height and differentiate it (Sobel) to
 * get a normal map. The photo's shading already encodes the weave, so this
 * recovers surface relief no synthetic pattern would match.
 */
function normalFromHeight(source) {
    const size = source.width;
    const read = source.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, size, size).data;

    const height = new Float32Array(size * size);
    for (let p = 0; p < height.length; p += 1) {
        const i = p * 4;

        height[p] = (read[i] * 0.299 + read[i + 1] * 0.587 + read[i + 2] * 0.114) / 255;
    }

    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;

    const context = canvas.getContext('2d');
    const out = context.createImageData(size, size);

    const at = (x, y) => height[((y + size) % size) * size + ((x + size) % size)];
    const strength = 2.2;

    for (let y = 0; y < size; y += 1) {
        for (let x = 0; x < size; x += 1) {
            const dx =
                at(x - 1, y - 1) + 2 * at(x - 1, y) + at(x - 1, y + 1) -
                (at(x + 1, y - 1) + 2 * at(x + 1, y) + at(x + 1, y + 1));
            const dy =
                at(x - 1, y - 1) + 2 * at(x, y - 1) + at(x + 1, y - 1) -
                (at(x - 1, y + 1) + 2 * at(x, y + 1) + at(x + 1, y + 1));

            const nx = dx * strength;
            const ny = dy * strength;
            const length = Math.hypot(nx, ny, 1);

            const i = (y * size + x) * 4;
            out.data[i] = ((nx / length) * 0.5 + 0.5) * 255;
            out.data[i + 1] = ((ny / length) * 0.5 + 0.5) * 255;
            out.data[i + 2] = (1 / length) * 255;
            out.data[i + 3] = 255;
        }
    }

    context.putImageData(out, 0, 0);

    return canvas;
}

