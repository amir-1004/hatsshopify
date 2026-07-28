// An actual baseball cap, modelled rather than assembled.
//
// The first version of this was a hemisphere with an extruded half-ellipse
// stuck on the side, and it read as exactly that: a dome and a blade. No
// amount of fabric texture or lighting rescues a wrong silhouette, because
// the silhouette is what the eye checks first.
//
// Two things make a cap look like a cap:
//
//   1. The crown is not a sphere. It's fullest at the band and tapers as it
//      rises to the button, and it's built from six panels whose seams give
//      the outline a faint scallop instead of a perfect circle.
//
//   2. The bill is not a flat plate. It's a curved surface that *wraps the
//      head* — every point along it starts on the band circle and sweeps
//      outward and down, so it hugs the front of the crown rather than
//      sticking out sideways.
//
// Everything is normalised so the band sits on y = 0 with radius 1, matching
// the convention the rest of the try-on uses.

import * as THREE from 'three';

/**
 * Crown silhouette, as [radius, height] pairs from the band up to the button.
 *
 * Measured off the shape of a real six-panel cap: it holds nearly full width
 * for the first third — that's the part that sits around your head — then
 * turns over and tapers. A sphere starts narrowing immediately, which is why
 * the old one looked like a bowl.
 */
const CROWN_PROFILE = [
    [1.0, 0.0],
    [1.0, 0.08],
    [0.995, 0.18],
    [0.98, 0.3],
    [0.95, 0.42],
    [0.9, 0.53],
    [0.83, 0.63],
    [0.73, 0.72],
    [0.6, 0.8],
    [0.44, 0.86],
    [0.26, 0.9],
    [0.1, 0.92],
    [0.0, 0.925],
];

/**
 * Build the crown as a surface of revolution with panel seams pressed into it.
 *
 * @param {object} options
 * @param {number} options.height  vertical stretch (a snapback is taller)
 * @param {number} options.depth   front-to-back stretch — a head is an ellipse
 * @param {number} options.panels  seam count; six is the classic cap
 * @param {number} options.crease  how deep the seams pinch the outline
 * @param {number} options.phiStart  where the sweep begins; 0 faces +z
 * @param {number} options.phiLength how much of the way round to sweep
 */
export function crownGeometry({
    height = 1,
    depth = 1.14,
    panels = 6,
    crease = 0.022,
    segments = 96,
    phiStart = 0,
    phiLength = Math.PI * 2,
    scale = 1,
} = {}) {
    const points = CROWN_PROFILE.map(([radius, y]) => new THREE.Vector2(radius * scale, y * height));
    const geometry = new THREE.LatheGeometry(points, segments, phiStart, phiLength);

    const position = geometry.attributes.position;

    for (let i = 0; i < position.count; i += 1) {
        const x = position.getX(i);
        const y = position.getY(i);
        const z = position.getZ(i);

        // Pinch the surface in slightly at each seam. cos(panels * angle)
        // peaks once per panel, so the crown gains six soft ridges and the
        // silhouette stops being a perfect circle.
        const angle = Math.atan2(z, x);
        const pinch = 1 - crease * (0.5 - 0.5 * Math.cos(panels * angle));

        position.setXYZ(i, x * pinch, y, z * pinch * depth);
    }

    position.needsUpdate = true;
    geometry.computeVertexNormals();

    return geometry;
}

/**
 * Build the bill as a curved sweep off the band circle.
 *
 * For every angle across the front of the head, a rib starts on the band and
 * travels outward while dropping — so the bill wraps the crown, is longest
 * dead ahead, tapers toward the ears, and curves down at the tip the way a
 * shaped bill does. That wrap is the whole difference between a bill and a
 * paddle.
 *
 * @param {object} options
 * @param {number} options.spread   half-angle the bill covers, in radians
 * @param {number} options.reach    how far it extends at the centre, in head radii
 * @param {number} options.drop     how far the tip falls
 * @param {number} options.thickness slab thickness at the band, tapering out
 * @param {number} options.depth    the head ellipse's front-to-back stretch
 */
export function billGeometry({
    spread = 1.38,
    reach = 0.85,
    drop = 0.3,
    thickness = 0.055,
    depth = 1.14,
    across = 64,
    along = 16,
} = {}) {
    const top = [];
    const bottom = [];
    const uvs = [];

    for (let a = 0; a <= across; a += 1) {
        // Sweep symmetrically about straight ahead (+z).
        const angle = -spread + (2 * spread * a) / across;

        // Where this rib leaves the band, on the head's ellipse.
        const baseX = Math.sin(angle);
        const baseZ = Math.cos(angle) * depth;

        // Radially outward, in the plane of the band.
        const outward = new THREE.Vector2(baseX, baseZ).normalize();

        // Longest straight ahead, falling to *nothing* at the ends. Reaching
        // zero is the load-bearing part: leave any width at the extremes and
        // the brim carries on round the head and the thing reads as a sun
        // hat. A bill has to vanish into the band where it stops.
        const taper = Math.pow(Math.cos((angle / spread) * (Math.PI / 2)), 1.15);
        const ribReach = reach * taper;

        for (let t = 0; t <= along; t += 1) {
            const s = t / along;

            const x = baseX + outward.x * ribReach * s;
            const z = baseZ + outward.y * ribReach * s;

            // Accelerating fall, so the bill leaves the band level and only
            // curls down toward the tip.
            const y = -drop * Math.pow(s, 1.7) * taper;

            // Thin out toward the edge like a real moulded bill.
            const slab = thickness * (1 - 0.65 * s);

            top.push(x, y, z);
            bottom.push(x, y - slab, z);
            uvs.push(a / across, s);
        }
    }

    const stride = along + 1;
    const indices = [];

    const quad = (a, b, c, d) => indices.push(a, b, d, b, c, d);

    for (let a = 0; a < across; a += 1) {
        for (let t = 0; t < along; t += 1) {
            const i = a * stride + t;
            const j = (a + 1) * stride + t;

            quad(i, i + 1, j + 1, j); // top surface
        }
    }

    const offset = top.length / 3;

    for (let a = 0; a < across; a += 1) {
        for (let t = 0; t < along; t += 1) {
            const i = offset + a * stride + t;
            const j = offset + (a + 1) * stride + t;

            quad(j, j + 1, i + 1, i); // underside, wound the other way
        }
    }

    // Close the outer rim so the bill reads as a solid object edge-on.
    for (let a = 0; a < across; a += 1) {
        const i = a * stride + along;
        const j = (a + 1) * stride + along;

        quad(i, j, offset + j, offset + i);
    }

    const geometry = new THREE.BufferGeometry();

    geometry.setAttribute('position', new THREE.Float32BufferAttribute([...top, ...bottom], 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute([...uvs, ...uvs], 2));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();

    return geometry;
}

/**
 * The band the crown sits on — a real cap has a visible seam there.
 */
export function bandGeometry({ depth = 1.14, height = 0.075, segments = 96 } = {}) {
    const geometry = new THREE.CylinderGeometry(1.004, 1.004, height, segments, 1, true);

    geometry.scale(1, 1, depth);
    geometry.translate(0, height / 2, 0);

    return geometry;
}
