// Presentacion pura de la salud de la flota de quioscos (RF-PA-07, tarea 3.3).
import { describe, expect, it } from 'vitest'
import {
  batteryChargingKey,
  batteryChargingState,
  batteryPercentLabel,
  hasBatteryWarning,
  reasonKey,
  rowToneClass,
  showsWhatToDo,
  thresholdLabel,
  verdictBadgeClass,
  verdictGlyph,
  verdictKey,
  whatToDoKey,
} from '@/features/devices/devicePresentation'
import type { Device, DeviceHealth } from '@/shared/api/types'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'

const VERDICTS: readonly DeviceHealth['verdict'][] = ['ok', 'warning', 'failure', 'revoked']
const REASONS: readonly DeviceHealth['reason'][] = [
  'beating',
  'queue_pending',
  'late',
  'silent',
  'awaiting_first_heartbeat',
  'never_seen',
  'revoked',
  'battery_low',
]

describe('verdictBadgeClass', () => {
  it('los cuatro veredictos llevan clases distintas, cada una con color Y texto (WCAG 1.4.1)', () => {
    const classes = VERDICTS.map(verdictBadgeClass)

    expect(new Set(classes).size).toBe(4)
  })

  it('failure es danger, warning es warning, ok es success', () => {
    expect(verdictBadgeClass('failure')).toContain('danger')
    expect(verdictBadgeClass('warning')).toContain('warning')
    expect(verdictBadgeClass('ok')).toContain('success')
  })
})

describe('verdictGlyph', () => {
  it('cada veredicto tiene su glifo, decorativo -el texto accesible es `verdictKey`', () => {
    for (const verdict of VERDICTS) {
      expect(verdictGlyph(verdict)).not.toBe('')
    }
  })
})

describe('rowToneClass', () => {
  it('failure y warning tiñen la fila; ok y revoked no necesitan destacarse', () => {
    expect(rowToneClass('failure')).toContain('danger')
    expect(rowToneClass('warning')).toContain('warning')
    expect(rowToneClass('ok')).toBe('')
    expect(rowToneClass('revoked')).toBe('')
  })
})

describe('showsWhatToDo', () => {
  it('solo se omite para `ok`: late con normalidad no tiene ninguna accion que ofrecer', () => {
    expect(showsWhatToDo('ok')).toBe(false)
    expect(showsWhatToDo('warning')).toBe(true)
    expect(showsWhatToDo('failure')).toBe(true)
    expect(showsWhatToDo('revoked')).toBe(true)
  })
})

describe('verdictKey y reasonKey', () => {
  it('cada veredicto y cada razon tienen su texto en español e ingles', () => {
    for (const verdict of VERDICTS) {
      const key = verdictKey(verdict)

      expect(key).toBe(`devices.health.verdict.${verdict}`)
      expect(es.devices.health.verdict[verdict]).toBeTruthy()
      expect(en.devices.health.verdict[verdict]).toBeTruthy()
    }

    for (const reason of REASONS) {
      const key = reasonKey(reason)

      expect(key).toBe(`devices.health.reason.${reason}`)
      expect(es.devices.health.reason[reason]).toBeTruthy()
      expect(en.devices.health.reason[reason]).toBeTruthy()
    }
  })
})

