// Cuando una exportacion se pide en diferido por omision (decision del propietario,
// ADR-041): la plantilla entera, o un periodo de mas de un mes. Las consultas
// cortas de una persona o un departamento siguen siendo sincronas. Los endpoints
// sincronos se mantienen: esto solo decide cual se ofrece primero.

/** Dias de un periodo `YYYY-MM-DD` a `YYYY-MM-DD`, ambos incluidos. `null` si falta alguno o esta invertido. */
export function inclusiveDays(from: string, to: string): number | null {
  if (from === '' || to === '') {
    return null
  }

  const start = Date.parse(`${from}T00:00:00Z`)
  const end = Date.parse(`${to}T00:00:00Z`)

  if (Number.isNaN(start) || Number.isNaN(end) || end < start) {
    return null
  }

  return Math.round((end - start) / 86_400_000) + 1
}

/** Mas de un mes: 31 dias es lo que cabe en un mes natural. */
export const DEFERRED_FROM_DAYS = 32

export function shouldDeferExport(input: {
  from: string
  to: string
  /** Sin persona ni departamento: toda la plantilla. */
  wholeWorkforce: boolean
}): boolean {
  const days = inclusiveDays(input.from, input.to)

  return input.wholeWorkforce || (days !== null && days >= DEFERRED_FROM_DAYS)
}
