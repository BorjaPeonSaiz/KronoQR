// Battery Status API (RF-PA-07, tarea 3.3).
//
// Alimenta dos cosas con la MISMA implementacion: el latido (`battery_level` y
// `battery_charging` en `KioskHeartbeatRequest`, para que el panel vea si una
// tablet colgada de la pared se esta quedando sin cargador) y la pantalla de
// diagnostico (RF-KI-08). NO ES UN SINGLETON: cada llamada a
// `useBatteryStatus()` (una por pantalla que la usa -`ScanView.vue`,
// `PinView.vue`, `DiagnosticsView.vue`-) pide su propio `navigator.getBattery()`
// y anade sus propios oyentes de `levelchange`/`chargingchange`, y los suelta
// en `onUnmounted`. Eso es deliberado y barato: `getBattery()` devuelve
// siempre la MISMA instancia de `BatteryManager` por pagina (la especificacion
// la memoiza), asi que anadir un segundo oyente no abre una segunda lectura
// del sensor, solo un segundo callback sobre el mismo evento del navegador.
//
// SOLO CHROME EN ANDROID LA OFRECE. `supported` es `false` en cualquier otro
// navegador -incluido el que ejecuta las pruebas-, y eso NO es una averia
// (mismo criterio que el resto de esta tarea): `level`/`charging` se quedan en
// `null` y el latido, sencillamente, no manda esos dos campos
// (`buildHeartbeatBody`, `exactOptionalPropertyTypes`).

import type { Ref } from 'vue'
import { onUnmounted, readonly, ref } from 'vue'

export interface BatteryStatusController {
  readonly supported: boolean
  /** 0 a 100, redondeado. `null` sin dato (navegador sin la API, o aun sin resolver). */
  readonly level: Readonly<Ref<number | null>>
  readonly charging: Readonly<Ref<boolean | null>>
}

export function useBatteryStatus(): BatteryStatusController {
  const supported = typeof navigator !== 'undefined' && typeof navigator.getBattery === 'function'
  const level = ref<number | null>(null)
  const charging = ref<boolean | null>(null)

  let manager: BatteryManager | null = null

  function sync(): void {
    if (manager === null) return
    level.value = Math.round(manager.level * 100)
    charging.value = manager.charging
  }

  if (supported) {
    void navigator
      .getBattery?.()
      .then((battery) => {
        manager = battery
        sync()
        battery.addEventListener('levelchange', sync)
        battery.addEventListener('chargingchange', sync)
      })
      .catch(() => {
        // Sin lectura de bateria: se queda en `null`, no es un fallo que reportar.
      })
  }

  onUnmounted(() => {
    if (manager === null) return
    manager.removeEventListener('levelchange', sync)
    manager.removeEventListener('chargingchange', sync)
  })

  return { supported, level: readonly(level), charging: readonly(charging) }
}
