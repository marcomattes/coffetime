#!/usr/bin/env node
/**
 * Renders every file in public/icons/ from the single vector description
 * below. Development tool: the PNGs are committed, so a deployment never
 * runs this. Re-run it after changing the artwork:
 *
 *   node scripts/make-icons.mjs
 *
 * Why generate instead of hand-editing: each target needs the same drawing
 * at a different scale and with a different safe area. iOS masks the
 * apple-touch icon into a squircle and Android masks the maskable icons
 * into whatever shape the launcher uses, so artwork that is off-centre or
 * too close to the edge is visibly cropped or looks lost in the plate.
 * Deriving all of them from one geometry keeps those margins right.
 */

import { deflateSync } from 'node:zlib';
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ICONS = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'icons');

/* ------------------------------------------------------------- Palette -- */

const BROWN = [0x7a, 0x4a, 0x21]; // --accent
const CREAM = [0xff, 0xfa, 0xf4]; // --accent-ink
const COFFEE = [0xe8, 0xda, 0xc8]; // the surface in the cup

/* ------------------------------------------------------------ Geometry -- */

/*
 * Drawing units: the origin is the centre of the cup rim and the rim is
 * 210 units wide. Nothing here depends on the output size — a target size
 * only picks the scale and the fraction of the canvas the drawing may use.
 */
const RIM_RX = 210;
const RIM_RY = 48;
const CUP_HEIGHT = 340; // rim centre → bottom of the cup wall
const CUP_BOTTOM_RX = 138;
const CUP_BOTTOM_RY = 22;
const SURFACE_DROP = 7; // exposes the far rim as a thin cream arc

const HANDLE = { cx: 262, cy: 148, rx: 112, ry: 132, irx: 58, iry: 80 };
const SAUCER = { cx: 0, cy: 366, rx: 250, ry: 38 };

const STEAM_WIDTH = 50;
const STEAM = [
  { x: -92, y: -228, spread: 62, drop: 92 },
  { x: 92, y: -284, spread: 62, drop: 92 }
];

/* The drawing's own bounding box, so every target can centre it exactly. */
const ART = (() => {
  const steamReach = STEAM_WIDTH / 2;
  const left = Math.min(-RIM_RX, SAUCER.cx - SAUCER.rx, ...STEAM.map((s) => s.x - s.spread - steamReach));
  const right = Math.max(RIM_RX, HANDLE.cx + HANDLE.rx, SAUCER.cx + SAUCER.rx, ...STEAM.map((s) => s.x + s.spread + steamReach));
  const top = Math.min(-RIM_RY, ...STEAM.map((s) => s.y - steamReach));
  const bottom = Math.max(CUP_HEIGHT + CUP_BOTTOM_RY, SAUCER.cy + SAUCER.ry, HANDLE.cy + HANDLE.ry);
  return { left, right, top, bottom, width: right - left, height: bottom - top };
})();

/* --------------------------------------------------------- Hit testing -- */

function inEllipse(x, y, cx, cy, rx, ry) {
  const dx = (x - cx) / rx;
  const dy = (y - cy) / ry;
  return dx * dx + dy * dy <= 1;
}

/** Distance from (x, y) to the segment (ax, ay)–(bx, by). */
function distanceToSegment(x, y, ax, ay, bx, by) {
  const vx = bx - ax;
  const vy = by - ay;
  const length = vx * vx + vy * vy;
  let t = length === 0 ? 0 : ((x - ax) * vx + (y - ay) * vy) / length;
  t = Math.min(1, Math.max(0, t));
  const dx = x - (ax + t * vx);
  const dy = y - (ay + t * vy);
  return Math.hypot(dx, dy);
}

function inCup(x, y) {
  if (inEllipse(x, y, 0, 0, RIM_RX, RIM_RY)) {
    return true;
  }
  if (y >= 0 && y <= CUP_HEIGHT) {
    const halfWidth = RIM_RX + ((CUP_BOTTOM_RX - RIM_RX) * y) / CUP_HEIGHT;
    return Math.abs(x) <= halfWidth;
  }
  // The wall is closed at the bottom with a shallow arc, not a straight cut.
  return y > CUP_HEIGHT && inEllipse(x, y, 0, CUP_HEIGHT, CUP_BOTTOM_RX, CUP_BOTTOM_RY);
}

function inHandle(x, y) {
  return inEllipse(x, y, HANDLE.cx, HANDLE.cy, HANDLE.rx, HANDLE.ry)
    && !inEllipse(x, y, HANDLE.cx, HANDLE.cy, HANDLE.irx, HANDLE.iry);
}

function inSteam(x, y) {
  for (const chevron of STEAM) {
    const left = distanceToSegment(x, y, chevron.x - chevron.spread, chevron.y + chevron.drop, chevron.x, chevron.y);
    const right = distanceToSegment(x, y, chevron.x, chevron.y, chevron.x + chevron.spread, chevron.y + chevron.drop);
    if (Math.min(left, right) <= STEAM_WIDTH / 2) {
      return true;
    }
  }
  return false;
}

/**
 * Colour of the drawing at (x, y) in drawing units, or null where the
 * background shows through. Painting order matters: the handle goes in
 * before the cup so its inner hole never punches through the cup wall.
 */
function artColorAt(x, y) {
  if (inHandle(x, y)) {
    return CREAM;
  }
  if (inCup(x, y)) {
    return inEllipse(x, y - SURFACE_DROP, 0, 0, RIM_RX - SURFACE_DROP, RIM_RY - SURFACE_DROP) ? COFFEE : CREAM;
  }
  if (inEllipse(x, y, SAUCER.cx, SAUCER.cy, SAUCER.rx, SAUCER.ry)) {
    return CREAM;
  }
  if (inSteam(x, y)) {
    return CREAM;
  }
  return null;
}

