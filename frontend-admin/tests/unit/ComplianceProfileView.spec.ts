import { beforeEach, describe, expect, it } from 'vitest'
import ComplianceProfileView from '@/features/settings/ComplianceProfileView.vue'
import es from '@/shared/i18n/locales/es.json'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import type { FetchHandler } from './support/harness'
import {
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
  stubRoutes,
} from './support/harness'

// La pantalla del perfil de cumplimiento (RF-PD-07, regla dura 14).
//
// Lo que se comprueba aqui es lo que puede salir mal el dia que un cliente
// ajusta su convenio: que el aviso de que el cambio mueve la deteccion de
// incidencias este siempre visible, que solo se manden los campos que de verdad
// han cambiado —un `PATCH` con los ocho llenaria el trail de asientos vacios—,
// que el aviso especifico de la retencion aparezca solo cuando toca, y que un
// `422` del servidor se lea con el nombre del campo en castellano y no con el de
// la columna.

const profile = {
  data: {
    id: 1,
    name: 'ES-hosteleria',
    jurisdiction: 'ES',
    min_rest_hours: 12,
    max_daily_hours: 9,
    max_weekly_hours: 40,
    break_required_after_hours: 6,
    week_starts_on: 1,
    holiday_calendar: [],
    retention_years: 4,
    is_default: true,
    source: 'installation_default',
    updated_at: null,
  },
}

/**
 * El catalogo minimo de `GET /api/v1/settings` que esta pantalla necesita
 * (tarea 3.5): solo `ATTENDANCE_BREAK_CLOCKING`, con su conjunto cerrado de
 * valores.
 */
function settingsCatalog(breakClocking: 'enabled' | 'disabled' = 'disabled'): unknown {
  return {
    data: [
      {
        key: 'ATTENDANCE_BREAK_CLOCKING',
        value: breakClocking,
        type: 'text',
        impact: 'compliance_review',
        affects_worked_hours: false,
        source: breakClocking === 'disabled' ? 'product_default' : 'installation',
        constraints: { allowed: ['enabled', 'disabled'] },
      },
    ],
    meta: { unknown_keys: [], invalid_keys: [] },
  }
}

/**
 * Enruta `GET/PATCH /api/v1/compliance-profile` a `onProfile` y
 * `GET /api/v1/settings` al catalogo fijo: desde la tarea 3.5 esta pantalla
 * pide los dos recursos a la vez, y las pruebas que no dependen de
 * `ATTENDANCE_BREAK_CLOCKING` lo dejan en `disabled`, el valor de serie.
 */
function stubProfileApi(
  onProfile: FetchHandler,
  breakClocking: 'enabled' | 'disabled' = 'disabled',
): ReturnType<typeof stubFetch> {
  return stubRoutes({
    '/compliance-profile': onProfile,
    '/settings': () => jsonResponse(settingsCatalog(breakClocking)),
  })
}

beforeEach(() => {
  clearAnnouncement()
})

