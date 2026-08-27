// Estado de conexion, visible siempre (RF-KI-04).
//
// «La plantilla debe poder confiar en lo que ve.» Un indicador que dice «en
// linea» cuando no lo esta es peor que no tener indicador: convierte un
// problema visible en una reclamacion tres semanas despues.
//
// ALCANCE. Esta tarea (1.8) aporta la mitad honesta: en linea / sin conexion.
// El CONTADOR DE PENDIENTES es de la tarea 1.9, que es quien tiene la cola; aqui
// se deja el hueco (`pendingCount`) para que la 1.9 lo rellene sin rehacer la
// pantalla. Mientras tanto lo alimenta el envio directo, que ya sabe cuantos
// escaneos no ha podido resolver.
//
// `navigator.onLine` solo es fiable en `false`. En `true` significa «hay una
// interfaz de red levantada», que en un hotel con el router caido es
// exactamente lo que no interesa saber. Por eso el estado tambien admite que lo
// corrija quien de verdad habla con el servidor.

import type { Ref } from 'vue'
import { onUnmounted, readonly, ref } from 'vue'

export type ConnectivityStatus = 'online' | 'offline'

export interface ConnectivityController {
  readonly status: Readonly<Ref<ConnectivityStatus>>
  readonly pendingCount: Readonly<Ref<number>>
  /** Lo llama quien habla con el servidor: es la unica senal fiable de que hay red. */
  reportReachability(reachable: boolean): void
  setPendingCount(count: number): void
}

function currentStatus(): ConnectivityStatus {
  if (typeof navigator === 'undefined') return 'online'
  return navigator.onLine === false ? 'offline' : 'online'
}

export function useConnectivity(): ConnectivityController {
  const status = ref<ConnectivityStatus>(currentStatus())
  const pendingCount = ref(0)

  const sync = (): void => {
    status.value = currentStatus()
  }

  if (typeof window !== 'undefined') {
    window.addEventListener('online', sync)
    window.addEventListener('offline', sync)
  }

  onUnmounted(() => {
    if (typeof window !== 'undefined') {
      window.removeEventListener('online', sync)
      window.removeEventListener('offline', sync)
    }
  })

  return {
    status: readonly(status),
    pendingCount: readonly(pendingCount),
    reportReachability(reachable) {
      status.value = reachable ? 'online' : 'offline'
    },
    setPendingCount(count) {
      pendingCount.value = Math.max(0, count)
    },
  }
}
