// Titulo de la pestaña por pantalla (WCAG 2.4.2, V7-PA-2) y anuncio del cambio de
// pantalla (WCAG 2.4.3 y 4.1.3, V7-PA-3).
import { announce, announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import { routes } from '@/router'
import { bindDocumentTitle } from '@/router/documentTitle'
import { bindScreenChange } from '@/router/screenChange'
import { createAppI18n } from '@/shared/i18n'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { testMessages } from './support/i18n'

function lookup(messages: unknown, key: string): unknown {
  return key
    .split('.')
    .reduce<unknown>(
      (node, part) =>
        typeof node === 'object' && node !== null
          ? (node as Record<string, unknown>)[part]
          : undefined,
      messages,
    )
}

/** Todas las rutas con pantalla (las hijas del marco y las publicas), sin redirecciones. */
function screens(): RouteRecordRaw[] {
  return routes
    .flatMap((route) => route.children ?? [route])
    .filter((route) => route.redirect === undefined && route.component !== undefined)
}

describe('titulo de la pestaña (V7-PA-2)', () => {
  it('toda pantalla declara una clave de titulo que existe en es y en en', () => {
    const missing = screens().filter((route) => route.meta?.titleKey === undefined)

    expect(missing.map((route) => String(route.name))).toEqual([])

    for (const route of screens()) {
      const key = route.meta?.titleKey as string

      expect(typeof lookup(es, key), `es: ${key}`).toBe('string')
      expect(typeof lookup(en, key), `en: ${key}`).toBe('string')
    }
  })

  it('no hay dos pantallas con el mismo titulo', () => {
    const keys = screens().map((route) => route.meta?.titleKey as string)

    expect(new Set(keys).size).toBe(keys.length)
  })

  it('compone «pantalla · instalacion», sigue al idioma y nunca lleva el nombre de una persona', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        {
          path: '/',
          name: 'a',
          component: { template: '<i/>' },
          meta: { titleKey: 'incidents.title' },
        },
        {
          path: '/employees/:uuid',
          name: 'employee',
          component: { template: '<i/>' },
          meta: { titleKey: 'app.titles.employee' },
        },
      ],
    })
    const i18n = createAppI18n('es', testMessages)
    const binding = bindDocumentTitle({ router, i18n, applicationName: () => 'Hotel Marina' })

    await router.push('/')
    expect(document.title).toBe(`${es.incidents.title} · Hotel Marina`)

    await router.push('/employees/0199f0c2-1f4a-7c3e-9b21-000000000001')
    expect(document.title).toBe('Ficha del empleado · Hotel Marina')

    i18n.global.locale.value = 'en'
    expect(document.title).toBe('Employee record · Hotel Marina')

    binding.stop()
  })
})

describe('cambio de pantalla (V7-PA-3)', () => {
  let stop: (() => void) | null = null

  beforeEach(async () => {
    document.body.innerHTML =
      '<main id="main"><h1>Pantalla</h1><button id="menu">Menu</button></main>'
    clearAnnouncement()
  })

  afterEach(() => {
    stop?.()
    document.body.innerHTML = ''
  })

  function makeRouter() {
    const view = { template: '<i/>' }

    return createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/a', name: 'a', component: view },
        { path: '/b', name: 'b', component: view },
      ],
    })
  }

  it('al cambiar de pantalla vacia la region viva y manda el foco al h1', async () => {
    const router = makeRouter()

    stop = bindScreenChange(router)
    await router.push('/a')
    announce('El cuadro de impacto y adopcion se ha calculado.')
    ;(document.getElementById('menu') as HTMLElement).focus()

    await router.push('/b')
    await nextTick()
    await nextTick()

    expect(announcement.value).toBe('')
    expect(document.activeElement?.tagName).toBe('H1')
    expect(document.activeElement?.getAttribute('tabindex')).toBe('-1')
  })

  it('no mueve el foco en la primera navegacion (la carga de la pagina)', async () => {
    const router = makeRouter()

    stop = bindScreenChange(router)
    ;(document.getElementById('menu') as HTMLElement).focus()
    await router.push('/a')
    await nextTick()
    await nextTick()

    expect(document.activeElement?.id).toBe('menu')
  })

  it('no lo mueve si solo cambia la query o el hash dentro de la misma pantalla', async () => {
    const router = makeRouter()

    stop = bindScreenChange(router)
    await router.push('/a')
    announce('Resultados: 3')
    ;(document.getElementById('menu') as HTMLElement).focus()

    await router.push('/a?page=2')
    await router.push('/a?page=2#report-exports')
    await nextTick()
    await nextTick()

    expect(document.activeElement?.id).toBe('menu')
    expect(announcement.value).toBe('Resultados: 3')
  })

  it('sin h1, el foco va al contenido principal', async () => {
    document.body.innerHTML = '<main id="main"><button id="menu">Menu</button></main>'
    const router = makeRouter()

    stop = bindScreenChange(router)
    await router.push('/a')
    await router.push('/b')
    await nextTick()
    await nextTick()

    expect(document.activeElement?.id).toBe('main')
  })
})
