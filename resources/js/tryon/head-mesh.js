// Turns a face into an actual 3D object.
//
// The landmarks MediaPipe returns are already 3D — x, y in image space and a
// z depth relative to the centre of the head. Triangulate them and project
// the shopper's own photo on as the texture and you get a real mesh of their
// face that can be rotated, rather than a photo with a hat drawn on it.
// (Same technique as PyFace3D and Babylon's facecap.)
//
// A photo only contains a face, though, and a face on its own is a mask: a
// hat sized for a head floats above it with nothing to sit on. So the face
// mesh is mounted on a skull — an ellipsoid fitted to the same landmarks and
// painted the shopper's hair colour, sampled from their photo. It's mostly
// hidden behind the face and under the hat; its job is to give the head
// volume, and the hat something to rest on.

import * as THREE from 'three';
import Delaunator from 'delaunator';

/**
 * The iris points (468-477) sit *inside* the eyes. They're perfect for
 * measuring and terrible for triangulating, so the mesh uses the 468
 * surface landmarks only.
 */
const SURFACE_POINT_COUNT = 468;

/**
 * MediaPipe's face-oval ring, in order. Triangles whose centre falls outside
 * this polygon are dropped — Delaunay fills the convex hull, which would
 * otherwise web the neck and temples over with skin.
 */
const FACE_OVAL = [
    10, 338, 297, 332, 284, 251, 389, 356, 454, 323, 361, 288, 397, 365, 379,
    378, 400, 377, 152, 148, 176, 149, 150, 136, 172, 58, 132, 93, 234, 127,
    162, 21, 54, 103, 67, 109,
];

const LANDMARK = {
    faceRight: 234,
    faceLeft: 454,
    foreheadTop: 10,
    chin: 152,
    templeRight: 54,
    templeLeft: 284,
};

/** MediaPipe says z "uses roughly the same scale as x". */
const DEPTH_SCALE = 1.0;

function pointInPolygon(x, y, polygon) {
    let inside = false;

    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i, i += 1) {
        const [xi, yi] = polygon[i];
        const [xj, yj] = polygon[j];

        if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) {
            inside = !inside;
        }
    }

    return inside;
}

/**
 * Average colour of the photo at a few normalised points — used to paint the
 * skull the shopper's own hair colour instead of an arbitrary grey.
 */
function sampleColor(image, samples) {
    try {
        const canvas = document.createElement('canvas');
        const size = 160;

        canvas.width = size;
        canvas.height = size;

        const context = canvas.getContext('2d', { willReadFrequently: true });
        context.drawImage(image, 0, 0, size, size);

        let r = 0;
        let g = 0;
        let b = 0;
        let taken = 0;

        for (const [nx, ny] of samples) {
            if (nx < 0 || nx > 1 || ny < 0 || ny > 1) continue;

            const pixel = context.getImageData(
                Math.min(size - 1, Math.max(0, Math.round(nx * size))),
                Math.min(size - 1, Math.max(0, Math.round(ny * size))),
                1,
                1,
            ).data;

            r += pixel[0];
            g += pixel[1];
            b += pixel[2];
            taken += 1;
        }

        if (!taken) return new THREE.Color(0x3a3a3a);

        return new THREE.Color(`rgb(${Math.round(r / taken)}, ${Math.round(g / taken)}, ${Math.round(b / taken)})`);
    } catch (error) {
        // A cross-origin photo taints the canvas; a neutral scalp is fine.
        return new THREE.Color(0x3a3a3a);
    }
}

/**
 * Build the shopper's head: a photo-textured face mesh mounted on a fitted
 * skull, centred on the origin so it can be rotated in place.
 *
 * @param {Array<{x:number,y:number,z:number}>} landmarks normalised, from MediaPipe
 * @param {HTMLImageElement} image the shopper's photo
 * @param {number} worldWidth  width of the photo in world units
 * @param {number} worldHeight height of the photo in world units
 * @returns {{group: THREE.Group, center: THREE.Vector3, radius: number, basis: THREE.Quaternion, bandOffset: THREE.Vector3}}
 */
