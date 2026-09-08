// Estado de la marca de la instalacion, compartido por toda la aplicacion
// (RF-PD-08, tarea 5.8, regla dura 13; ADR-036).
//
// La logica de verdad vive en `@kronoqr/web-kit/brandingState`
// (`createBrandingState`), compartida con el portal del empleado: arranca en
// `PRODUCT_BRANDING`, pide `GET /api/v1/branding` sin bloquear la primera
// pintura, nunca lanza (sin red, con un `429` o ante una respuesta con otra
// forma se queda la marca que hubiera) y aplica SIEMPRE al terminar -tambien
// tras un fallo-, para que el titulo de la pestaña y el favicon del producto
// aparezcan incluso sin servidor. Antes esta logica estaba escrita a mano en
// cada SPA clara y habia divergido: el panel solo aplicaba una respuesta
// valida, el portal aplicaba siempre. Ahora hay una sola fuente.
//
// Este fichero solo la envuelve en un store de Pinia, para que el resto del
// panel -`AppShellView`, `LoginView`, `BrandingView`- la consuma como
// cualquier otro estado compartido (`useBrandingStore().current`, `.load()`).
import { createBrandingState } from '@kronoqr/web-kit/brandingState'
import { defineStore } from 'pinia'

export const useBrandingStore = defineStore('branding', () =>
  createBrandingState({ mode: 'light' }),
)
