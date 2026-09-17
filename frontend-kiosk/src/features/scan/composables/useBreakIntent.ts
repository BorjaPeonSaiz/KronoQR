// Enchufe de Vue sobre el controlador puro de `application/breakIntent.ts`.
// Reactividad para la plantilla, nada mas: la logica de armar/desarmar vive
// en el controlador (testeable sin Vue, sin IndexedDB, sin camara).
//
// SE DESARMA AL DESMONTAR (revision de la segunda vuelta, seguridad): la
// intencion es de la TABLET, no de la persona, y en una cola de cambio de
// turno otro empleado puede pasar su tarjeta dentro de los 10 s de armado.
// Cambiar de pantalla -de `ScanView` a `PinView` o al diagnostico, o al
// reves- es la senal mas clara de que quien armo el boton ya no esta
// delante, asi que el arme NO sobrevive a la navegacion: cada pantalla que
// se monta empieza siempre desarmada, aunque el controlador sea un
// singleton (compartido para que dos pantallas vean el MISMO estado mientras
// ambas estan montadas, no para que sobreviva a un cambio de pantalla).

import type { Ref } from 'vue'
import { onUnmounted, readonly, ref } from 'vue'
import type { ScanIntent } from '@/shared/api/types'
import type { BreakIntentControllerOptions } from '../application/breakIntent'
import { getBreakIntentController } from '../application/breakIntent'

export interface UseBreakIntentOptions extends BreakIntentControllerOptions {
  /**
   * Texto para la region viva mientras esta armado (RF-KI-06, region viva
   * SIEMPRE montada). Cada pantalla dice algo distinto («Pasa tu tarjeta…»
   * en `ScanView`, «Introduce tu código…» en `PinView`), por eso se pide
   * como funcion en vez de fijarse aqui.
   */
  readonly armedAnnouncement?: () => string
  /**
   * Texto al desarmar (revision de la segunda vuelta: hay que ANUNCIAR el
   * desarme -uso, tiempo, escaneo no encolado o cambio de pantalla-, no solo
   * retirar el nodo de la region viva, que muchos lectores de pantalla no
   * anuncian).
   */
  readonly disarmedAnnouncement?: () => string
}

export interface UseBreakIntent {
  /** `true` mientras el boton esta armado y el siguiente fichaje se encolara como pausa. */
  readonly armed: Readonly<Ref<boolean>>
  /**
   * Texto de la region viva `role="status" aria-live="polite"`, que la
   * plantilla monta SIEMPRE (nunca con `v-if`/`v-show` sobre el nodo entero):
   * solo asi un lector de pantalla anuncia tambien el CAMBIO a desarmado, no
   * solo la aparicion del aviso al armar. Cadena vacia antes del primer
   * cambio.
   */
  readonly announcement: Readonly<Ref<string>>
  arm(): void
  disarm(): void
  /** Ver `BreakIntentController.consumeIntent`. */
  consumeIntent(): ScanIntent
}

export function useBreakIntent(options: UseBreakIntentOptions = {}): UseBreakIntent {
  const controller = getBreakIntentController(options)
  const armed = ref(controller.isArmed())
  const announcement = ref('')

  const unsubscribe = controller.onChange((value) => {
    armed.value = value
    announcement.value = value
      ? (options.armedAnnouncement?.() ?? '')
      : (options.disarmedAnnouncement?.() ?? '')
  })

  onUnmounted(() => {
    unsubscribe()
    // Cambio de pantalla: ver la nota de cabecera. Idempotente si ya estaba
    // desarmado (por uso, por tiempo o por un escaneo no encolado). No pasa
    // por el `onChange` de arriba (ya desenganchado): la pantalla que se va
    // no tiene nada que anunciar.
    controller.disarm()
  })

  return {
    armed: readonly(armed),
    announcement: readonly(announcement),
    arm: () => controller.arm(),
    disarm: () => controller.disarm(),
    consumeIntent: () => controller.consumeIntent(),
  }
}