/* ----------------------------------------------------------- Rendering -- */

/** Coverage of a rounded rectangle filling the whole canvas, 0 = outside. */
function inRoundedSquare(x, y, size, radius) {
  if (radius <= 0) {
    return true;
  }
  const dx = Math.abs(x - size / 2) - (size / 2 - radius);
  const dy = Math.abs(y - size / 2) - (size / 2 - radius);
  if (dx <= 0 || dy <= 0) {
    return true;
  }
  return dx * dx + dy * dy <= radius * radius;
}

const SUPERSAMPLE = 4;

/**
 * Supersampled colour + coverage for one output pixel, in canvas pixel
 * coordinates (px, py). Coverage is the count of subsamples that landed
 * inside the rounded-square canvas; colour is their summed RGB, so the
 * caller can average only the covered subsamples.
 */
function samplePixel(px, py, size, radius, scale, offsetX, offsetY) {
  const step = 1 / SUPERSAMPLE;
  let r = 0;
  let g = 0;
  let b = 0;
  let covered = 0;
  for (let sy = 0; sy < SUPERSAMPLE; sy++) {
    const y = py + (sy + 0.5) * step;
    for (let sx = 0; sx < SUPERSAMPLE; sx++) {
      const x = px + (sx + 0.5) * step;
      if (!inRoundedSquare(x, y, size, radius)) {
        continue;
      }
      const color = artColorAt((x - offsetX) / scale, (y - offsetY) / scale) || BROWN;
      r += color[0];
      g += color[1];
      b += color[2];
      covered += 1;
    }
  }
  return { r, g, b, covered };
}

/**
 * Writes one straight (un-premultiplied) alpha RGBA pixel: colour is the
 * average of the covered samples only, so edges anti-alias without a
 * colour fringe from the uncovered ones.
 */
function writePixel(pixels, offset, { r, g, b, covered }, samples) {
  pixels[offset] = covered === 0 ? 0 : Math.round(r / covered);
  pixels[offset + 1] = covered === 0 ? 0 : Math.round(g / covered);
  pixels[offset + 2] = covered === 0 ? 0 : Math.round(b / covered);
  pixels[offset + 3] = Math.round((covered * 255) / samples);
}

/**
 * @param size     edge length in pixels
 * @param artScale fraction of the canvas the drawing may occupy
 * @param radiusPc corner radius as a fraction of the size (0 = full bleed)
 */
function render(size, artScale, radiusPc) {
  const scale = (artScale * size) / Math.max(ART.width, ART.height);
  const offsetX = size / 2 - ((ART.left + ART.right) / 2) * scale;
  const offsetY = size / 2 - ((ART.top + ART.bottom) / 2) * scale;
  const radius = radiusPc * size;
  const samples = SUPERSAMPLE * SUPERSAMPLE;
  const pixels = Buffer.alloc(size * size * 4);

  for (let py = 0; py < size; py++) {
    for (let px = 0; px < size; px++) {
      const pixel = samplePixel(px, py, size, radius, scale, offsetX, offsetY);
      const offset = (py * size + px) * 4;
      writePixel(pixels, offset, pixel, samples);
    }
  }
  return pixels;
}

/* ------------------------------------------------------- PNG container -- */

function crc32(buffer) {
  let crc = 0xffffffff;
  for (const byte of buffer) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++) {
      crc = crc & 1 ? (crc >>> 1) ^ 0xedb88320 : crc >>> 1;
    }
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const head = Buffer.alloc(8);
  head.writeUInt32BE(data.length, 0);
  head.write(type, 4, 'ascii');
  const tail = Buffer.alloc(4);
  tail.writeUInt32BE(crc32(Buffer.concat([head.subarray(4), data])), 0);
  return Buffer.concat([head, data, tail]);
}

function encodePng(size, pixels) {
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0);
  ihdr.writeUInt32BE(size, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 6; // truecolour with alpha
  const stride = size * 4;
  const raw = Buffer.alloc((stride + 1) * size);
  for (let y = 0; y < size; y++) {
    raw[y * (stride + 1)] = 0; // filter: none
    pixels.copy(raw, y * (stride + 1) + 1, y * stride, (y + 1) * stride);
  }
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0))
  ]);
}

/* --------------------------------------------------------------- Files -- */

/*
 * `artScale` per purpose:
 *   any / apple-touch  0.68 — a comfortable app-icon margin.
 *   maskable           0.56 — the drawing's diagonal then stays inside the
 *                             80 % safe circle every launcher mask respects.
 *   favicon            0.78 — 32 px needs the drawing to fill more.
 * `radius` per purpose: the apple-touch icon must be full bleed and fully
 * opaque, because iOS applies its own squircle mask and renders any
 * transparency as black.
 */
const TARGETS = [
  { file: 'icon-192.png', size: 192, artScale: 0.68, radius: 0.2 },
  { file: 'icon-512.png', size: 512, artScale: 0.68, radius: 0.2 },
  { file: 'icon-maskable-192.png', size: 192, artScale: 0.56, radius: 0 },
  { file: 'icon-maskable-512.png', size: 512, artScale: 0.56, radius: 0 },
  { file: 'apple-touch-icon.png', size: 180, artScale: 0.72, radius: 0 },
  { file: 'favicon-32.png', size: 32, artScale: 0.78, radius: 0.2 }
];

for (const target of TARGETS) {
  const png = encodePng(target.size, render(target.size, target.artScale, target.radius));
  writeFileSync(join(ICONS, target.file), png);
  process.stdout.write(`${target.file}  ${target.size}×${target.size}  ${png.length} bytes\n`);
}
