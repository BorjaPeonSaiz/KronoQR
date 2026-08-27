// Movida de `frontend-admin/tests/unit/datetime.spec.ts` (ADR-036).
import { describe, expect, it } from 'vitest'
import {
  formatCivilDate,
  formatInstant,
  formatInstantWithZone,
  formatLocalTime,
  formatUtcTime,
  formatZoneLabel,
  minutesBetween,
  readLocalTimestamp,
  todayInZone,
} from '../../src/datetime'

describe('presentacion del tiempo', () => {
  it('convierte un instante UTC a la zona del centro y no a la del navegador', () => {
    const madrid = formatInstant('2026-08-20T07:11:02Z', 'Europe/Madrid', 'es')
    const canarias = formatInstant('2026-08-20T07:11:02Z', 'Atlantic/Canary', 'es')

    expect(madrid).toContain('9:11')
    expect(canarias).toContain('8:11')
  })

  it('acompaña la hora con la zona, porque un listado cruza centros', () => {
    const formatted = formatInstantWithZone('2026-08-20T07:11:02Z', 'Europe/Madrid', 'es')

    expect(formatted).toContain(formatZoneLabel('2026-08-20T07:11:02Z', 'Europe/Madrid', 'es'))
  })

  it('respeta el cambio de hora en lugar de sumar un desfase fijo', () => {
    const invierno = formatInstant('2026-01-15T12:00:00Z', 'Europe/Madrid', 'es')
    const verano = formatInstant('2026-07-15T12:00:00Z', 'Europe/Madrid', 'es')

    expect(invierno).toContain('13:00')
    expect(verano).toContain('14:00')
  })

  it('no desplaza una fecha civil por ninguna zona', () => {
    expect(formatCivilDate('2026-08-14', 'es')).toContain('14')
    expect(formatCivilDate('2026-01-01', 'es')).toContain('2026')
  })

  it('devuelve vacio ante un valor que no es una fecha, en lugar de inventarse una', () => {
    expect(formatCivilDate('14/08/2026', 'es')).toBe('')
    expect(formatInstant('no es una fecha', 'Europe/Madrid', 'es')).toBe('')
    expect(formatInstantWithZone('no es una fecha', 'Europe/Madrid', 'es')).toBe('')
  })

  it('lee la hora local que ya resolvio el servidor, sin volver a convertirla', () => {
    // El navegador de esta prueba puede estar en cualquier zona: la hora que
    // sale es la que viene escrita, con su desplazamiento (regla dura 3).
    expect(readLocalTimestamp('2026-03-14T06:00:00.000000+01:00')).toEqual({
      date: '2026-03-14',
      time: '06:00',
      offset: '+01:00',
    })
    expect(formatLocalTime('2026-03-14T06:00:00.000000+01:00')).toBe('06:00')
  })

  it('no acepta como hora local un instante UTC ni un texto cualquiera', () => {
    expect(readLocalTimestamp('2026-03-14T05:00:00.000000Z')).toBeNull()
    expect(formatLocalTime('a las seis')).toBe('')
  })

  it('enseña la marca en UTC tal y como esta almacenada', () => {
    expect(formatUtcTime('2026-03-14T05:00:00.000000Z')).toBe('05:00')
    expect(formatUtcTime('2026-03-14T23:59:00.000000Z')).toBe('23:59')
    expect(formatUtcTime('no es una hora')).toBe('')
  })

  it('mide el retraso de un fichaje encolado, que es una duracion y no una zona', () => {
    expect(minutesBetween('2026-03-14T05:00:00.000000Z', '2026-03-14T07:13:00.000000Z')).toBe(133)
    expect(minutesBetween('2026-03-14T05:00:00.000000Z', null)).toBeNull()
    expect(minutesBetween('2026-03-14T05:00:00.000000Z', 'nunca')).toBeNull()
  })

  it('da el dia de hoy en la zona del centro, no en la del navegador', () => {
    // A las 22:30 UTC de un dia de verano, en Madrid (UTC+2) ya es el dia
    // siguiente y en Canarias (UTC+1) todavia no.
    const instant = new Date('2026-08-20T22:30:00Z')

    expect(todayInZone('Europe/Madrid', instant)).toBe('2026-08-21')
    expect(todayInZone('Atlantic/Canary', instant)).toBe('2026-08-20')
  })
})
