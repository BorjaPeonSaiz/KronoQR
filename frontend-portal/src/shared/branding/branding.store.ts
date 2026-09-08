// Estado de la marca en tiempo de ejecucion (RF-PD-08, tarea 5.8, regla dura 13).
//
// La logica -empezar en `PRODUCT_BRANDING`, pintar de inmediato sin esperar a
// la red, pedir `GET /api/v1/branding` sin bloquear la primera pantalla y
// nunca lanzar- es identica a la del panel: vive una sola vez en
// `@kronoqr/web-kit/brandingState` (ADR-036) porque escrita dos veces ya
// habia divergido. Este fichero solo la envuelve en un store de Pinia.
import { createBrandingState } from '@kronoqr/web-kit/brandingState'
import { defineStore } from 'pinia'

export const useBrandingStore = defineStore('portal-branding', () =>
  createBrandingState({ mode: 'light' }),
)
