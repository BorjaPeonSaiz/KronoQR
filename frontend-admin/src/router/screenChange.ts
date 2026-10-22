// El cambio de pantalla se anuncia (WCAG 2.4.3, 4.1.3; V7-PA-3).
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

export function bindScreenChange(router: Router, doc: Document = document): () => void {
  return router.afterEach((to, from, failure) => {
    if (failure !== undefined || from === START_LOCATION || to.path === from.path) {
      return
    }

    clearAnnouncement()

    void nextTick().then(() => {
      const main = doc.getElementById('main')
      const heading = main?.querySelector<HTMLElement>('h1') ?? main

      if (heading === null || heading === undefined) {
        return
      }

      if (!heading.hasAttribute('tabindex')) {
        heading.setAttribute('tabindex', '-1')
      }

      heading.focus({ preventScroll: false })
    })
  })
}
