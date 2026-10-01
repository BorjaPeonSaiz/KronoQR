// Zoom y tamano de texto al 200 % en «Mi registro» (WCAG 2.2 AA, 1.4.4 y
// 1.4.10; PO7-03, PO7-04). Quien tiene poca vista agranda el texto o amplia la
// pagina, y el registro legal no puede romperse por eso: ni scroll horizontal
// de la pagina entera, ni cabeceras solapadas, ni texto cortado.
//
//  - Zoom al 200 % en un movil de 390 px = 195 px CSS de ancho de ventana.
//  - Reflujo de 1.4.10: 320 px CSS.
//  - Texto al 200 %: `font-size: 200 %` en la raiz, que es lo que hace el
//    ajuste de tamano de fuente del sistema y escala todo lo que va en `rem`.
//
// Lo unico que SI puede desplazarse en horizontal es la region de la tabla de
// tramos (los datos tabulares estan exentos de 1.4.10), y es enfocable con el
// teclado; la pagina no.
import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import {
  dayWithBreak,
  logInToPortal,
  PORTAL_WORKDAYS,
  stubPortalApi,
  WORKDAYS_FROM,
  WORKDAYS_TO,
} from './support/portal'

interface LayoutProblems {
  pageOverflow: number
  clipped: string[]
  overlappingHeaders: string[]
  wide: string[]
}

async function openRecords(page: Page): Promise<void> {
  await stubPortalApi(page, { locale: 'es' })

  // Un dia con pausa suma la fila de pausa y la insignia, que son las celdas
  // con mas texto de la tabla.
  await page.route('**/api/v1/me/workdays*', async (route) => {
    const data = [...PORTAL_WORKDAYS, dayWithBreak()]

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        employee_uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        time_zone: 'Europe/Madrid',
        from: WORKDAYS_FROM,
        to: WORKDAYS_TO,
        data,
        meta: { total: data.length },
      }),
    })
  })

  await logInToPortal(page)
  await expect(page.getByTestId('period-summary')).toBeVisible()
  await expect(page.getByTestId('workday').first()).toBeVisible()
}

async function measureLayout(page: Page): Promise<LayoutProblems> {
  return page.evaluate(() => {
    const root = document.documentElement
    const clipped: string[] = []

    for (const element of document.body.querySelectorAll<HTMLElement>('*')) {
      const style = getComputedStyle(element)
      const clips = style.overflowX === 'hidden' || style.overflowX === 'clip'
      const visible =
        element.clientWidth > 1 &&
        element.clientHeight > 1 &&
        !element.classList.contains('sr-only')

      if (clips && visible && element.scrollWidth > element.clientWidth + 1) {
        clipped.push(`${element.tagName.toLowerCase()}.${element.className}`)
      }
    }

    const wide: string[] = []

    for (const element of document.body.querySelectorAll<HTMLElement>('*')) {
      if (element.closest('[role="region"]') !== null) {
        continue
      }

      if (element.getBoundingClientRect().right > root.clientWidth + 1) {
        wide.push(`${element.tagName.toLowerCase()}.${element.className}`)
      }
    }

    const overlappingHeaders: string[] = []

    for (const table of document.querySelectorAll('table')) {
      for (const cell of table.querySelectorAll<HTMLElement>('th, td')) {
        if (cell.scrollWidth > cell.clientWidth + 1) {
          overlappingHeaders.push(
            `celda desbordada: ${cell.textContent?.trim().slice(0, 20) ?? ''}`,
          )
        }
      }

      const heads = [...table.querySelectorAll('thead th')].map((th) => th.getBoundingClientRect())

      for (let index = 1; index < heads.length; index += 1) {
        const previous = heads[index - 1]
        const current = heads[index]

        if (previous !== undefined && current !== undefined && current.left < previous.right - 1) {
          overlappingHeaders.push(`columna ${index}`)
        }
      }
    }

    return {
      pageOverflow: root.scrollWidth - root.clientWidth,
      clipped,
      overlappingHeaders,
      wide,
    }
  })
}

async function expectReadable(page: Page): Promise<void> {
  const problems = await measureLayout(page)

  expect(problems.wide, 'nada fuera de la region de la tabla se sale de la ventana').toEqual([])
  expect(problems.pageOverflow, 'la pagina no se desplaza en horizontal').toBeLessThanOrEqual(0)
  expect(problems.overlappingHeaders, 'cabeceras de la tabla sin solapar').toEqual([])
  expect(problems.clipped, 'ningun texto cortado').toEqual([])
}

test(
  'al 200 % de zoom (195 px CSS) el registro no desborda ni corta texto',
  { tag: ['@RF-ID-05', '@RL-05'] },
  async ({ page }) => {
    await page.setViewportSize({ width: 195, height: 422 })
    await openRecords(page)

    await expectReadable(page)
  },
)

test(
  'a 320 px CSS (reflujo, WCAG 1.4.10) el registro no desborda ni corta texto',
  { tag: ['@RF-ID-05', '@RL-05'] },
  async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 568 })
    await openRecords(page)

    await expectReadable(page)
  },
)

test(
  'con el texto al 200 % (WCAG 1.4.4) el registro sigue legible y la tabla de tramos se puede recorrer',
  { tag: ['@RF-ID-05', '@RL-05'] },
  async ({ page }) => {
    await openRecords(page)
    await page.evaluate(() => {
      document.documentElement.style.fontSize = '200%'
    })

    await expectReadable(page)

    // Las cuatro cabeceras siguen ahi, con texto, y la region que puede
    // desplazarse se alcanza con el teclado.
    const table = page.getByRole('table').first()

    await expect(table.getByRole('columnheader')).toHaveCount(4)

    const region = page.locator('div[role="region"]').first()

    await region.focus()
    await expect(region).toBeFocused()
  },
)
