// Disposicion de la pantalla de escaneo en tablet (RF-KI-06, doc 01 §6.5).
//
// El velo oscuro sobre la camara desaparecio a peticion del cliente: la
// camara se ve limpia y el texto vive en bandas propias
// (`bg-kq-kiosk-surface/80`) solo detras de si mismo. Lo que esta prueba
// blinda es justo lo que se rompe cuando alguien retoca ese layout sin
// mirarlo en una tablet real: que titulo, subtitulo, el enlace de PIN y el
// visor quepan ENTEROS dentro de la seccion de la camara, que NINGUNO se
// solape con otro, y que el visor quede centrado horizontalmente. Se
// comprueba en apaisado (1280x800 y 1024x768, las resoluciones de tablet
// tipicas) y en vertical (800x1280): la orientacion de la tablet de pared no
// esta garantizada.
//
// Los cuatro rectangulos se leen en una UNICA llamada a `page.evaluate` para
// que la lectura sea atomica: el video de camara simulada SI contiene un QR
// valido y decodifica solo, y una lectura en varias vueltas de red podria
// pillar a mitad de camino entre la pantalla de espera y la confirmacion.

import { expect, test } from '@playwright/test'
import { stubKioskApiWithPin } from './support/pin'

interface Rect {
  readonly top: number
  readonly right: number
  readonly bottom: number
  readonly left: number
  readonly width: number
  readonly height: number
}

interface IdleRects {
  readonly section: Rect
  readonly title: Rect
  readonly subtitle: Rect
  readonly pinLink: Rect
  readonly viewfinder: Rect
}

/** Tolerancia de subpixel para comparaciones de contorno. */
const EPSILON = 1

async function readIdleRects(page: import('@playwright/test').Page): Promise<IdleRects> {
  return page.evaluate(() => {
    function box(testid: string): DOMRect {
      const el = document.querySelector(`[data-testid="${testid}"]`)
      if (el === null) throw new Error(`falta [data-testid="${testid}"]`)
      return el.getBoundingClientRect()
    }
    function plain(r: DOMRect) {
      return {
        top: r.top,
        right: r.right,
        bottom: r.bottom,
        left: r.left,
        width: r.width,
        height: r.height,
      }
    }
    return {
      section: plain(box('scan-camera-section')),
      title: plain(box('scan-idle-title')),
      subtitle: plain(box('scan-idle-subtitle')),
      pinLink: plain(box('pin-entry-link')),
      viewfinder: plain(box('scan-viewfinder')),
    }
  })
}

/** Que `inner` quepa entero dentro de `outer`, con un margen de subpixel. */
function expectContained(inner: Rect, outer: Rect, label: string): void {
  expect(inner.top, `${label}: se sale del borde superior`).toBeGreaterThanOrEqual(
    outer.top - EPSILON,
  )
  expect(inner.left, `${label}: se sale del borde izquierdo`).toBeGreaterThanOrEqual(
    outer.left - EPSILON,
  )
  expect(inner.bottom, `${label}: se sale del borde inferior`).toBeLessThanOrEqual(
    outer.bottom + EPSILON,
  )
  expect(inner.right, `${label}: se sale del borde derecho`).toBeLessThanOrEqual(
    outer.right + EPSILON,
  )
}

/** Solapamiento AABB clasico: si no se solapan en un eje, no hay interseccion. */
function intersects(a: Rect, b: Rect): boolean {
  return (
    a.left < b.right - EPSILON &&
    a.right > b.left + EPSILON &&
    a.top < b.bottom - EPSILON &&
    a.bottom > b.top + EPSILON
  )
}

function expectNoOverlap(rects: Record<string, Rect>): void {
  const entries = Object.entries(rects)
  for (let i = 0; i < entries.length; i += 1) {
    for (let j = i + 1; j < entries.length; j += 1) {
      const [nameA, rectA] = entries[i] as [string, Rect]
      const [nameB, rectB] = entries[j] as [string, Rect]
      expect(
        intersects(rectA, rectB),
        `${nameA} y ${nameB} se solapan: ` + `${JSON.stringify(rectA)} / ${JSON.stringify(rectB)}`,
      ).toBe(false)
    }
  }
}

const VIEWPORTS = [
  { name: 'tablet apaisada 1280x800', width: 1280, height: 800 },
  { name: 'tablet apaisada 1024x768', width: 1024, height: 768 },
  { name: 'tablet vertical 800x1280', width: 800, height: 1280 },
]

for (const viewport of VIEWPORTS) {
  test.describe(`disposicion en ${viewport.name}`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } })

    test(
      'titulo, subtitulo, enlace de PIN y visor caben en la seccion de camara sin solaparse',
      { tag: ['@RF-KI-06'] },
      async ({ page }) => {
        await stubKioskApiWithPin(page)
        await page.goto('/')

        await expect(page.getByTestId('scan-idle')).toBeVisible()
        await expect(page.getByTestId('pin-entry-link')).toBeVisible()
        await expect(page.getByTestId('scan-viewfinder')).toBeVisible()

        const rects = await readIdleRects(page)

        // Nada se sale de la camara.
        expectContained(rects.title, rects.section, 'titulo')
        expectContained(rects.subtitle, rects.section, 'subtitulo')
        expectContained(rects.pinLink, rects.section, 'enlace de PIN')
        expectContained(rects.viewfinder, rects.section, 'visor')

        // Nada se pisa con nada.
        expectNoOverlap({
          titulo: rects.title,
          subtitulo: rects.subtitle,
          pinLink: rects.pinLink,
          visor: rects.viewfinder,
        })

        // El visor queda centrado horizontalmente en la seccion, +/- 8 px.
        const sectionCenter = (rects.section.left + rects.section.right) / 2
        const viewfinderCenter = (rects.viewfinder.left + rects.viewfinder.right) / 2
        expect(
          Math.abs(viewfinderCenter - sectionCenter),
          `visor descentrado: seccion=${sectionCenter}, visor=${viewfinderCenter}`,
        ).toBeLessThanOrEqual(8)

        // Orden de arriba a abajo: titulo -> subtitulo -> enlace -> visor.
        expect(rects.title.top).toBeLessThan(rects.subtitle.top)
        expect(rects.subtitle.top).toBeLessThan(rects.pinLink.top)
        expect(rects.pinLink.bottom).toBeLessThanOrEqual(rects.viewfinder.top + EPSILON)

        await page.screenshot({
          path: test.info().outputPath(`quiosco-${viewport.width}x${viewport.height}.png`),
          fullPage: false,
        })
      },
    )
  })
}
