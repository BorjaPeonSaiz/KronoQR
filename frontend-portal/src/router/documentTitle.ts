// Titulo de la pestaña por pantalla (WCAG 2.4.2 «Página titulada», R7-PO-03).
//
// `applyBranding` de web-kit pone el nombre de la instalacion como titulo y no
// sabe en que pantalla esta la persona. Aqui se compone `pantalla · nombre`,
// en el idioma que se esta mostrando. Como la marca vuelve a escribir el titulo
// cada vez que se aplica, quien la aplica llama despues a `refresh()`.
import type { Router } from 'vue-router'
import { watch } from 'vue'
import type { createAppI18n } from '@/shared/i18n'

export interface DocumentTitleBinding {
  /** Vuelve a escribir el titulo (p. ej. despues de aplicar la marca). */
  readonly refresh: () => void
  readonly stop: () => void
}

export function bindDocumentTitle(options: {
  readonly router: Router
  readonly i18n: ReturnType<typeof createAppI18n>
  readonly applicationName: () => string
  readonly document?: Document
}): DocumentTitleBinding {
  const doc = options.document ?? globalThis.document
  const { router, i18n } = options

  function refresh(): void {
    const key = router.currentRoute.value.meta.titleKey
    const name = options.applicationName()

    doc.title = key === undefined ? name : `${i18n.global.t(key)} · ${name}`
  }

  const stop = watch(
    [() => router.currentRoute.value.meta.titleKey, () => i18n.global.locale.value],
    refresh,
    { immediate: true, flush: 'sync' },
  )

  return { refresh, stop }
}
