// Titulo de la pestaña por pantalla (WCAG 2.4.2 «Página titulada», V7-PA-2, R7-PO-03).
//
// El panel y el portal crean su estado de marca con `manageTitle: false`: `applyBranding` no
// toca `<title>`, asi que este modulo es su UNICO dueño. Compone `pantalla – nombre`, en el
// idioma que se esta mostrando, y se recompone solo cuando cambia la pantalla, el idioma o el
// nombre de la instalacion. Que la marca falle o llegue invalida no cambia nada: el titulo
// compuesto sigue ahi, sin vigilar el DOM.
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
  readonly document?: Document
}): DocumentTitleBinding {
  const doc = options.document ?? globalThis.document
  const { router, i18n } = options

  function composed(): string {
    const key = router.currentRoute.value.meta.titleKey
    const name = options.applicationName()

    return key === undefined ? name : `${i18n.global.t(key)}${TITLE_SEPARATOR}${name}`
  }

  function refresh(): void {
    doc.title = composed()
  }

  const stopScreen = watch(
    [() => router.currentRoute.value.meta.titleKey, () => i18n.global.locale.value],
    refresh,
    { immediate: true, flush: 'sync' },
  )
  const stopName = watch(options.applicationName, refresh, { flush: 'sync' })

  return {
    refresh,
    stop: () => {
      stopScreen()
      stopName()
    },
  }
}