describe('perfil de cumplimiento', () => {
  it('carga el perfil y avisa de que los umbrales mueven la deteccion', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    expect(wrapper.find('[data-test="min-rest-hours"]').element).toHaveProperty('value', '12')
    expect(wrapper.find('[data-test="retention-years"]').element).toHaveProperty('value', '4')
    // El aviso es permanente, no condicional: quien abre la pantalla tiene que
    // leerlo antes de tocar nada.
    expect(wrapper.find('[data-test="detection-warning"]').text()).toBe(
      es.compliance.detectionWarning,
    )
    // Y el unico campo que sigue sin consumidor (el calendario de festivos,
    // tarea 3.10) se declara como tal: la jornada semanal y el dia de inicio de
    // semana ya los aplica la vista de cumplimiento desde la tarea 3.4.
    expect(wrapper.find('[data-test="not-applied-yet"]').text()).toBe(es.compliance.notAppliedYet)
  })

  it('dice que la jornada semanal y el inicio de semana mueven la vista de cumplimiento, no la bandeja', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    // Aviso permanente, igual que `detection-warning`: RN-17 es informativa
    // (computo anual, art. 34.1 ET) y nunca abre incidencia, al contrario que
    // el descanso minimo y la jornada diaria.
    expect(wrapper.find('[data-test="compliance-view-effect"]').text()).toBe(
      es.compliance.complianceViewEffect,
    )

    // Y el aviso del calendario de festivos vive junto a SU campo, no antes
    // de la jornada semanal (que ya tiene consumidor desde la tarea 3.4).
    const html = wrapper.html()
    const weekStartsOnAt = html.indexOf('data-test="week-starts-on"')
    const effectAt = html.indexOf('data-test="compliance-view-effect"')
    const holidayCalendarAt = html.indexOf('data-test="holiday-calendar"')
    const notAppliedYetAt = html.indexOf('data-test="not-applied-yet"')

    expect(weekStartsOnAt).toBeLessThan(effectAt)
    expect(effectAt).toBeLessThan(holidayCalendarAt)
    expect(holidayCalendarAt).toBeLessThan(notAppliedYetAt)
  })

  it('dice si el perfil es del centro o el de la instalacion', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    // Un centro sin perfil asignado hereda los cambios del perfil por defecto:
    // quien edita necesita saber cual de las dos cosas esta tocando.
    expect(wrapper.find('[data-test="source"]').text()).toContain('ES-hosteleria')
    expect(wrapper.find('[data-test="source"]').text()).toContain('por defecto')
  })

  it('no deja guardar mientras no haya ningun cambio', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeDefined()
  })

  it('manda solo los campos que han cambiado', async () => {
    const fetchSpy = stubProfileApi((_url, init) =>
      init?.method === 'PATCH'
        ? jsonResponse({ data: { ...profile.data, min_rest_hours: 10 } })
        : jsonResponse(profile),
    )

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    await wrapper.find('[data-test="min-rest-hours"]').setValue('10')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(patch).toBeDefined()
    // Un `PATCH` con los ocho campos llenaria `audit_log` de asientos que dicen
    // «alguien abrio la pantalla», y enterraria la señal que importa.
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({ min_rest_hours: 10 })
  })

  it('avisa aparte cuando lo que se va a cambiar es el plazo de conservacion', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    expect(wrapper.find('[data-test="retention-warning"]').exists()).toBe(false)

    await wrapper.find('[data-test="retention-years"]').setValue('2')

    // Es el unico campo cuyo error se paga con datos que no vuelven: su aviso no
    // puede ser el mismo que el de los umbrales de deteccion.
    expect(wrapper.find('[data-test="retention-warning"]').text()).toBe(
      es.compliance.retentionWarning,
    )
  })

  it('avisa cuando el cambio pendiente mueve la deteccion de incidencias', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    expect(wrapper.find('[data-test="pending-detection-warning"]').exists()).toBe(false)

    await wrapper.find('[data-test="max-daily-hours"]').setValue('8')

    expect(wrapper.find('[data-test="pending-detection-warning"]').exists()).toBe(true)

    // La jornada semanal se guarda pero no la lee ninguna regla: cambiarla no
    // puede anunciar un efecto sobre la bandeja que no va a ocurrir.
    await wrapper.find('[data-test="max-daily-hours"]').setValue('9')
    await wrapper.find('[data-test="max-weekly-hours"]').setValue('38')

    expect(wrapper.find('[data-test="pending-detection-warning"]').exists()).toBe(false)
  })

  it('manda el calendario de festivos como lista de fechas', async () => {
    const fetchSpy = stubProfileApi((_url, init) =>
      init?.method === 'PATCH'
        ? jsonResponse({
            data: { ...profile.data, holiday_calendar: ['2026-01-01', '2026-12-25'] },
          })
        : jsonResponse(profile),
    )

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    // Una fecha por linea es como se pega un calendario copiado de un boletin
    // oficial. Las lineas en blanco no viajan.
    await wrapper.find('[data-test="holiday-calendar"]').setValue('2026-01-01\n\n2026-12-25\n')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      holiday_calendar: ['2026-01-01', '2026-12-25'],
    })
  })

  it('enseña el error del servidor con el nombre del campo, no con el de la columna', async () => {
    stubProfileApi((_url, init) =>
      init?.method === 'PATCH'
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: { max_daily_hours: ['El campo admite de 1 a 24 y ha recibido 90.'] },
          })
        : jsonResponse(profile),
    )

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    await wrapper.find('[data-test="max-daily-hours"]').setValue('90')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    // Sin `fieldLabels`, el aviso diria «max_daily_hours», que es el nombre de la
    // columna y no lo que la persona acaba de escribir en la pantalla.
    expect(wrapper.text()).toContain(es.compliance.fields.maxDailyHours)
  })

  it('enseña el error de carga sin dejar el formulario a medias', async () => {
    stubProfileApi(() => problemResponse(404, 'urn:kronoqr:problem:not-found'))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    expect(wrapper.find('[data-test="save"]').exists()).toBe(false)
    expect(wrapper.text()).not.toBe('')
  })
})

