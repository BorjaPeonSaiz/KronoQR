// Andamiaje comun de las pruebas de componente.
//
// Monta la vista con lo mismo que le da la aplicacion real —Pinia, router,
// i18n y la cache de consultas— y sustituye `fetch` por un doble explicito. Las
// pruebas describen respuestas de la API, no interioridades del cliente: si
// mañana cambia como se envia una peticion, siguen valiendo.
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import type { ComponentMountingOptions } from '@vue/test-utils'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Component } from 'vue'
import { vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { Router } from 'vue-router'
import { routes } from '@/router'
import { createAppI18n } from '@/shared/i18n'
import type { AppLocale } from '@/shared/i18n'

export function createTestPinia(): ReturnType<typeof createPinia> {
  const pinia = createPinia()

  setActivePinia(pinia)

  return pinia
}

export function createTestRouter(): Router {
  return createRouter({ history: createMemoryHistory(), routes })
}

function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0, staleTime: 0 },
      mutations: { retry: false },
    },
  })
}

export interface HarnessOptions<Props> {
  props?: Props
  locale?: AppLocale
  router?: Router
  pinia?: ReturnType<typeof createPinia>
}

/** Monta un componente con el mismo entorno que la aplicacion. */
export async function mountView<Props extends Record<string, unknown>>(
  component: Component,
  options: HarnessOptions<Props> = {},
): Promise<ReturnType<typeof mount>> {
  const router = options.router ?? createTestRouter()
  const pinia = options.pinia ?? createTestPinia()

  // `isReady()` solo se resuelve despues de la primera navegacion, y esa la
  // dispara `app.use(router)` o un `push`. Sin este empujon la espera no termina
  // nunca. `/forbidden` es una ruta que no pide datos a nadie.
  if (router.currentRoute.value.name === undefined) {
    await router.push('/forbidden')
  }

  await router.isReady()

  const mountingOptions = {
    props: options.props,
    global: {
      plugins: [
        pinia,
        router,
        createAppI18n(options.locale ?? 'es'),
        [VueQueryPlugin, { queryClient: createTestQueryClient() }],
      ],
    },
  } as unknown as ComponentMountingOptions<Component>

  return mount(component, mountingOptions)
}

/** Una respuesta JSON del servidor. */
export function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

/** Una respuesta `application/problem+json` (RFC 9457), como las del contrato. */
export function problemResponse(
  status: number,
  type: string,
  extra: Record<string, unknown> = {},
): Response {
  return new Response(JSON.stringify({ type, title: 'Problema', status, ...extra }), {
    status,
    headers: { 'Content-Type': 'application/problem+json' },
  })
}

export type FetchHandler = (
  url: string,
  init: RequestInit | undefined,
) => Response | Promise<Response>

/** Sustituye `fetch` por un enrutador de dobles. Devuelve el espia. */
export function stubFetch(handler: FetchHandler): ReturnType<typeof vi.fn> {
  const spy = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) =>
    handler(String(input), init),
  )

  vi.stubGlobal('fetch', spy)

  return spy as unknown as ReturnType<typeof vi.fn>
}

/** Espera a que se resuelvan las consultas pendientes y se repinte el DOM. */
export async function settle(times = 4): Promise<void> {
  for (let index = 0; index < times; index += 1) {
    await Promise.resolve()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}
