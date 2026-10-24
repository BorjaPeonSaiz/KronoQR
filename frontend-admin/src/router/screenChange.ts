// El cambio de pantalla se anuncia (WCAG 2.4.3, 4.1.3).
//
// Un panel de una sola pagina no recarga: sin esto, quien usa un lector de
// pantalla activa un enlace del menu y no oye nada, y la region viva global sigue
// diciendo lo ultimo que se anuncio en la pantalla anterior. Al cambiar de
// pantalla:
//  1. se vacia la region viva global (no se arrastra un texto viejo), y
//  2. el foco pasa al `<h1>` de la pantalla nueva. Ese ES el anuncio: el lector
//     lee «Incidencias, encabezado de nivel 1». No se escribe ademas en la region
//     viva, para que no suene dos veces.
//
// No se hace en la primera navegacion (la carga de la pagina, que el navegador
// ya anuncia) ni cuando solo cambia la query o el hash dentro de la misma
// pantalla (filtros, «#report-exports»).
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { nextTick } from 'vue'
import { START_LOCATION, type Router } from 'vue-router'

/** El `<h1>` del contenido (o el propio `<main>`): sirve al acceso y al asistente, que no tienen `#main`. */
export function contentHeading(doc: Document = document): HTMLElement | null {
  const main = doc.querySelector<HTMLElement>('main')

  return main?.querySelector<HTMLElement>('h1') ?? main
}

let changing = false

/** Hay un cambio de pantalla en curso: `focusRecovery` se aparta para no focar dos veces. */
export function isScreenChanging(): boolean {
  return changing
}

export function bindScreenChange(router: Router, doc: Document = document): () => void {
  const stopBefore = router.beforeEach(() => {
    changing = true
  })
  const stopError = router.onError(() => {
    changing = false
  })
  const stopAfter = router.afterEach((to, from, failure) => {
    if (failure !== undefined || from === START_LOCATION || to.path === from.path) {
      changing = false

      return
    }

    clearAnnouncement()

    void nextTick().then(() => {
      changing = false
      const heading = contentHeading(doc)

      if (heading === null) {
        return
      }

      if (!heading.hasAttribute('tabindex')) {
        heading.setAttribute('tabindex', '-1')
      }

      heading.focus({ preventScroll: false })
    })
  })

  return () => {
    stopBefore()
    stopError()
    stopAfter()
    changing = false
  }
}
