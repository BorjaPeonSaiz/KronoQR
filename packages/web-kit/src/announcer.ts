// Region viva de la aplicacion (WCAG 2.2 AA, 4.1.3 Status Messages), compartida
// por las SPA del panel y del portal (ADR-036).
//
// Todo lo que cambia sin mover el foco —«entrega registrada», «40 tarjetas
// impresas», «empleado dado de baja»— se anuncia por aqui. Sin esto, quien usa
// un lector de pantalla confirma una accion y no se entera de si ocurrio.
//
// El estado es un modulo singleton: cada SPA que importa este fichero comparte
// la misma region viva dentro de su propio arbol de Vue, exactamente igual que
// cuando vivia en `frontend-admin/src/shared/ui/announcer.ts`. Lo que pinta el
// texto (`role="status"`/`aria-live`) es responsabilidad del `AppShellView` de
// cada aplicacion, que sigue siendo especifico de cada una.
import { readonly, ref } from 'vue'

/** Espacio duro invisible con el que se fuerza la relectura de un texto repetido. */
const NBSP = ' '

const message = ref('')

/** El texto que esta anunciado ahora mismo. Lo pinta el `AppShellView` de cada SPA. */
export const announcement = readonly(message)

/**
 * Anuncia un cambio.
 *
 * Si el texto es identico al anterior se le añade un espacio duro invisible: un
 * lector de pantalla no relee una region viva cuyo contenido no ha cambiado, y
 * repetir una accion tiene que sonar dos veces. El truco es sincrono a
 * proposito, para no depender de un `nextTick` que la prueba tendria que
 * adivinar.
 */
export function announce(text: string): void {
  message.value = message.value === text ? text + NBSP : text
}

/** Vacia la region. Util al cambiar de pantalla y en las pruebas. */
export function clearAnnouncement(): void {
  message.value = ''
}
