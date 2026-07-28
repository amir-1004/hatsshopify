// Procedural 3D hats.
//
// Each style is built from primitives at a normalised scale: the crown has
// radius 1 and sits on y = 0, so the caller can scale the whole group by the
// head radius it measured (in screen pixels) and drop it on the head.

import * as THREE from 'three';
import { crownGeometry, billGeometry, bandGeometry } from './cap-geometry.js';

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

/**
 * A woven-fabric normal map, drawn once into a canvas.
 *
 * Flat-shaded polygons read as plastic no matter how good the silhouette is
 * — what says "hat" is the weave catching the light. This is cheap enough to
 * generate at runtime and needs no asset to download.
 */
let weaveTexture = null;

function weave() {
    if (weaveTexture) return weaveTexture;

    const size = 256;
    const canvas = document.createElement('canvas');

    canvas.width = size;
    canvas.height = size;

    const context = canvas.getContext('2d');
    const image = context.createImageData(size, size);

    for (let y = 0; y < size; y += 1) {
        for (let x = 0; x < size; x += 1) {
            // Two out-of-phase ripples crossing at right angles read as warp
            // and weft; the noise keeps it from looking like graph paper.
            const warp = Math.sin((x / size) * Math.PI * 2 * 48);
            const weft = Math.sin((y / size) * Math.PI * 2 * 48);
            const noise = (Math.random() - 0.5) * 0.35;

            const i = (y * size + x) * 4;
            image.data[i] = 128 + (warp + noise) * 26; // normal.x
            image.data[i + 1] = 128 + (weft + noise) * 26; // normal.y
            image.data[i + 2] = 255; // normal.z
            image.data[i + 3] = 255;
        }
    }

    context.putImageData(image, 0, 0);

    weaveTexture = new THREE.CanvasTexture(canvas);
    weaveTexture.wrapS = THREE.RepeatWrapping;
    weaveTexture.wrapT = THREE.RepeatWrapping;
    weaveTexture.repeat.set(6, 6);

    return weaveTexture;
}

/**
 * The crown's albedo: the hat's own cloth, with six-panel seams, topstitching
 * and eyelets drawn over it.
 *
 * Both halves matter. A seamless dome reads as a bowl however well it's lit —
 * the eye finds a cap's panel seams before it finds the silhouette. And the
 * cloth underneath is lifted straight out of the merchant's product photo, so
 * the crown is made of the fabric the hat is actually made of.
 */
