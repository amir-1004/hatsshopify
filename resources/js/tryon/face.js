// Face landmark detection for the virtual try-on.
//
// MediaPipe's FaceLandmarker runs entirely in the browser (WASM + WebGL),
// which is the whole point: the shopper's photo is measured on their own
// device and never uploaded. The library and its model are pulled from the
// CDN on first use so they don't bloat the app bundle.

const VERSION = '0.10.18';
const MODULE_URL = `https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@${VERSION}/vision_bundle.mjs`;
const WASM_URL = `https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@${VERSION}/wasm`;
const MODEL_URL =
    'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task';

/**
 * Landmark indices in MediaPipe's 478-point face mesh.
 */
const IDX = {
    faceRight: 234, // right edge of the face oval (viewer's left)
    faceLeft: 454,
    foreheadTop: 10,
    chin: 152,
    noseTip: 1,
    rightIris: 468, // iris centres — only present on the 478-point model
    leftIris: 473,
    rightEyeOuter: 33,
    rightEyeInner: 133,
    leftEyeInner: 362,
    leftEyeOuter: 263,
};

let landmarkerPromise = null;

/**
 * Load (once) and cache the FaceLandmarker. Tries the GPU delegate first
 * and falls back to CPU on machines where WebGL compute isn't available.
 */
export function loadFaceLandmarker() {
    if (landmarkerPromise) return landmarkerPromise;

    landmarkerPromise = (async () => {
        const vision = await import(/* @vite-ignore */ MODULE_URL);
        const fileset = await vision.FilesetResolver.forVisionTasks(WASM_URL);

        const options = (delegate) => ({
            baseOptions: { modelAssetPath: MODEL_URL, delegate },
            runningMode: 'IMAGE',
            numFaces: 1,
        });

        try {
            return await vision.FaceLandmarker.createFromOptions(fileset, options('GPU'));
        } catch (error) {
            return await vision.FaceLandmarker.createFromOptions(fileset, options('CPU'));
        }
    })().catch((error) => {
        landmarkerPromise = null; // let the next attempt retry the download
        throw error;
    });

    return landmarkerPromise;
}

/**
 * Detect a single face in an <img>/<canvas>. Returns the raw normalised
 * landmarks, or null when no face is found.
 */
export async function detectFace(source) {
    const landmarker = await loadFaceLandmarker();
    const result = landmarker.detect(source);

    return result?.faceLandmarks?.[0] ?? null;
}

const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

/**
 * Convert normalised landmarks into pixel measurements and the anchor
 * points the 3D hat is placed from.
 *
 * @param {Array<{x:number,y:number,z:number}>} landmarks
 * @param {number} width  natural pixel width of the photo
 * @param {number} height natural pixel height of the photo
 */
export function measureFace(landmarks, width, height) {
    const at = (index) => ({
        x: landmarks[index].x * width,
        y: landmarks[index].y * height,
        z: (landmarks[index].z ?? 0) * width,
    });

    const midpoint = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });

    const left = at(IDX.faceLeft);
    const right = at(IDX.faceRight);
    const top = at(IDX.foreheadTop);
    const chin = at(IDX.chin);
    const nose = at(IDX.noseTip);

    // Pupils where the model gives us irises, eye-corner midpoints otherwise.
    const hasIris = landmarks.length > IDX.leftIris;
    const rightEye = hasIris ? at(IDX.rightIris) : midpoint(at(IDX.rightEyeOuter), at(IDX.rightEyeInner));
    const leftEye = hasIris ? at(IDX.leftIris) : midpoint(at(IDX.leftEyeInner), at(IDX.leftEyeOuter));

    const faceWidthPx = distance(left, right);
    const faceHeightPx = distance(top, chin);
    const ipdPx = distance(leftEye, rightEye);

    const center = midpoint(left, right);

    // Head roll straight off the eye line.
    const roll = Math.atan2(leftEye.y - rightEye.y, leftEye.x - rightEye.x);

    // Yaw: how far the nose has drifted from the centre of the face oval.
    const offset = Math.max(-1, Math.min(1, (nose.x - center.x) / (faceWidthPx / 2 || 1)));
    const yaw = Math.asin(offset) * 0.75;

    // Pitch: the eye line sits lower in the face when the head tilts back.
    const eyeLine = midpoint(leftEye, rightEye);
    const eyeRatio = faceHeightPx > 0 ? (eyeLine.y - top.y) / faceHeightPx : 0.5;
    const pitch = Math.max(-0.5, Math.min(0.5, (eyeRatio - 0.45) * 1.6));

    return {
        faceWidthPx,
        faceHeightPx,
        ipdPx,
        center,
        top,
        chin,
        eyeLine,
        roll,
        yaw,
        pitch,
        // Every point, in photo pixels — used for the scan animation.
        points: landmarks.map((point) => ({ x: point.x * width, y: point.y * height })),
    };
}

export { IDX };
