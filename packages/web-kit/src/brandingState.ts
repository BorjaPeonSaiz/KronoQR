// Estado de la marca para el panel y el portal (RF-PD-08, ADR-036).
//
// Las dos SPA claras hacen exactamente lo mismo: arrancan con la marca del
// producto, piden `GET /api/v1/branding` sin bloquear la primera pintura y
// aplican lo que llega —o lo que ya tenian— sobre los tokens `--kq-*`. Escrito
// dos veces ya habia divergido (una aplicaba solo si la respuesta era valida,
// la otra siempre), asi que vive aqui una sola vez. Cada SPA lo envuelve en su
// store de Pinia con dos lineas; Pinia no es dependencia de este paquete.
//
// Cache (MB4). Igual que el quiosco, se guarda la ultima marca valida en
// `localStorage`: al crear el estado se lee y `apply()` pinta ESA marca en el
// primer fotograma, sin parpadeo del producto; al llegar la respuesta se
// actualiza y se vuelve a guardar. La copia es un estorbo prescindible, nunca
// un requisito: `localStorage` inaccesible o lleno, un JSON roto, un valor de
// otra version del contrato o una copia desmesurada se tratan como «no hay
// copia». Se guarda la forma del contrato, ya validada, nunca la respuesta
// cruda: lo que no cabe en `Branding` no se persiste.
//
// El quiosco NO usa esto a proposito: tiene su propio cliente sin excepciones
// (`ApiResult`) y la vuelve a pedir al recuperar la conexion (regla dura 19).
//
// Regla que gobierna la carga: un fallo de red, un 429 o una respuesta con
// otra forma dejan la marca que hubiera —el producto, o la ultima valida— y
// NUNCA lanzan. Y se aplica SIEMPRE al terminar, tambien tras el fallo: el
// titulo de la pestaña y el favicon del producto tienen que aparecer aunque
// el servidor no conteste.

import { readonly, ref, type Ref } from 'vue'
import {
  applyBranding,
  parseBranding,
  PRODUCT_BRANDING,
  type Branding,
  type ThemeMode,
} from './branding'
import { requestJson } from './http'

export interface BrandingState {
  /** La marca vigente. La copia guardada, o `PRODUCT_BRANDING` si no hay. */
  readonly current: Readonly<Ref<Branding>>
  /** Vuelve a pintar `current` sobre el documento. */
  readonly apply: () => void
  /** Pide la marca al servidor, la guarda si es valida y la aplica. Nunca lanza. */
  readonly load: () => Promise<void>
}

export interface BrandingStateOptions {
  readonly mode: ThemeMode
  readonly document?: Document
  /** Donde se guarda la copia. Por defecto, la clave compartida por panel y portal. */
  readonly storageKey?: string
  /** Por defecto, `globalThis.localStorage`; `null` desactiva la cache. */
  readonly storage?: Storage | null
  /** Por defecto, `GET /api/v1/branding` anonimo con el cliente HTTP compartido. */
  readonly fetchBranding?: () => Promise<unknown>
}

/** Panel y portal pintan lo mismo (`GET /api/v1/branding`) y comparten copia. */
export const BRANDING_CACHE_KEY = 'kronoqr.branding'

/** Tope de lo que se lee de disco: una marca real ocupa unos cientos de bytes. */
const MAX_CACHED_BYTES = 4096

function safeStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null
  } catch {
    return null
  }
}

/** La forma del contrato, de la que `parseBranding` es la inversa. */
function toContract(branding: Branding): Record<string, unknown> {
  return {
    application_name: branding.applicationName,
    accent_color: branding.accentColor,
    logo_url: branding.logoUrl,
    locales: { default: branding.locales.default, available: [...branding.locales.available] },
    privacy_notice: {
      controller_name: branding.privacyNotice.controllerName,
      policy_url: branding.privacyNotice.policyUrl,
    },
  }
}

/** Lee y valida la copia. `null` si no hay, esta rota, es enorme o no se puede leer. */
export function readCachedBranding(storage: Storage | null, key: string): Branding | null {
  if (storage === null) return null
  try {
    const raw = storage.getItem(key)
    if (raw === null || raw.length > MAX_CACHED_BYTES) return null
    return parseBranding(JSON.parse(raw))
  } catch {
    return null
  }
}

/** Guarda la marca vigente. Un fallo (disco lleno, almacenamiento bloqueado) se ignora. */
export function writeCachedBranding(
  storage: Storage | null,
  key: string,
  branding: Branding,
): void {
  if (storage === null) return
  try {
    const raw = JSON.stringify(toContract(branding))
    if (raw.length <= MAX_CACHED_BYTES) storage.setItem(key, raw)
  } catch {
    // La marca de esta sesion ya esta aplicada en memoria; se pierde solo la copia.
  }
}

export function createBrandingState(options: BrandingStateOptions): BrandingState {
  const storage = options.storage === undefined ? safeStorage() : options.storage
  const storageKey = options.storageKey ?? BRANDING_CACHE_KEY
  const current = ref<Branding>(readCachedBranding(storage, storageKey) ?? PRODUCT_BRANDING)
  const fetchBranding =
    options.fetchBranding ??
    ((): Promise<unknown> => requestJson<unknown>('/api/v1/branding', { anonymous: true }))

  function apply(): void {
    applyBranding(
      current.value,
      options.document === undefined
        ? { mode: options.mode }
        : { mode: options.mode, document: options.document },
    )
  }

  async function load(): Promise<void> {
    try {
      const parsed = parseBranding(await fetchBranding())
      if (parsed !== null) {
        current.value = parsed
        writeCachedBranding(storage, storageKey, parsed)
      }
    } catch {
      // Sin red o sin servidor se queda lo que hubiera: el producto o la
      // ultima marca valida. La interfaz no tiene por que enterarse.
    } finally {
      apply()
    }
  }

  return { current: readonly(current), apply, load }
}