describe('whatToDoKey', () => {
  it('cada razon tiene su propio texto «que hacer», en español e ingles', () => {
    for (const reason of REASONS) {
      const key = whatToDoKey(reason)

      expect(key).toBe(`devices.health.whatToDo.${reason}`)
      expect(es.devices.health.whatToDo[reason]).toBeTruthy()
      expect(en.devices.health.whatToDo[reason]).toBeTruthy()
    }
  })

  it('el de «failure» (silent/never_seen) nombra el runbook y el comando de consola', () => {
    expect(es.devices.health.whatToDo.silent).toContain('quiosco-no-responde.md')
    expect(es.devices.health.whatToDo.silent).toContain('kiosk:health')
    expect(es.devices.health.whatToDo.never_seen).toContain('quiosco-no-responde.md')
    expect(es.devices.health.whatToDo.never_seen).toContain('kiosk:health')
  })

  it('el de «battery_low» dice que hay que revisar el cargador', () => {
    expect(es.devices.health.whatToDo.battery_low).toContain('cargador')
  })

  // Correccion de `revisor-codigo` (segunda vuelta): un quiosco con
  // `queue_pending` LATE al dia -tiene red-, asi que «sin latido no es sin
  // fichar» es la explicacion equivocada para su caso; esa frase pertenece a
  // `late`/`silent`, que si estan sin latido y SI necesitan la tranquilidad
  // de que la cola offline sigue funcionando.
  it('el de «queue_pending» habla de la propia cola, no de «sin latido»', () => {
    expect(es.devices.health.whatToDo.queue_pending).toContain('cola')
    expect(es.devices.health.whatToDo.queue_pending).not.toContain('Sin latido')
  })

  it('el de «late» y «silent» tranquilizan con «sin latido no es sin fichar»', () => {
    expect(es.devices.health.whatToDo.late.toLowerCase()).toContain('sin latido no es sin fichar')
    expect(es.devices.health.whatToDo.silent).toContain('Sin latido no es sin fichar')
  })
})

describe('batteryPercentLabel', () => {
  it('formatea el porcentaje declarado', () => {
    expect(batteryPercentLabel(83)).toBe('83 %')
    expect(batteryPercentLabel(0)).toBe('0 %')
  })

  it('`null` -sin dato- no es lo mismo que 0 %', () => {
    expect(batteryPercentLabel(null)).toBeNull()
  })
})

describe('batteryChargingState y batteryChargingKey', () => {
  it('los tres estados son distintos, y `null` es «no informa», no «sin cargar»', () => {
    expect(batteryChargingState(true)).toBe('charging')
    expect(batteryChargingState(false)).toBe('notCharging')
    expect(batteryChargingState(null)).toBe('unknown')
  })

  it('las tres claves tienen texto en los dos idiomas', () => {
    for (const charging of [true, false, null]) {
      const key = batteryChargingKey(charging)

      expect(es.devices.battery[batteryChargingState(charging)]).toBeTruthy()
      expect(en.devices.battery[batteryChargingState(charging)]).toBeTruthy()
      expect(key).toBe(`devices.battery.${batteryChargingState(charging)}`)
    }
  })
})

describe('hasBatteryWarning', () => {
  const withReason = (reason: DeviceHealth['reason']): Pick<Device, 'health'> => ({
    health: { verdict: 'warning', reason, seconds_since_last_seen: 30 },
  })

  it('solo avisa con la razon `battery_low`', () => {
    expect(hasBatteryWarning(withReason('battery_low'))).toBe(true)
    expect(hasBatteryWarning(withReason('late'))).toBe(false)
    expect(hasBatteryWarning(withReason('queue_pending'))).toBe(false)
  })
})

describe('thresholdLabel', () => {
  // Correccion de `revisor-codigo` (segunda vuelta): `Math.round(seconds/60)`
  // convertia un umbral afinado de 90 s en «2 min» y uno de 20 s en «0 min»,
  // los dos mintiendo sobre el umbral real de la instalacion.
  it('por debajo del minuto, muestra los segundos tal cual (20 s no es «0 min»)', () => {
    expect(thresholdLabel(20)).toBe('20 s')
  })

  it('un minuto y medio se dice «1 min 30 s», nunca «2 min»', () => {
    expect(thresholdLabel(90)).toBe('1 min 30 s')
  })

  it('un multiplo exacto de minuto no lleva segundos de mas', () => {
    expect(thresholdLabel(120)).toBe('2 min')
    expect(thresholdLabel(600)).toBe('10 min')
  })

  it('nunca negativo', () => {
    expect(thresholdLabel(-30)).toBe('0 s')
  })
})
