// Movida de `frontend-admin/tests/unit/datetime.spec.ts` (ADR-036).
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
  formatCivilDate,
  formatDayMonthTime,
  formatInstant,
  formatInstantWithZone,
  formatLocalDateTime,
  formatLocalTime,
  formatTimeOfDay,
  formatUtcTime,
  formatZoneLabel,
  isValidTimeZone,
  minutesBetween,
  readLocalTimestamp,
  readWallClock,
  resolveTimeZone,
  todayInZone,
  wallClockOf,
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

const MADRID = 'Europe/Madrid'
const CANARIAS = 'Atlantic/Canary'

describe('zonas horarias: validacion', () => {
  it('reconoce los nombres IANA y descarta lo demas', () => {
    expect(isValidTimeZone(MADRID)).toBe(true)
    expect(isValidTimeZone(CANARIAS)).toBe(true)
    expect(isValidTimeZone('UTC')).toBe(true)
    expect(isValidTimeZone('Marte/Olympus')).toBe(false)
    expect(isValidTimeZone('')).toBe(false)
  })

  it('`resolveTimeZone`: nombre valido o `undefined` (= la del aparato)', () => {
    expect(resolveTimeZone(MADRID)).toBe(MADRID)
    expect(resolveTimeZone(null)).toBeUndefined()
    expect(resolveTimeZone(undefined)).toBeUndefined()
    expect(resolveTimeZone('Marte/Olympus')).toBeUndefined()
  })
})

describe('hora de pared en una zona', () => {
  it('lee la pared de la zona pedida, no la del aparato', () => {
    const instant = new Date('2026-07-15T05:30:00.000Z')

    expect(wallClockOf(instant, CANARIAS)).toEqual({ hour: 6, minute: 30 })
    expect(wallClockOf(instant, MADRID)).toEqual({ hour: 7, minute: 30 })
  })

  it('devuelve tambien la fecha y cruza la medianoche de la zona', () => {
    // 22:30Z en verano: ya es el dia siguiente en Madrid (+02:00).
    expect(readWallClock(new Date('2026-07-14T22:30:00.000Z'), MADRID)).toEqual({
      year: 2026,
      month: 7,
      day: 15,
      hour: 0,
      minute: 30,
    })
  })

  it('la medianoche exacta es la hora 0, nunca la 24', () => {
    expect(wallClockOf(new Date('2026-07-14T22:00:00.000Z'), MADRID)).toEqual({
      hour: 0,
      minute: 0,
    })
    expect(readWallClock(new Date('2026-01-14T23:00:00.000Z'), MADRID)?.hour).toBe(0)
    expect(readWallClock(0, 'UTC')?.hour).toBe(0)
  })

  it('acepta milisegundos y los trata igual que un Date', () => {
    const ms = Date.parse('2026-07-15T05:30:00.000Z')

    expect(readWallClock(ms, MADRID)).toEqual(readWallClock(new Date(ms), MADRID))
  })

  it('cambio de hora de primavera (Madrid, 29-03-2026): la hora 02:xx no se lee nunca', () => {
    expect(wallClockOf(new Date('2026-03-29T00:59:00.000Z'), MADRID)).toEqual({
      hour: 1,
      minute: 59,
    })
    expect(wallClockOf(new Date('2026-03-29T01:00:00.000Z'), MADRID)).toEqual({
      hour: 3,
      minute: 0,
    })
  })

  it('cambio de hora de otono (Madrid, 25-10-2026): la hora 02:30 se lee dos veces', () => {
    expect(wallClockOf(new Date('2026-10-25T00:30:00.000Z'), MADRID)).toEqual({
      hour: 2,
      minute: 30,
    })
    expect(wallClockOf(new Date('2026-10-25T01:30:00.000Z'), MADRID)).toEqual({
      hour: 2,
      minute: 30,
    })
  })

  it('cambio de hora en Canarias (WET/WEST) un dia distinto al de Madrid', () => {
    // Las dos zonas cambian a la vez (01:00Z), pero la pared de Canarias salta de 01:00 a 02:00.
    expect(wallClockOf(new Date('2026-03-29T00:59:00.000Z'), CANARIAS)).toEqual({
      hour: 0,
      minute: 59,
    })
    expect(wallClockOf(new Date('2026-03-29T01:00:00.000Z'), CANARIAS)).toEqual({
      hour: 2,
      minute: 0,
    })
  })

  it('una zona invalida o ausente usa la del aparato', () => {
    const instant = new Date('2026-07-15T05:30:00.000Z')
    const device = wallClockOf(instant)

    expect(wallClockOf(instant, 'Marte/Olympus')).toEqual(device)
    expect(wallClockOf(instant, '')).toEqual(device)
  })

  it('una fecha invalida da null y no revienta', () => {
    expect(wallClockOf(new Date('nope'), MADRID)).toBeNull()
    expect(readWallClock(Number.NaN, MADRID)).toBeNull()
  })
})