export function buildHeadMesh(landmarks, image, worldWidth, worldHeight) {
    const points = landmarks.slice(0, SURFACE_POINT_COUNT);

    // World position of each landmark, with the photo centred on the origin.
    const world = points.map((point) => new THREE.Vector3(
        (point.x - 0.5) * worldWidth,
        (0.5 - point.y) * worldHeight,
        -(point.z ?? 0) * worldWidth * DEPTH_SCALE,
    ));

    // Centre on the middle of the face oval so rotation pivots on the head.
    const center = new THREE.Vector3();
    FACE_OVAL.forEach((index) => center.add(world[index]));
    center.divideScalar(FACE_OVAL.length);

    // ------------------------------------------------------ the face mesh

    const positions = new Float32Array(points.length * 3);
    const uvs = new Float32Array(points.length * 2);

    points.forEach((point, i) => {
        positions[i * 3] = world[i].x - center.x;
        positions[i * 3 + 1] = world[i].y - center.y;
        positions[i * 3 + 2] = world[i].z - center.z;

        // The landmarks are normalised image coordinates, so they double as
        // texture coordinates — the photo lands exactly where it was taken
        // from. (v is flipped: three.js uploads textures bottom-up.)
        uvs[i * 2] = point.x;
        uvs[i * 2 + 1] = 1 - point.y;
    });

    const flat = [];
    points.forEach((point) => flat.push(point.x, point.y));

    const delaunay = new Delaunator(Float64Array.from(flat));
    const oval2d = FACE_OVAL.map((index) => [points[index].x, points[index].y]);

    const indices = [];
    for (let t = 0; t < delaunay.triangles.length; t += 3) {
        const [a, b, c] = [delaunay.triangles[t], delaunay.triangles[t + 1], delaunay.triangles[t + 2]];

        const cx = (points[a].x + points[b].x + points[c].x) / 3;
        const cy = (points[a].y + points[b].y + points[c].y) / 3;

        if (pointInPolygon(cx, cy, oval2d)) indices.push(a, b, c);
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();

    const texture = new THREE.Texture(image);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.needsUpdate = true;

    // Unlit: the photo already has this person's real lighting baked in, and
    // relighting it would only fight the original exposure.
    const face = new THREE.Mesh(
        geometry,
        new THREE.MeshBasicMaterial({ map: texture, side: THREE.DoubleSide }),
    );

    // ------------------------------------------------- the head's own axes

    const right = world[LANDMARK.faceLeft].clone().sub(world[LANDMARK.faceRight]);
    const up = world[LANDMARK.foreheadTop].clone().sub(world[LANDMARK.chin]);

    const headWidth = right.length();

    right.normalize();
    up.normalize();

    // Forward comes out of the face; flip it if the cross product points away
    // from the camera.
    const forward = new THREE.Vector3().crossVectors(right, up).normalize();
    if (forward.z < 0) forward.negate();

    // Re-orthogonalise so the basis is clean even on an odd head pose.
    up.crossVectors(forward, right).normalize();

    const basis = new THREE.Quaternion().setFromRotationMatrix(
        new THREE.Matrix4().makeBasis(right, up, forward),
    );

    // ---------------------------------------------------------- the skull

    // Skull breadth mirrors HatSizingService.FACE_TO_SKULL_WIDTH, and depth
    // follows the same cephalic index the server sizes with.
    const radius = (headWidth * 1.12) / 2;

    const hair = sampleColor(image, [
        // Just above the hairline, and both temples.
        [points[LANDMARK.foreheadTop].x, Math.max(0, points[LANDMARK.foreheadTop].y - 0.05)],
        [points[LANDMARK.templeRight].x, Math.max(0, points[LANDMARK.templeRight].y - 0.03)],
        [points[LANDMARK.templeLeft].x, Math.max(0, points[LANDMARK.templeLeft].y - 0.03)],
    ]);

    const skull = new THREE.Mesh(
        new THREE.SphereGeometry(1, 48, 36),
        new THREE.MeshStandardMaterial({ color: hair, roughness: 0.95, metalness: 0 }),
    );

    skull.scale.set(radius, radius * 1.22, radius * 1.05);
    skull.quaternion.copy(basis);

    // The skull goes *behind* the face, not around it: its front surface is
    // parked on the plane of the face oval, so every forward-facing part of
    // the photo mesh — nose, cheeks, forehead — still renders in front of it,
    // and only the parts that curve away toward the ears get covered.
    const skullCenter = new THREE.Vector3()
        .addScaledVector(forward, -radius * 1.03)
        .addScaledVector(up, radius * 0.14);

    skull.position.copy(skullCenter);

    const group = new THREE.Group();
    group.add(skull);
    group.add(face);

    // Where a hat's band belongs: up the skull from its centre, in head space.
    const bandOffset = skullCenter.clone().addScaledVector(up, radius * 0.62);

    return { group, center, radius, basis, bandOffset };
}

export { FACE_OVAL, SURFACE_POINT_COUNT };
