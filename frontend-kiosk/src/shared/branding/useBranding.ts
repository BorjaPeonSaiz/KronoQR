// Marca blanca del quiosco en tiempo de ejecucion (RF-PD-08, tarea 5.8).
//
// LA PANTALLA NUNCA ESPERA A ESTO (regla dura 19). El nombre y el logotipo de
// la instalacion son cosmeticos, no un fichaje: si la red esta caida, si el
// servidor contesta mal formado o si `localStorage` esta lleno, el quiosco
// sigue mostrando la marca del PRODUCTO (o la ultima que se pudo guardar) y
// sigue fichando exactamente igual. Nada de lo que hay aqui lanza, y nada
// espera un `await` en el camino del escaneo: `applyCachedBranding()` es
// sincrona y `useBranding()` dispara su primera peticion en segundo plano.
//
// DOS MOMENTOS, DOS FUNCIONES:
//   1. `applyCachedBranding()` — la llama `main.ts` ANTES de montar la
//      aplicacion. Lee `localStorage`, valida con `parseBranding` y pinta
//      tokens/titulo/favicon con lo que hubiera, sin tocar la red. Es lo que
//      evita un parpadeo "KronoQR" -> "Hotel Marina" en el primer fotograma.
//   2. `useBranding()` — la usa `ScanView.vue` (que ya tiene `api` y
//      `connectivity`). Aplica lo mismo que el paso 1 al crearse (por si se
//      monta sin pasar por `main.ts`, como en las pruebas unitarias), pide la
//      marca al servidor, la guarda si cambia y se vuelve a pedir cada vez
//      que la conectividad pasa a "online".
//
// QUE SE GUARDA EN DISCO. El JSON CRUDO del contrato (snake_case), tal cual
// lo valida `parseBranding` antes de guardarlo: es la unica forma de que la
// copia en disco y la respuesta del servidor sean intercambiables sin una
// conversion de ida y vuelta. El logotipo NO viaja aqui (sus bytes no caben
// en `localStorage`); esos los sirve `GET /api/v1/branding/logo` cacheado por
// el service worker (`vite.config.ts`, `sw/README.md`).

import {
  applyBranding,
  parseBranding,
  PRODUCT_BRANDING,
  type Branding,
} from '@kronoqr/web-kit/branding'
import { readonly, ref, watch, type Ref } from 'vue'
import { createApiClient, type ApiClient } from '@/shared/api/client'
import type { ConnectivityController } from '@/shared/connectivity/useConnectivity'

const STORAGE_KEY = 'kronoqr.kiosk.branding'

function safeStorage(): Storage | null {
  try {
    return globalThis.localStorage
  } catch {
    return null
  }
}

/** Lee la copia guardada y la valida. `null` si no hay, esta rota o es imposible de leer. */
function readCachedBranding(storage: Storage | null = safeStorage()): Branding | null {
  if (storage === null) return null
  try {
    const raw = storage.getItem(STORAGE_KEY)
    if (raw === null) return null
    return parseBranding(JSON.parse(raw))
  } catch {
    // JSON roto, un valor de una version anterior del contrato, o el
    // almacenamiento deshabilitado: se trata igual que "no hay copia".
    return null
  }
}

function writeCachedBranding(raw: unknown, storage: Storage | null = safeStorage()): void {
  if (storage === null) return
  try {
    storage.setItem(STORAGE_KEY, JSON.stringify(raw))
  } catch {
    // Disco lleno o almacenamiento deshabilitado: se pierde la copia para la
    // proxima vez, no la marca de esta sesion (ya esta aplicada en memoria).
  }
}

/**
 * Aplica la marca cacheada (o el producto si no hay ninguna) SIN RED y SIN
 * ESPERAR NADA. Se llama una vez, en `main.ts`, antes de `app.mount()`: es lo
 * que deja `document.title`, el favicon y los tokens `--kq-*` correctos desde
 * el primer fotograma, tanto si la tablet nunca ha visto un servidor como si
 * lo vio hace media hora.
 */
export function applyCachedBranding(): void {
  applyBranding(readCachedBranding() ?? PRODUCT_BRANDING, { mode: 'kiosk' })
}

export interface UseBrandingOptions {
  /** Por defecto, un cliente propio: la marca es publica y no necesita el de `ScanView`. */
  readonly api?: ApiClient
  /**
   * Si se da, la marca se vuelve a pedir cada vez que pasa a "online" (doc
   * "Frontends — común": "se vuelve a pedir... al recuperar la red"). Sin
   * ella, solo se pide una vez al crear el composable.
   */
  readonly connectivity?: ConnectivityController
}

export interface BrandingController {
  /** La marca vigente: la del producto hasta que llega -si llega- la del cliente. */
  readonly current: Readonly<Ref<Branding>>
  /** Fuerza una peticion nueva. Nunca lanza; un fallo se ignora en silencio. */
  refresh(): Promise<void>
}

export function useBranding(options: UseBrandingOptions = {}): BrandingController {
  const api = options.api ?? createApiClient()
  // Se lee y se aplica YA, sin esperar al primer `refresh()`: si este
  // composable se usa fuera de `main.ts` (las pruebas unitarias montan
  // `ScanView` directamente), la marca cacheada tiene que estar puesta igual.
  const current = ref<Branding>(readCachedBranding() ?? PRODUCT_BRANDING)
  applyBranding(current.value, { mode: 'kiosk' })

  async function refresh(): Promise<void> {
    try {
      const result = await api.fetchBranding()
      if (result.outcome !== 'ok') return // offline, timeout, 429, 5xx... se queda lo que habia.

      const parsed = parseBranding(result.data)
      if (parsed === null) return // el servidor contesto 200 con algo que no encaja: se ignora.

      current.value = parsed
      applyBranding(parsed, { mode: 'kiosk' })
      writeCachedBranding(result.data)
    } catch {
      // Nunca debe llegar hasta aqui (el cliente HTTP no lanza), pero un
      // fallo de la marca jamas puede tumbar la pantalla de fichaje.
    }
  }

  void refresh()

  if (options.connectivity !== undefined) {
    watch(options.connectivity.status, (status) => {
      if (status === 'online') void refresh()
    })
  }

  return { current: readonly(current), refresh }
}
