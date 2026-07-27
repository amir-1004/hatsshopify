// Procedural 3D hats.
//
// Each style is built from primitives at a normalised scale: the crown has
// radius 1 and sits on y = 0, so the caller can scale the whole group by the
// head radius it measured (in screen pixels) and drop it on the head.

import * as THREE from 'three';

/**
 * Multiply a hex color's channels — <1 darkens, >1 lightens.
 */
function shade(hex, factor) {
    const color = new THREE.Color(hex);

    return color.setRGB(
        Math.min(1, color.r * factor),
        Math.min(1, color.g * factor),
        Math.min(1, color.b * factor),
    );
}

function fabric(hex, factor = 1) {
    return new THREE.MeshStandardMaterial({
        color: shade(hex, factor),
        roughness: 0.85,
        metalness: 0.02,
    });
}

/**
 * Dome of a cap/beanie. `height` stretches it vertically, `depth` front-to-back
 * (a skull is longer than it is wide).
 */
function crown(material, { height = 0.78, depth = 1.2, segments = 48 } = {}) {
    const geometry = new THREE.SphereGeometry(1, segments, 32, 0, Math.PI * 2, 0, Math.PI / 2);
    const mesh = new THREE.Mesh(geometry, material);

    mesh.scale.set(1, height, depth);

    return mesh;
}

/**
 * A visor: half an ellipse extruded into a slab, laid flat and tilted down
 * at the front.
 */
function visor(material, { reach = 1.55, width = 1.12, thickness = 0.07, tilt = 0.22, squared = false } = {}) {
    const shape = new THREE.Shape();

    if (squared) {
        // Flat-brim look: straighter sides, rounded tip.
        shape.moveTo(-width, 0);
        shape.lineTo(-width * 0.94, -reach * 0.72);
        shape.quadraticCurveTo(-width * 0.7, -reach, 0, -reach);
        shape.quadraticCurveTo(width * 0.7, -reach, width * 0.94, -reach * 0.72);
        shape.lineTo(width, 0);
    } else {
        shape.absellipse(0, 0, width, reach, Math.PI, Math.PI * 2, false);
    }

    const geometry = new THREE.ExtrudeGeometry(shape, {
        depth: thickness,
        bevelEnabled: true,
        bevelSize: thickness * 0.6,
        bevelThickness: thickness * 0.5,
        bevelSegments: 2,
        curveSegments: 48,
    });

    // Shape's -y becomes +z (forward); extrusion depth becomes thickness.
    geometry.rotateX(-Math.PI / 2);

    const mesh = new THREE.Mesh(geometry, material);
    mesh.rotation.x = tilt;
    mesh.position.y = 0.04;

    return mesh;
}

/**
 * The band where the crown meets the head.
 */
function sweatband(material, { radius = 1.005, tube = 0.055 } = {}) {
    const mesh = new THREE.Mesh(new THREE.TorusGeometry(radius, tube, 12, 56), material);

    mesh.rotation.x = Math.PI / 2;
    mesh.scale.z = 1.2; // match the crown's front-to-back stretch
    mesh.position.y = 0.05;

    return mesh;
}

function button(material, height) {
    const mesh = new THREE.Mesh(new THREE.SphereGeometry(0.09, 20, 16), material);

    mesh.position.y = height;

    return mesh;
}

function baseball(hex) {
    const group = new THREE.Group();

    group.add(crown(fabric(hex)));
    group.add(visor(fabric(hex, 0.72)));
    group.add(sweatband(fabric(hex, 0.72)));
    group.add(button(fabric(hex, 1.15), 0.79));

    return group;
}

function snapback(hex) {
    const group = new THREE.Group();

    group.add(crown(fabric(hex), { height: 0.9, depth: 1.16 }));
    group.add(visor(fabric(hex, 0.7), { reach: 1.7, width: 1.2, tilt: 0.03, thickness: 0.08, squared: true }));
    group.add(sweatband(fabric(hex, 0.7)));
    group.add(button(fabric(hex, 1.15), 0.91));

    return group;
}

function trucker(hex) {
    const group = new THREE.Group();

    group.add(crown(fabric(hex), { height: 0.86, depth: 1.18 }));

    // Lighter mesh panels across the back half of the crown.
    const panels = new THREE.Mesh(
        new THREE.SphereGeometry(1.004, 48, 32, Math.PI / 2, Math.PI, 0, Math.PI / 2),
        new THREE.MeshStandardMaterial({
            color: shade(hex, 1.45),
            roughness: 0.95,
            side: THREE.DoubleSide,
        }),
    );
    panels.scale.set(1, 0.86, 1.18);
    group.add(panels);

    group.add(visor(fabric(hex, 0.68), { reach: 1.6, width: 1.16, tilt: 0.08, squared: true }));
    group.add(sweatband(fabric(hex, 0.68)));

    return group;
}

function beanie(hex) {
    const group = new THREE.Group();

    group.add(crown(fabric(hex), { height: 1.02, depth: 1.1 }));

    // Rolled cuff.
    const cuff = new THREE.Mesh(new THREE.TorusGeometry(1.01, 0.13, 14, 56), fabric(hex, 0.8));
    cuff.rotation.x = Math.PI / 2;
    cuff.scale.z = 1.1;
    cuff.position.y = 0.12;
    group.add(cuff);

    const pompom = new THREE.Mesh(new THREE.SphereGeometry(0.24, 24, 20), fabric(hex, 1.35));
    pompom.position.y = 1.06;
    group.add(pompom);

    return group;
}

function bucket(hex) {
    const group = new THREE.Group();

    group.add(crown(fabric(hex), { height: 0.62, depth: 1.12 }));

    // A brim that droops all the way round, swept as a surface of revolution.
    const profile = [];
    for (let i = 0; i <= 10; i += 1) {
        const t = i / 10;
        profile.push(new THREE.Vector2(0.96 + t * 0.78, 0.06 - t * t * 0.42));
    }

    const brim = new THREE.Mesh(
        new THREE.LatheGeometry(profile, 56),
        new THREE.MeshStandardMaterial({
            color: shade(hex, 1.08),
            roughness: 0.9,
            side: THREE.DoubleSide,
        }),
    );
    brim.scale.z = 1.08;
    group.add(brim);
    group.add(sweatband(fabric(hex, 0.75), { radius: 0.99, tube: 0.05 }));

    return group;
}

const BUILDERS = {
    Baseball: baseball,
    Snapback: snapback,
    Trucker: trucker,
    Beanie: beanie,
    Bucket: bucket,
};

/**
 * Build a hat of the given style in the given color.
 *
 * @param {string} style one of the catalog styles
 * @param {string} hex   resolved `#rrggbb` (the server resolves color names)
 * @returns {THREE.Group} crown radius 1, base at y = 0, facing +z
 */
export function buildHat(style, hex) {
    const build = BUILDERS[style] ?? baseball;
    const group = build(hex || '#4c6ef5');

    group.traverse((child) => {
        if (child.isMesh) child.castShadow = true;
    });

    return group;
}

export const HAT_STYLES = Object.keys(BUILDERS);
