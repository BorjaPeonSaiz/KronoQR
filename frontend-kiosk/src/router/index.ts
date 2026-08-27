import type { RouteRecordRaw } from 'vue-router'
import { createRouter, createWebHistory } from 'vue-router'
import ScanView from '@/features/scan/ui/ScanView.vue'

// La pantalla de escaneo se importa de forma ESTATICA a proposito: es la unica
// que ve un empleado y la que decide el LCP del Anexo A. Todo lo demas que
// llegue —diagnostico (RF-KI-08), PIN de respaldo (tarea 1.12)— va con
// `import()` para que no compita con ella por el arranque.
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
]

export function createAppRouter(): ReturnType<typeof createRouter> {
  return createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes,
  })
}
