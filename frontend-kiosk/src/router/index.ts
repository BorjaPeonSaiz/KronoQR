import type { RouteRecordRaw } from 'vue-router'
import { createRouter, createWebHistory } from 'vue-router'
import { readDeviceToken } from '@/shared/telemetry/deviceIdentity'
import ScanView from '@/features/scan/ui/ScanView.vue'

// La pantalla de escaneo se importa de forma ESTATICA a proposito: es la unica
// que ve un empleado y la que decide el LCP del Anexo A. Todo lo demas que
// llegue —diagnostico (RF-KI-08), PIN de respaldo (tarea 1.12), emparejamiento
// (RF-PD-06, tarea 5.6)— va con `import()` para que no compita con ella por el
// arranque.
export const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: ScanView,
  },
  {
    path: '/pin',
    name: 'pin',
    // Cargado bajo demanda (tarea 1.12): solo lo pide quien pulsa «¿Sin
    // tarjeta?», y ese boton solo existe si la instalacion ofrece esta via.
    component: () => import('@/features/pin/ui/PinView.vue'),
  },
  {
    path: '/pair',
    name: 'pair',
    // Solo la ve una tablet sin vincular (o recien desvinculada, ver
    // `deviceRevocation.ts`): cargarla siempre penalizaria el LCP de la
    // pantalla que SI ve un empleado cada vez (tarea 5.6, Anexo A).
    component: () => import('@/features/pairing/ui/PairingView.vue'),
  },
]

export function createAppRouter(): ReturnType<typeof createRouter> {
  const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes,
  })

  // Guardia de emparejamiento (RF-PD-06, regla dura 19). Sin token de
  // dispositivo no hay ninguna otra pantalla que tenga sentido: `/scan` y
  // `/kiosk/*` responderian `401` a todo. Y al reves, una tablet YA
  // emparejada no vuelve a `/pair` por accidente (recarga con la URL en el
  // historial, enlace directo): la manda a la de fichaje, que es la que
  // existe para ver un empleado.
  //
  // `readDeviceToken()` se lee en CADA navegacion, no una vez al arrancar:
  // es lo que hace que, tras una desvinculacion (que limpia el token y
  // navega aqui ella misma), cualquier intento posterior de volver a `/` se
  // quede en `/pair` hasta que se complete un emparejamiento nuevo.
  router.beforeEach((to) => {
    const paired = readDeviceToken() !== null
    const goingToPairing = to.name === 'pair'

    if (!paired && !goingToPairing) return { name: 'pair' }
    if (paired && goingToPairing) return { name: 'home' }
    return true
  })

  return router
}
