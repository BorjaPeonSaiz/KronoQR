// Que pasa cuando la sesion termina sola.
//
// Tres caminos llevan al mismo sitio y los ata este fichero, que es el unico
// que conoce a la vez la tienda, el router y el cliente HTTP (el cliente no
// conoce la tienda y la tienda no conoce al router, para no crear ciclos):
//
//  - un `401` con la sesion abierta (token revocado o vencido en el servidor);
//  - el temporizador de la tienda, que salta en `expires_at`;
//  - volver a la pestana, donde un movil que durmio pudo saltarse el temporizador.
//
// En los tres la tienda queda con `endedReason = 'expired'` y aqui se lleva a la
// persona al acceso guardando la ruta en la que estaba (`redirect`), que
// `LoginView` respeta al entrar. «Salir» NO pasa por aqui: deja `endedReason` a
// `null` y la navegacion la hace el propio boton.
import { setUnauthenticatedHandler } from '@kronoqr/web-kit/http'
import { watch } from 'vue'
import type { Router } from 'vue-router'
import type { useSessionStore } from '@/features/login/session.store'

export interface SessionLifecycleOptions {
  readonly router: Router
  readonly session: ReturnType<typeof useSessionStore>
  readonly document?: Document
}

export function bindSessionLifecycle(options: SessionLifecycleOptions): () => void {
  const { router, session } = options
  const doc = options.document ?? globalThis.document

  setUnauthenticatedHandler(() => session.expire())

  const stopWatching = watch(
    () => session.endedReason,
    (reason) => {
      if (reason !== 'expired') {
        return
      }

      const current = router.currentRoute.value

      // En el acceso no hay nada a lo que volver: ahi se queda, con su aviso.
      if (current.meta.public === true) {
        return
      }

      void router.push({ name: 'login', query: { redirect: current.fullPath } })
    },
    { flush: 'sync' },
  )

  const onVisible = (): void => {
    if (doc.visibilityState === 'visible') {
      session.expireIfDue()
    }
  }

  doc.addEventListener('visibilitychange', onVisible)

  return () => {
    stopWatching()
    doc.removeEventListener('visibilitychange', onVisible)
    setUnauthenticatedHandler(() => {})
  }
}
