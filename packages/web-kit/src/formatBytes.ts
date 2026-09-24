// Tamaño de fichero legible, compartido por las SPA del panel (ADR-036): es
// la funcion que se habia copiado byte a byte en `ReportExportsPanel.vue`
// (RF-IN-06, exportaciones de informes) y en `DataExportPanel.vue` (RF-PD-05,
// paquete de exportacion de datos), unidades binarias y todo. No es una cifra
// del registro legal -es el tamaño de un fichero-, asi que aqui si conviene
// redondear a una cifra razonable en vez del numero exacto de bytes; lo que
// no cambia entre pantallas es CUANTO se redondea ni con que unidades.
//
// Pura, sin Vue y sin i18n: solo recibe el locale ya resuelto por quien
// llama, igual que `workdayTotals.ts`.

const UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB'] as const

/**
 * Tamaño legible en unidades BINARIAS (divisor 1024), con el numero de
 * decimales que ya usaban las dos pantallas: cero para bytes exactos, uno a
 * partir de KiB. `locale` decide el separador decimal (`Intl.NumberFormat`),
 * nunca la zona horaria del navegador de quien mira -eso no aplica aqui,
 * pero es el mismo criterio que en `datetime.ts`: la interfaz lo declara, no
 * lo adivina-.
 */
export function formatBytes(bytes: number, locale: string): string {
  let value = bytes
  let unitIndex = 0

  while (value >= 1024 && unitIndex < UNITS.length - 1) {
    value /= 1024
    unitIndex += 1
  }

  const decimals = unitIndex === 0 ? 0 : 1
  const formatted = new Intl.NumberFormat(locale, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(value)

  return `${formatted} ${UNITS[unitIndex]}`
}
