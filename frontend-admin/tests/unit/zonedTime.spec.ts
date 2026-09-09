// Conversion de hora local (centro) a UTC para las tres operaciones de
// correccion (RF-PA-04, regla dura 3). Lo que importa aqui no es que la
// funcion "funcione": es que un mismo reloj de pared en Madrid y en Canarias
// produzca DOS instantes UTC distintos, y que el cambio de horario de verano
// no rompa la cuenta.
import { describe, expect, it } from 'vitest'
import { toLocalInputValue, zonedInputToUtcIso } from '@/features/workdays/zonedTime'

describe('zonedInputToUtcIso', () => {
  it('convierte una hora de verano en Madrid (CEST, +02:00) a UTC', () => {
    // 14 de agosto de 2026, 06:00 en Madrid = 04:00 UTC.
    expect(zonedInputToUtcIso('2026-08-14T06:00', 'Europe/Madrid')).toBe('2026-08-14T04:00:00.000Z')
  })

  it('convierte una hora de invierno en Madrid (CET, +01:00) a UTC', () => {
    // 14 de enero de 2026, 06:00 en Madrid = 05:00 UTC.
    expect(zonedInputToUtcIso('2026-01-14T06:00', 'Europe/Madrid')).toBe('2026-01-14T05:00:00.000Z')
  })

  it('la misma hora de pared da un instante UTC distinto en otra zona', () => {
    const madrid = zonedInputToUtcIso('2026-08-14T06:00', 'Europe/Madrid')
    const canarias = zonedInputToUtcIso('2026-08-14T06:00', 'Atlantic/Canary')

    expect(madrid).not.toBe(canarias)
    // Canarias va una hora por detras de Madrid en verano (+01:00 frente a +02:00).
    expect(new Date(canarias ?? '').getTime() - new Date(madrid ?? '').getTime()).toBe(3_600_000)
  })

  it('un turno de noche cruza la medianoche UTC sin desviarse', () => {
    // 14 de marzo de 2026, 22:00 en Madrid (CET, +01:00) = 21:00 UTC del mismo dia.
    expect(zonedInputToUtcIso('2026-03-14T22:00', 'Europe/Madrid')).toBe('2026-03-14T21:00:00.000Z')
  })

  it('nunca adivina un valor que no tiene forma de datetime-local', () => {
    expect(zonedInputToUtcIso('', 'Europe/Madrid')).toBeNull()
    expect(zonedInputToUtcIso('2026-08-14', 'Europe/Madrid')).toBeNull()
    expect(zonedInputToUtcIso('no es una fecha', 'Europe/Madrid')).toBeNull()
  })

  it('la hora que no existio nunca (el reloj salta de 02:00 a 03:00) da null, nunca 03:30', () => {
    // 29 de marzo de 2026 en Madrid: a la 01:00 UTC el reloj de pared salta
    // de las 02:00 a las 03:00. 02:30 no ocurrio nunca.
    expect(zonedInputToUtcIso('2026-03-29T02:30', 'Europe/Madrid')).toBeNull()
    // El filo exacto tampoco: las 02:00 en punto igual no existieron.
    expect(zonedInputToUtcIso('2026-03-29T02:00', 'Europe/Madrid')).toBeNull()
  })

  it('la hora que se repite (el reloj retrocede de 03:00 a 02:00) converge a la segunda ocurrencia, la de invierno', () => {
    // 25 de octubre de 2026 en Madrid: 02:30 ocurre dos veces, a las 00:30 UTC
    // (todavia CEST, +02:00) y a las 01:30 UTC (ya CET, +01:00). La convencion
    // fijada por esta prueba es la segunda: la posterior en el tiempo UTC.
    expect(zonedInputToUtcIso('2026-10-25T02:30', 'Europe/Madrid')).toBe('2026-10-25T01:30:00.000Z')
  })
})

describe('toLocalInputValue', () => {
  it('junta la fecha y la hora de un LocalTimestamp ya resuelto, sin convertir nada', () => {
    expect(toLocalInputValue('2026-03-14', '06:00')).toBe('2026-03-14T06:00')
  })
})
