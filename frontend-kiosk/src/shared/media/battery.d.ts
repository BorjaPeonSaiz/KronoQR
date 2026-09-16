// Battery Status API.
//
// NO va en `media-capabilities.d.ts`: aquella ampliacion es sobre
// `MediaTrackConstraintSet`/`MediaTrackCapabilities` (la camara); esto es sobre
// `Navigator`, una superficie completamente distinta, y mezclarlas en el mismo
// fichero confundiria el motivo de cada una.
//
// SOLO Chrome en Android (que es el navegador de la tablet del producto) la
// ofrece. `navigator.getBattery` es opcional a proposito: una tablet que no la
// tenga no es una tablet averiada (mismo criterio que `battery_level` en el
// contrato, tarea 3.3). Todo lo que la usa comprueba `'getBattery' in navigator`
// antes de llamarla.

export {}

declare global {
  interface BatteryManager extends EventTarget {
    readonly charging: boolean
    readonly chargingTime: number
    readonly dischargingTime: number
    /** De 0 a 1, no de 0 a 100: quien lo consume multiplica por 100 y redondea. */
    readonly level: number
  }

  interface Navigator {
    /** Ausente fuera de Chromium/Android. Nunca se asume presente. */
    getBattery?: () => Promise<BatteryManager>
  }
}
