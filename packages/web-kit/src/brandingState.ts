// Estado de la marca para el panel y el portal (RF-PD-08, ADR-036).
//
// Las dos SPA claras hacen exactamente lo mismo: arrancan con la marca del
// producto, piden `GET /api/v1/branding` sin bloquear la primera pintura y
// aplican lo que llega —o lo que ya tenian— sobre los tokens `--kq-*`. Escrito
// dos veces ya habia divergido (una aplicaba solo si la respuesta era valida,
// la otra siempre), asi que vive aqui una sola vez. Cada SPA lo envuelve en su
// store de Pinia con dos lineas; Pinia no es dependencia de este paquete.
//
// El quiosco NO usa esto a proposito: tiene su propio cliente sin excepciones
// (`ApiResult`), guarda la copia en localStorage para arrancar sin red y la
// vuelve a pedir al recuperar la conexion (regla dura 19).
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
  /** La marca vigente. Empieza en `PRODUCT_BRANDING`. */
  readonly current: Readonly<Ref<Branding>>
  /** Vuelve a pintar `current` sobre el documento. */
  readonly apply: () => void
  /** Pide la marca al servidor, la guarda si es valida y la aplica. Nunca lanza. */
  readonly load: () => Promise<void>
}

export interface BrandingStateOptions {
  readonly mode: ThemeMode
  readonly document?: Document
  /** Por defecto, `GET /api/v1/branding` anonimo con el cliente HTTP compartido. */
  readonly fetchBranding?: () => Promise<unknown>
}

export function createBrandingState(options: BrandingStateOptions): BrandingState {
  const current = ref<Branding>(PRODUCT_BRANDING)
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
      if (parsed !== null) current.value = parsed
    } catch {
      // Sin red o sin servidor se queda lo que hubiera: el producto o la
      // ultima marca valida. La interfaz no tiene por que enterarse.
    } finally {
      apply()
    }
  }

  return { current: readonly(current), apply, load }
}