function crownTexture(cloth) {
    const width = 1024;
    const height = 512;
    const canvas = document.createElement('canvas');

    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d');

    if (cloth) {
        // Tile the photographed patch across the crown's UV space.
        const pattern = context.createPattern(cloth, 'repeat');
        const scale = height / (cloth.height * 2.2);

        context.save();
        context.scale(scale, scale);
        context.fillStyle = pattern;
        context.fillRect(0, 0, width / scale, height / scale);
        context.restore();
    } else {
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
    }

    // Six meridian seams, each flanked by a row of topstitching.
    for (let panel = 0; panel < 6; panel += 1) {
        const x = (panel / 6) * width;

        context.strokeStyle = 'rgba(0, 0, 0, 0.34)';
        context.lineWidth = 3;
        context.beginPath();
        context.moveTo(x, 0);
        context.lineTo(x, height);
        context.stroke();

        context.strokeStyle = 'rgba(0, 0, 0, 0.16)';
        context.lineWidth = 2;
        context.setLineDash([7, 6]);

        for (const offset of [-9, 9]) {
            context.beginPath();
            context.moveTo(x + offset, 0);
            context.lineTo(x + offset, height);
            context.stroke();
        }

        context.setLineDash([]);
    }

    // Eyelets: one per panel, a little above the band.
    context.fillStyle = 'rgba(0, 0, 0, 0.25)';
    for (let panel = 0; panel < 6; panel += 1) {
        context.beginPath();
        context.arc(((panel + 0.5) / 6) * width, height * 0.62, 5, 0, Math.PI * 2);
        context.fill();
    }

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

function clothNormal(cloth) {
    const texture = new THREE.CanvasTexture(cloth);

    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.repeat.set(4, 4);

    return texture;
}

/**
 * Build a fabric material for one part of the hat.
 *
 * `appearance` is what was read off the product photo — its real colour, a
 * tileable patch of its cloth, and a normal map derived from that patch's own
 * shading. When it's missing (no photo, a cross-origin one, or a picture with
 * no hat in it) this falls back to the product's colour and a synthetic weave,
 * so a hat always renders.
 */
function shadeColor(color, factor) {
    return new THREE.Color(
        Math.min(1, color.r * factor),
        Math.min(1, color.g * factor),
        Math.min(1, color.b * factor),
    );
}

function fabric(hex, factor = 1, { seams = false, appearance = null } = {}) {
    const base = appearance?.color
        ? shadeColor(appearance.color, factor)
        : shade(hex, factor);

    const material = new THREE.MeshPhysicalMaterial({
        color: base,
        roughness: 0.78,
        metalness: 0,
        normalMap: appearance?.normal ? clothNormal(appearance.normal) : weave(),
        normalScale: new THREE.Vector2(0.45, 0.45),
        // Brushed cotton picks up a soft off-axis highlight rather than a
        // hard specular dot.
        // Restrained now that the albedo carries the product's real colour.
        // Cotton does pick up a soft sheen, but a strong one over an accurate
        // red renders it pink — the light budget has to leave the pigment
        // room to show.
        sheen: 0.25,
        sheenRoughness: 0.9,
        sheenColor: shadeColor(base, 1.15),
        envMapIntensity: 0.45,
    });

    if (seams) {
        material.map = crownTexture(appearance?.patch ?? null);

        // The map already carries the cloth's own colour, so tinting it again
        // would double the darkness.
        if (appearance?.patch) material.color = new THREE.Color(0xffffff);
    }

    return material;
}

/**
 * Dome of a cap/beanie. `height` stretches it vertically, `depth` front-to-back
 * (a skull is longer than it is wide).
 */
function crown(material, { height = 0.7, depth = 1.2, segments = 48 } = {}) {
    const geometry = new THREE.SphereGeometry(1, segments, 32, 0, Math.PI * 2, 0, Math.PI / 2);
    const mesh = new THREE.Mesh(geometry, material);

    mesh.scale.set(1, height, depth);

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

/**
 * Assemble a cap from the modelled crown and bill.
 *
 * `crownOpts` / `billOpts` are what separates the styles: a snapback is a
 * taller crown with a long flat bill, a trucker sits between the two with
 * mesh panels at the back.
 */
function cap(hex, appearance, { crownOpts = {}, billOpts = {}, button = true } = {}) {
    const group = new THREE.Group();
    const depth = crownOpts.depth ?? 1.14;

    group.add(new THREE.Mesh(
        crownGeometry(crownOpts),
        fabric(hex, 1, { seams: true, appearance }),
    ));

    // The bill's underside is darker on almost every real cap, and it's the
    // face you actually see once the hat is on someone's head.
    const bill = new THREE.Mesh(
        billGeometry({ ...billOpts, depth }),
        fabric(hex, 0.62, { appearance }),
    );
    bill.position.y = 0.045;
    group.add(bill);

    group.add(new THREE.Mesh(bandGeometry({ depth }), fabric(hex, 0.72, { appearance })));

    if (button) {
        const height = (crownOpts.height ?? 1) * 0.925;
        const knob = new THREE.Mesh(
            new THREE.SphereGeometry(0.055, 20, 16),
            fabric(hex, 1.1, { appearance }),
        );

        knob.position.y = height + 0.02;
        group.add(knob);
    }

    return group;
}

function baseball(hex, appearance) {
    return cap(hex, appearance, {
        crownOpts: { height: 0.82, depth: 1.14 },
        billOpts: { reach: 0.9, drop: 0.28, spread: 1.38 },
    });
}

function snapback(hex, appearance) {
    // Taller, boxier crown; a longer bill that barely drops.
    return cap(hex, appearance, {
        crownOpts: { height: 0.98, depth: 1.12, crease: 0.016 },
        billOpts: { reach: 1.02, drop: 0.08, spread: 1.42, thickness: 0.07 },
    });
}

function trucker(hex, appearance) {
    const group = cap(hex, appearance, {
        crownOpts: { height: 0.9, depth: 1.15 },
        billOpts: { reach: 0.92, drop: 0.16, spread: 1.4 },
        button: false,
    });

    // Lighter mesh across the back half of the crown.
    const mesh = new THREE.Mesh(
        // Back half only: phi = 0 faces +z, so start a quarter turn round.
        crownGeometry({ height: 0.9, depth: 1.15, phiStart: Math.PI / 2, phiLength: Math.PI, scale: 1.004 }),
        new THREE.MeshStandardMaterial({
            color: appearance?.color ? shadeColor(appearance.color, 1.4) : shade(hex, 1.4),
            roughness: 0.95,
            side: THREE.DoubleSide,
        }),
    );

    group.add(mesh);

    return group;
}

function beanie(hex, appearance) {
    const group = new THREE.Group();

    group.add(crown(fabric(hex, 1, { appearance }), { height: 0.95, depth: 1.1 }));

    // Rolled cuff.
    const cuff = new THREE.Mesh(new THREE.TorusGeometry(1.01, 0.13, 14, 56), fabric(hex, 0.8, { appearance }));
    cuff.rotation.x = Math.PI / 2;
    cuff.scale.z = 1.1;
    cuff.position.y = 0.12;
    group.add(cuff);

    const pompom = new THREE.Mesh(new THREE.SphereGeometry(0.24, 24, 20), fabric(hex, 1.35, { appearance }));
    pompom.position.y = 0.99;
    group.add(pompom);

    return group;
}

function bucket(hex, appearance) {
    const group = new THREE.Group();

    group.add(crown(fabric(hex, 1, { appearance }), { height: 0.62, depth: 1.12 }));

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
    group.add(sweatband(fabric(hex, 0.75, { appearance }), { radius: 0.99, tube: 0.05 }));

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
 * @param {?object} appearance material read off the product photo, if any
 * @returns {THREE.Group} crown radius 1, base at y = 0, facing +z
 */
export function buildHat(style, hex, appearance = null) {
    const build = BUILDERS[style] ?? baseball;
    const group = build(hex || '#4c6ef5', appearance);

    group.traverse((child) => {
        if (child.isMesh) child.castShadow = true;
    });

    return group;
}

export const HAT_STYLES = Object.keys(BUILDERS);
