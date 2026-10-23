// Titulo de la pestaña por pantalla (WCAG 2.4.2 «Página titulada», V7-PA-2, R7-PO-03).
//
// `applyBranding` pone el nombre de la instalacion como titulo y no sabe en que pantalla
// esta la persona. Aqui se compone `pantalla – nombre`, en el idioma que se esta mostrando,
// y se recompone SOLA cada vez que la marca se aplica (despues de que ella reescriba el
// titulo): quien lo usa no tiene que acordarse de llamar a ningun `refresh`.
//
// El titulo es siempre generico de la pantalla: nunca lleva el nombre de la persona cuya
// ficha se mira (minimizacion de datos personales). Compartido por el panel y el portal.
import { watch } from 'vue'
import type { Ref } from 'vue'

/** Separador entre la pantalla y la instalacion: raya corta, que un lector de pantalla pronuncia como pausa. */
export const TITLE_SEPARATOR = ' – '

/**
 * Lo unico que se necesita del router: la ruta actual y su `meta.titleKey` (clave i18n del
 * titulo de la pantalla; generico, nunca el nombre de una persona). Estructural y no el
 * `Router` de `vue-router`: cada SPA instala su propia copia y sus tipos no son intercambiables.
 */
export interface TitledRouter {
  readonly currentRoute: Readonly<
    Ref<{ readonly meta: { readonly titleKey?: string | undefined } }>
  >
}

export interface DocumentTitleBinding {
  /** Vuelve a escribir el titulo ahora. */
  readonly refresh: () => void
  readonly stop: () => void
}

export function bindDocumentTitle(options: {
  readonly router: TitledRouter
  readonly i18n: { readonly global: { t: (key: string) => string; locale: Ref<string> } }
  readonly applicationName: () => string
  /**
   * Cualquier cosa reactiva que cambie cuando la marca se vuelve a aplicar (p. ej. el objeto
   * `branding.current`): `applyBranding` reescribe el titulo aunque el nombre sea el mismo.
   */
  readonly brandingVersion?: () => unknown
  readonly document?: Document
}): DocumentTitleBinding {
  const doc = options.document ?? globalThis.document
  const { router, i18n } = options

  function refresh(): void {
    const key = router.currentRoute.value.meta.titleKey
    const name = options.applicationName()

    doc.title = key === undefined ? name : `${i18n.global.t(key)}${TITLE_SEPARATOR}${name}`
  }

  const stopScreen = watch(
    [() => router.currentRoute.value.meta.titleKey, () => i18n.global.locale.value],
    refresh,
    { immediate: true, flush: 'sync' },
  )
  // Despues de que la marca haya reescrito el titulo, en el ciclo posterior.
  const stopBranding = watch(
    [options.applicationName, options.brandingVersion ?? (() => null)],
    refresh,
    { flush: 'post' },
  )

  return {
    refresh,
    stop: () => {
      stopScreen()
      stopBranding()
    },
  }
}
