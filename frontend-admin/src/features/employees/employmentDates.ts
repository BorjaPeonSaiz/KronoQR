// Aritmetica de fechas civiles (`AAAA-MM-DD`) de la baja y la readmision.
//
// Son fechas SIN zona (RN-14): un dia del calendario del centro. Nunca pasan
// por `new Date(texto)` con zona local ni por la zona del navegador (regla
// dura 3); la unica zona que interviene es la del centro, y solo para saber
// cual es «hoy» (`todayInZone`, en quien llama).

const CIVIL_DATE = /^(\d{4})-(\d{2})-(\d{2})$/

/** Si el texto es una fecha civil `AAAA-MM-DD` que existe en el calendario. */
export function isCivilDate(value: string): boolean {
  const match = CIVIL_DATE.exec(value)

  if (match === null) {
    return false
  }

  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
  const probe = new Date(Date.UTC(year, month - 1, day))

  return (
    probe.getUTCFullYear() === year &&
    probe.getUTCMonth() === month - 1 &&
    probe.getUTCDate() === day
  )
}

/** La fecha civil `days` dias despues (o antes, con negativo). Calendario puro, sin DST. */
export function addCivilDays(date: string, days: number): string {
  const match = CIVIL_DATE.exec(date)

  if (match === null) {
    throw new RangeError(`Fecha civil no valida: ${date}`)
  }

  const shifted = new Date(
    Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]) + days),
  )

  return shifted.toISOString().slice(0, 10)
}
