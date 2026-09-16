// Presentacion pura (sin Vue) de la salud de la flota de quioscos (RF-PA-07,
// tarea 3.3): el color y el glifo del veredicto, la clave i18n de la razon y
// del bloque «que hacer», y la lectura de la bateria. Se prueba sin montar
// `DevicesView`, igual que `errorPresentation.ts` hace para el historico de
// errores -es el mismo patron, deliberadamente.
import type { Device, DeviceHealth } from '@/shared/api/types'

type Verdict = DeviceHealth['verdict']
type Reason = DeviceHealth['reason']

// EL VEREDICTO SE DICE CON PALABRAS, NO SOLO CON COLOR (WCAG 2.2 AA, 1.4.1):
// el texto (`t(verdictKey(...))`) y un glifo con `aria-hidden="true"` van
// siempre junto al color de fondo, nunca solo. Tokens `--kq-*` compartidos
// (doc 06 regla 5): `revoked` no es un estado de alerta, es neutro, y usa el
// mismo par `surface-alt`/`text-muted` que el resto del panel para lo que no
// cuenta como aviso.
//
// `warning`/`failure` usan el CONTROL SÓLIDO (`bg-kq-{estado} text-kq-on-{estado}`,
// doc 06 regla 5), no el badge suave: esas dos filas llevan ADEMÁS el tinte de
// `rowToneClass` sobre toda la fila con el mismo `-soft` que usaría el badge
// (`bg-kq-warning-soft`/`bg-kq-danger-soft`), y un badge `-soft` sobre una fila
// del mismo `-soft` pierde el contorno: se confunde con el fondo y dilata de
// insignia a texto suelto justo en las dos filas que más necesitan destacar.
// El sólido se ve por encima de su propia fila tintada y de la fila neutra de
// `ok`/`revoked` por igual.
const VERDICT_CLASSES: Record<Verdict, string> = {
  ok: 'bg-kq-success-soft text-kq-success',
  warning: 'bg-kq-warning text-kq-on-warning',
  failure: 'bg-kq-danger text-kq-on-danger',
  revoked: 'bg-kq-surface-alt text-kq-text-muted',
}

export function verdictBadgeClass(verdict: Verdict): string {
  return VERDICT_CLASSES[verdict]
}

/** Solo decorativo (`aria-hidden`): el texto accesible es siempre `verdictKey`. */
const VERDICT_GLYPHS: Record<Verdict, string> = {
  ok: '✓',
  warning: '!',
  failure: '✕',
  revoked: '–',
}

export function verdictGlyph(verdict: Verdict): string {
  return VERDICT_GLYPHS[verdict]
}

/**
 * Tinte de la FILA entera para `failure` (fallo) y `warning` (aviso), ademas
 * del badge de la celda de salud: refuerzo visual, nunca el unico portador de
 * la informacion -esa es la insignia con texto e icono de la celda «Salud»
 * (WCAG 1.4.1). Cadena vacia para `ok`/`revoked`: no hace falta destacarlos.
 */
export function rowToneClass(verdict: Verdict): string {
  if (verdict === 'failure') {
    return 'bg-kq-danger-soft'
  }

  if (verdict === 'warning') {
    return 'bg-kq-warning-soft'
  }

  return ''
}

export function verdictKey(verdict: Verdict): string {
  return `devices.health.verdict.${verdict}`
}

export function reasonKey(reason: Reason): string {
  return `devices.health.reason.${reason}`
}

/**
 * Clave i18n del bloque «que hacer», UNA POR RAZON y no por veredicto: es la
 * granularidad que pide la ficha -`battery_low` y `queue_pending` comparten
 * veredicto `warning` con `late`/`awaiting_first_heartbeat`, pero cada una
 * necesita su propio texto (revisar el cargador no es lo mismo que esperar un
 * latido). `locales/{es,en}.json`, `devices.health.whatToDo.<razon>`.
 */
export function whatToDoKey(reason: Reason): string {
  return `devices.health.whatToDo.${reason}`
}

/** `«83 %»`, o `null` si la tablet no informa el nivel (nunca ha latido, o no lo declara). */
export function batteryPercentLabel(level: number | null): string | null {
  return level === null ? null : `${level} %`
}

export type BatteryChargingState = 'charging' | 'notCharging' | 'unknown'

/** `null` -sin dato- se lee como «no informa», nunca como «no está cargando». */
export function batteryChargingState(charging: boolean | null): BatteryChargingState {
  if (charging === null) {
    return 'unknown'
  }

  return charging ? 'charging' : 'notCharging'
}

export function batteryChargingKey(charging: boolean | null): string {
  return `devices.battery.${batteryChargingState(charging)}`
}

/** Si la celda de bateria debe llevar el aviso adicional (razon `battery_low` del veredicto). */
export function hasBatteryWarning(device: Pick<Device, 'health'>): boolean {
  return device.health.reason === 'battery_low'
}

/**
 * Si el bloque «que hacer» tiene sentido para este veredicto. `ok` -late con
 * normalidad- no tiene nada que hacer, y enseñar un bloque que dice «no hace
 * falta ninguna accion» en CADA fila sana es ruido, no ayuda (correccion de
 * `ui-ux`, segunda vuelta de la tarea 3.3). `warning`, `failure` y `revoked`
 * si lo llevan: los tres tienen una accion o una explicacion que dar.
 */
export function showsWhatToDo(verdict: Verdict): boolean {
  return verdict !== 'ok'
}

/**
 * Los umbrales de `DeviceListMeta.thresholds`, para la leyenda de la tabla
 * («al dia hasta 2 min sin latido; fallo a partir de 10 min»). A diferencia
 * de las horas trabajadas (regla de presentacion de CLAUDE.md), esto NO es un
 * dato con valor legal: es la configuracion operativa de la instalacion
 * (`KIOSK_HEALTH_FRESH_WITHIN_SECONDS`, `KIOSK_HEALTH_SILENT_AFTER_SECONDS`).
 *
 * **Nunca redondea a un minuto que no es** (correccion de `revisor-codigo`,
 * segunda vuelta: `Math.round(seconds / 60)` convertia un umbral afinado de
 * 90 s en «2 min» y uno de 20 s en «0 min», los dos mintiendo sobre el umbral
 * real de la instalacion). Por debajo del minuto se muestran los segundos
 * («20 s»); a partir de un minuto, minutos y -si sobran- los segundos que no
 * llegan a completar otro minuto («1 min 30 s»); un multiplo exacto no lleva
 * segundos de mas («10 min», no «10 min 0 s»).
 */
export function thresholdLabel(seconds: number): string {
  const safe = Number.isFinite(seconds) ? Math.max(Math.trunc(seconds), 0) : 0

  if (safe < 60) {
    return `${safe} s`
  }

  const minutes = Math.floor(safe / 60)
  const remainderSeconds = safe % 60

  return remainderSeconds === 0 ? `${minutes} min` : `${minutes} min ${remainderSeconds} s`
}