describe('perfil de cumplimiento: la regla suspendida (fichaje de pausa desactivado)', () => {
  it('no promete que cambiar el umbral de pausa mueva la bandeja', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    await wrapper.find('[data-test="break-required-after-hours"]').setValue('5')

    // RN-12 se evalua pero no abre incidencias mientras el fichaje de pausa
    // este desactivado (tarea 3.5). Con el aviso generico, la pantalla
    // afirmaria que «se marcaran jornadas distintas» y no se marcaria ninguna.
    expect(wrapper.find('[data-test="pending-detection-warning"]').exists()).toBe(false)
    // Y el campo lleva su propia explicacion, siempre visible, con el enlace
    // a donde se activa.
    expect(wrapper.find('[data-test="break-suspended"]').text()).toContain(
      es.compliance.breakSuspendedDisabled,
    )
    expect(wrapper.find('[data-test="break-suspended"]').text()).toContain(
      es.compliance.breakSuspendedDisabledLink,
    )
    // El enlace lleva a donde se activa (`OperationalSettingsView`, RF-AT-12).
    expect(wrapper.find('[data-test="break-suspended"] a').attributes('href')).toContain('settings')
  })

  it('sigue avisando por los dos umbrales que hoy si mueven la bandeja', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    await wrapper.find('[data-test="min-rest-hours"]').setValue('10')

    expect(wrapper.find('[data-test="pending-detection-warning"]').exists()).toBe(true)
  })

  it('avisa de que la revision mira siete dias hacia atras', async () => {
    // Endurecer un umbral puede abrir incidencias de jornadas ya pasadas que
    // caigan dentro de la ventana: quien lo cambia tiene que saberlo antes.
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    expect(wrapper.find('[data-test="detection-warning"]').text()).toContain('7 días')
  })
})

describe('perfil de cumplimiento: RN-12 activada (fichaje de pausa activado, tarea 3.5)', () => {
  it('dice que RN-12 se evalua y abre incidencias, sin el enlace de activacion', async () => {
    stubProfileApi(() => jsonResponse(profile), 'enabled')

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    expect(wrapper.find('[data-test="break-suspended"]').text()).toBe(
      es.compliance.breakSuspendedEnabled,
    )
  })

  it('cambiar el umbral de pausa SI avisa de que mueve la deteccion', async () => {
    stubProfileApi(() => jsonResponse(profile), 'enabled')

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    expect(wrapper.find('[data-test="pending-detection-warning"]').exists()).toBe(false)

    await wrapper.find('[data-test="break-required-after-hours"]').setValue('5')

    expect(wrapper.find('[data-test="pending-detection-warning"]').exists()).toBe(true)
  })
})

describe('perfil de cumplimiento: el ajuste de pausa es informativo, nunca bloqueante', () => {
  it('si GET /settings falla, el perfil se carga y se puede editar igual', async () => {
    stubRoutes({
      '/compliance-profile': () => jsonResponse(profile),
      '/settings': () => problemResponse(500, 'urn:kronoqr:problem:unexpected'),
    })

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    // El perfil se cargo con normalidad: una lectura meramente informativa no
    // puede dejar sin editar el formulario que de verdad importa.
    expect(wrapper.find('[data-test="min-rest-hours"]').element).toHaveProperty('value', '12')
    expect(wrapper.find('[data-test="save"]').exists()).toBe(true)

    // Y cae al aviso mas conservador: «desactivado», que es el que no
    // promete de mas.
    expect(wrapper.find('[data-test="break-suspended"]').text()).toContain(
      es.compliance.breakSuspendedDisabled,
    )
  })
})

describe('perfil de cumplimiento: entrada que no es un numero', () => {
  it('marca el campo vaciado en vez de ignorarlo en silencio', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    await wrapper.find('[data-test="min-rest-hours"]').setValue('')

    // Antes se descartaba sin decir nada: el campo se quedaba vacio, el PATCH no
    // lo incluia y el boton se deshabilitaba sin explicar por que.
    expect(wrapper.text()).toContain(es.compliance.errors.required)
    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeDefined()
  })

  it('marca un decimal, que es lo unico que un campo numerico deja teclear mal', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    // Un umbral legal es un numero entero de horas: 10,5 no es «diez y media»,
    // es un valor que el servidor rechaza y que aqui se ve antes de enviarlo.
    await wrapper.find('[data-test="min-rest-hours"]').setValue('10.5')

    expect(wrapper.text()).toContain(es.compliance.errors.notAWholeNumber)
    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeDefined()
  })

  it('deja de marcar el campo en cuanto se corrige', async () => {
    stubProfileApi(() => jsonResponse(profile))

    const wrapper = await mountView(ComplianceProfileView)
    await settle()

    await wrapper.find('[data-test="min-rest-hours"]').setValue('')
    await wrapper.find('[data-test="min-rest-hours"]').setValue('10')

    expect(wrapper.text()).not.toContain(es.compliance.errors.required)
    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeUndefined()
  })
})