describe('reloj de 24 h y variantes cortas (V2-PO-2)', () => {
  const instant = '2026-03-14T23:20:00.000Z'

  it('formatInstant usa 24 h tambien en ingles, sin AM/PM', () => {
    expect(formatInstant('2026-10-08T05:14:00Z', 'Europe/Madrid', 'en')).not.toMatch(/AM|PM/i)
    expect(formatInstant('2026-10-08T15:14:00Z', 'Europe/Madrid', 'en')).toContain('17:14')
  })

  it('el reloj de 12 h solo sale si se pide', () => {
    expect(
      formatInstant('2026-10-08T15:14:00Z', 'Europe/Madrid', 'en', { hourCycle: 'h12' }),
    ).toMatch(/PM/i)
  })

  it('formatTimeOfDay da HH:MM en la zona del centro, igual en los dos idiomas', () => {
    expect(formatTimeOfDay(instant, 'Europe/Madrid', 'es')).toBe('00:20')
    expect(formatTimeOfDay(instant, 'Europe/Madrid', 'en')).toBe('00:20')
    expect(formatTimeOfDay('no es una fecha', 'Europe/Madrid', 'es')).toBe('')
  })

  it('formatDayMonthTime ordena dia y mes segun el idioma', () => {
    // El cero del mes depende de la version de ICU: se admiten los dos.
    expect(formatDayMonthTime(instant, 'Europe/Madrid', 'es')).toMatch(/^15\/0?3, 00:20$/)
    expect(formatDayMonthTime(instant, 'Europe/Madrid', 'en')).toMatch(/^0?3\/15, 00:20$/)
    expect(formatDayMonthTime('x', 'Europe/Madrid', 'es')).toBe('')
  })
})

// Los mismos casos que el backend (`ElapsedMinutesFixtureTest`): la pausa que
// enseñan el panel y el portal tiene que ser la que cuenta el servidor (V21-1-RV-1).
interface ElapsedMinutesCase {
  readonly label: string
  readonly from: string
  readonly to: string
  readonly minutes: number
}
const elapsedMinutesFixture = JSON.parse(
  readFileSync(
    resolve(
      dirname(fileURLToPath(import.meta.url)),
      '../../../../backend/tests/Fixtures/elapsed-minutes.json',
    ),
    'utf8',
  ),
) as { readonly cases: ElapsedMinutesCase[] }

describe('minutesBetween: la regla de ElapsedMinutes del servidor', () => {
  it.each(
    elapsedMinutesFixture.cases.map(
      (item) => [item.label, item.from, item.to, item.minutes] as const,
    ),
  )('%s', (_label, from, to, minutes) => {
    expect(minutesBetween(from, to)).toBe(minutes)
  })

  it('trunca hacia cero si la segunda marca es anterior, sin un -0', () => {
    expect(minutesBetween('2026-10-07T10:00:30Z', '2026-10-07T10:00:00Z')).toBe(0)
    expect(minutesBetween('2026-10-07T10:01:30Z', '2026-10-07T10:00:00Z')).toBe(-1)
  })
})

describe('formatLocalDateTime: el momento de una correccion', () => {
  it('lee la fecha y la hora que resolvio el servidor, sin convertir', () => {
    expect(
      formatLocalDateTime(
        '2026-03-14T06:00:00.000000+01:00',
        '2026-03-14T05:00:00.000000Z',
        'Europe/Madrid',
        'es',
      ),
    ).toBe('14 mar 2026, 06:00')
  })

  it('sin hora local legible cae al instante en la zona del centro, en 24 h', () => {
    expect(
      formatLocalDateTime('no es una hora', '2026-10-08T05:14:00Z', 'Europe/Madrid', 'en'),
    ).not.toMatch(/AM|PM/)
  })
})
