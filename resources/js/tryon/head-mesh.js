// Turns a face into an actual 3D object.
//
// The landmarks MediaPipe returns are already 3D — x, y in image space and a
// z depth relative to the centre of the head. Triangulate them and project
// the shopper's own photo on as a texture and you get a real mesh of their
// face that can be rotated, rather than a photo with a hat drawn on it.
// (Same technique as PyFace3D and Babylon's facecap.)

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
 * Build a textured mesh of the face, centred on the head so it can be
 * rotated in place.
 *
 * @param {Array<{x:number,y:number,z:number}>} landmarks normalised, from MediaPipe
 * @param {HTMLImageElement} image the shopper's photo
 * @param {number} worldWidth  width of the photo in world units
 * @param {number} worldHeight height of the photo in world units
 * @returns {{mesh: THREE.Mesh, center: THREE.Vector3, radius: number}}
 */
export function buildHeadMesh(landmarks, image, worldWidth, worldHeight) {
    const points = landmarks.slice(0, SURFACE_POINT_COUNT);

    // World position of each landmark, with the photo centred on the origin.
    const toWorld = (point) => [
        (point.x - 0.5) * worldWidth,
        (0.5 - point.y) * worldHeight,
        -(point.z ?? 0) * worldWidth * DEPTH_SCALE,
    ];

    const world = points.map(toWorld);

    // Centre on the middle of the face oval so rotation pivots on the head.
    const ovalPoints = FACE_OVAL.map((index) => world[index]);
    const center = new THREE.Vector3(
        ovalPoints.reduce((sum, p) => sum + p[0], 0) / ovalPoints.length,
        ovalPoints.reduce((sum, p) => sum + p[1], 0) / ovalPoints.length,
        ovalPoints.reduce((sum, p) => sum + p[2], 0) / ovalPoints.length,
    );

    const positions = new Float32Array(points.length * 3);
    const uvs = new Float32Array(points.length * 2);

    points.forEach((point, i) => {
        positions[i * 3] = world[i][0] - center.x;
        positions[i * 3 + 1] = world[i][1] - center.y;
        positions[i * 3 + 2] = world[i][2] - center.z;

        // The landmarks are normalised image coordinates, so they double as
        // texture coordinates — the photo lands exactly where it was taken
        // from. (v is flipped: three.js uploads textures bottom-up.)
        uvs[i * 2] = point.x;
        uvs[i * 2 + 1] = 1 - point.y;
    });

    // Triangulate in 2D image space, then lift onto the depth we already have.
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
    const material = new THREE.MeshBasicMaterial({ map: texture, side: THREE.DoubleSide });

    const mesh = new THREE.Mesh(geometry, material);

    geometry.computeBoundingSphere();

    return { mesh, center, radius: geometry.boundingSphere?.radius ?? 1 };
}

export { FACE_OVAL, SURFACE_POINT_COUNT };
