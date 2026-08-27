// Guarda de rutas.
//
// Hace una sola cosa y no es seguridad: sin sesion valida, manda al acceso y se
// acuerda de a donde iba. La autorizacion real la aplica el servidor en cada
// endpoint (regla dura 18) — aqui solo se evita la frustracion de entrar a una
// pantalla que devolveria 401.
//
// No hay ambitos que repartir: el token del portal es siempre `self:read` y las
// tres pantallas son suyas por igual. No existe, por tanto, ningun «sin
// permiso» dentro de la aplicacion autenticada.
import type { Router } from 'vue-router'
import { useSessionStore } from '@/features/login/session.store'

export function registerAuthGuard(router: Router): void {
  router.beforeEach((to) => {
    const session = useSessionStore()

    if (to.meta.public === true) {
      return true
    }

    if (!session.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }

    return true
  })
}
