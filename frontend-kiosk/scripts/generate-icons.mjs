#!/usr/bin/env node
//
// KronoQR — iconos de la PWA del quiosco (PR7).
//
// Android solo ofrece «Instalar» y usa un icono decente en el lanzador si el
// manifiesto declara PNG de 192 y 512 px. Este guion los escribe en
// `public/icons/` sin depender de ninguna herramienta externa (ni ImageMagick,
// ni sharp): rasteriza a mano un reloj sencillo —anillo y dos agujas— con
// sobremuestreo, y codifica el PNG con `node:zlib`.
//
// ES LA MARCA DEL FABRICANTE, NO LA DE NINGUN CLIENTE (regla dura 13): el color
// es el acento de serie de `docs/06-guia-visual.md` (`primary-strong`, #b8542a)
// y no lleva texto ni nombre. Un cliente con marca blanca cambia el nombre, el
// color y el logotipo por configuracion (RF-PD-08); que el icono INSTALADO los
// siga es trabajo de marca blanca (el manifiesto es estatico, `vite.config.ts`).
//
// Sale determinista: los mismos parametros producen byte a byte el mismo
// fichero, asi que se versiona el resultado y este guion queda como receta.
//
//   node scripts/generate-icons.mjs
//
// Ficheros:
//   icon-192.png            purpose «any»
//   icon-512.png            purpose «any»
//   icon-maskable-512.png   purpose «maskable»: fondo a sangre y dibujo dentro
//                           del 80 % central (zona segura de Android)

import { mkdirSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { deflateSync } from 'node:zlib'

const OUT_DIR = fileURLToPath(new URL('../public/icons/', import.meta.url))
const SUPERSAMPLE = 3

/** `primary-strong` de la guia visual, y blanco para el dibujo. */
const BACKGROUND = [0xb8, 0x54, 0x2a]
const FOREGROUND = [0xff, 0xff, 0xff]

/** Distancia de un punto a un segmento, para las agujas con extremos redondeados. */
function distanceToSegment(px, py, ax, ay, bx, by) {
  const abx = bx - ax
  const aby = by - ay
  const lengthSquared = abx * abx + aby * aby
  const t =
    lengthSquared === 0
      ? 0
      : Math.max(0, Math.min(1, ((px - ax) * abx + (py - ay) * aby) / lengthSquared))
  return Math.hypot(px - (ax + t * abx), py - (ay + t * aby))
}

/**
 * Cobertura (0..1) del dibujo en un punto de coordenadas normalizadas
 * (-0.5..0.5, centro en 0,0). `scale` encoge el dibujo (el icono «maskable»).
 */
function glyph(x, y, scale) {
  const ringRadius = 0.3 * scale
  const stroke = 0.055 * scale
  const hand = 0.05 * scale

  const ring = Math.abs(Math.hypot(x, y) - ringRadius) <= stroke / 2
  const hour = distanceToSegment(x, y, 0, 0, 0, -0.17 * scale) <= hand / 2
  const minute = distanceToSegment(x, y, 0, 0, 0.12 * scale, 0.07 * scale) <= hand / 2
  return ring || hour || minute
}

/** `true` si el punto cae dentro de la forma del icono (cuadrado redondeado, o pleno). */
function insideShape(x, y, rounded) {
  if (!rounded) return true
  const half = 0.5
  const radius = 0.22
  const dx = Math.max(Math.abs(x) - (half - radius), 0)
  const dy = Math.max(Math.abs(y) - (half - radius), 0)
  return Math.hypot(dx, dy) <= radius
}

function render(size, { rounded, scale }) {
  const pixels = Buffer.alloc(size * size * 4)
  const samples = SUPERSAMPLE * SUPERSAMPLE

  for (let row = 0; row < size; row += 1) {
    for (let column = 0; column < size; column += 1) {
      let shape = 0
      let drawing = 0
      for (let sy = 0; sy < SUPERSAMPLE; sy += 1) {
        for (let sx = 0; sx < SUPERSAMPLE; sx += 1) {
          const x = (column + (sx + 0.5) / SUPERSAMPLE) / size - 0.5
          const y = (row + (sy + 0.5) / SUPERSAMPLE) / size - 0.5
          if (!insideShape(x, y, rounded)) continue
          shape += 1
          if (glyph(x, y, scale)) drawing += 1
        }
      }

      const offset = (row * size + column) * 4
      if (shape === 0) continue
      const mix = drawing / shape
      pixels[offset] = Math.round(BACKGROUND[0] * (1 - mix) + FOREGROUND[0] * mix)
      pixels[offset + 1] = Math.round(BACKGROUND[1] * (1 - mix) + FOREGROUND[1] * mix)
      pixels[offset + 2] = Math.round(BACKGROUND[2] * (1 - mix) + FOREGROUND[2] * mix)
      pixels[offset + 3] = Math.round((255 * shape) / samples)
    }
  }
  return pixels
}

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
  let c = n
  for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
  return c >>> 0
})

function crc32(buffer) {
  let crc = 0xffffffff
  for (const byte of buffer) crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8)
  return (crc ^ 0xffffffff) >>> 0
}

function chunk(type, data) {
  const head = Buffer.alloc(8)
  head.writeUInt32BE(data.length, 0)
  head.write(type, 4, 'ascii')
  const tail = Buffer.alloc(4)
  tail.writeUInt32BE(crc32(Buffer.concat([head.subarray(4), data])), 0)
  return Buffer.concat([head, data, tail])
}

function encodePng(size, rgba) {
  const header = Buffer.alloc(13)
  header.writeUInt32BE(size, 0)
  header.writeUInt32BE(size, 4)
  header[8] = 8 // profundidad de bits
  header[9] = 6 // RGBA
  // filtro 0 (ninguno) al inicio de cada fila
  const stride = size * 4
  const raw = Buffer.alloc((stride + 1) * size)
  for (let row = 0; row < size; row += 1) {
    raw[row * (stride + 1)] = 0
    rgba.copy(raw, row * (stride + 1) + 1, row * stride, (row + 1) * stride)
  }
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', header),
    chunk('IDAT', deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

mkdirSync(OUT_DIR, { recursive: true })

const ICONS = [
  { file: 'icon-192.png', size: 192, rounded: true, scale: 1 },
  { file: 'icon-512.png', size: 512, rounded: true, scale: 1 },
  { file: 'icon-maskable-512.png', size: 512, rounded: false, scale: 0.8 },
]

for (const icon of ICONS) {
  const png = encodePng(icon.size, render(icon.size, icon))
  writeFileSync(`${OUT_DIR}${icon.file}`, png)
  console.log(`${icon.file}: ${png.length} bytes`)
}
