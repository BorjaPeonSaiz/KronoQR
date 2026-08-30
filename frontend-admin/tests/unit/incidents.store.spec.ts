// Store de la bandeja de incidencias (RF-PA-05, RF-PR-01).
//
// Lo que se afirma: que los filtros van al servidor y reinician la pagina, que
// resolver sustituye la fila sin volver a pedir la bandeja entera, que un
// `409` deja dicho quien se adelanto releyendo por empleado, y que la
// antiguedad se calcula con el reloj del servidor y no con el del navegador.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useIncidentsStore } from '@/features/incidents/incidents.store'
import { incident, incidentCollection } from './support/fixtures'
import { createTestPinia, jsonResponse, problemResponse, stubFetch } from './support/harness'

describe('useIncidentsStore', () => {
  beforeEach(() => {
    createTestPinia()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('pide lo pendiente por omision y guarda la meta', async () => {
    const spy = stubFetch((url) => {
      expect(url).toContain('/api/v1/incidents')
      expect(url).toContain('status=open')

      return jsonResponse(incidentCollection())
    })
    const store = useIncidentsStore()

    await store.load()

    expect(store.entries).toHaveLength(1)
    expect(store.entries[0]?.employee.full_name).toBe('Youssef Amrani')
    expect(store.meta?.time_zone).toBe('Europe/Madrid')
    expect(spy).toHaveBeenCalledTimes(1)
  })

  it('un filtro nuevo vuelve a la primera pagina y se lo pide al servidor', async () => {
    const urls: string[] = []
    stubFetch((url) => {
      urls.push(url)

      return jsonResponse(incidentCollection())
    })
    const store = useIncidentsStore()
    await store.load()

    await store.goToPage(3)
    expect(store.filters.page).toBe(3)

    await store.applyFilters({ severity: 'high' })

    expect(store.filters.page).toBe(1)
    expect(urls.at(-1)).toContain('severity=high')
    expect(urls.at(-1)).toContain('page=1')
  })

  it('resuelve una incidencia y la retira de la bandeja sin volver a pedirla', async () => {
    let resolveCalls = 0
    stubFetch((url, init) => {
      if (url.includes('/resolve')) {
        resolveCalls += 1
        expect(init?.method).toBe('POST')
        expect(JSON.parse(String(init?.body))).toEqual({
          outcome: 'resolved',
          note: 'Corregido con el parte de turno.',
        })

        return jsonResponse({
          ...incident(),
          status: 'resolved',
          resolved_at: '2026-03-15T09:00:00.000000Z',
          resolved_by: { uuid: 'u-1', name: 'Direccion RRHH' },
          resolution_note: 'Corregido con el parte de turno.',
        })
      }

      return jsonResponse(incidentCollection())
    })
    const store = useIncidentsStore()
    await store.load()
    expect(store.meta?.total).toBe(1)

    await store.resolve(412, {
      outcome: 'resolved',
      note: 'Corregido con el parte de turno.',
    })

    expect(store.entries).toHaveLength(0)
    expect(store.meta?.total).toBe(0)
    expect(resolveCalls).toBe(1)
  })

  it('en un 409 releen las cerradas de esa persona y dicen quien la trabajo', async () => {
    let resolveAttempts = 0
    stubFetch((url) => {
      if (url.includes('/resolve')) {
        resolveAttempts += 1

        return problemResponse(409, 'urn:kronoqr:problem:conflict')
      }

      if (url.includes('status=resolved')) {
        return jsonResponse(
          incidentCollection([
            incident({
              status: 'resolved',
              resolved_at: '2026-03-15T08:45:00.000000Z',
              resolved_by: { uuid: 'u-2', name: 'Segunda jefatura de turno' },
              resolution_note: 'Revisado en el cambio de turno.',
            }),
          ]),
        )
      }

      if (url.includes('status=open')) {
        // La foto inicial (y la recarga tras el conflicto, con los mismos
        // filtros): la incidencia ya no esta pendiente en el servidor real, pero
        // aqui basta con distinguir la carga inicial de la relectura por que la
        // segunda ya no importa para lo que afirma esta prueba.
        return jsonResponse(incidentCollection())
      }

      // `status=dismissed`: nada.
      return jsonResponse(incidentCollection([], { total: 0 }))
    })
    const store = useIncidentsStore()
    await store.load()

    await expect(
      store.resolve(412, { outcome: 'resolved', note: 'Llega tarde.' }),
    ).rejects.toThrow()

    expect(resolveAttempts).toBe(1)
    expect(store.conflict?.incident?.resolved_by?.name).toBe('Segunda jefatura de turno')
    expect(store.conflict?.incident?.resolution_note).toBe('Revisado en el cambio de turno.')
  })

  it('cuando no se encuentra ni resuelta ni descartada, el conflicto no inventa un nombre', async () => {
    stubFetch((url) => {
      if (url.includes('/resolve')) {
        return problemResponse(409, 'urn:kronoqr:problem:conflict')
      }

      if (url.includes('status=open')) {
        return jsonResponse(incidentCollection())
      }

      // Ni entre las resueltas ni entre las descartadas: caso raro, pero la
      // interfaz no puede inventarse un nombre que no ha visto.
      return jsonResponse(incidentCollection([], { total: 0 }))
    })
    const store = useIncidentsStore()
    await store.load()

    await expect(
      store.resolve(412, { outcome: 'dismissed', note: 'Intento perdido.' }),
    ).rejects.toThrow()

    expect(store.conflict?.incident).toBeNull()
  })

  it('el «ahora» de la antiguedad es el del servidor, no el del navegador', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2030-01-01T00:00:00Z'))
    stubFetch(() => jsonResponse(incidentCollection()))
    const store = useIncidentsStore()

    await store.load()

    expect(new Date(store.serverNowMs()).toISOString()).toBe('2026-03-15T08:00:00.000Z')

    vi.setSystemTime(new Date('2030-01-01T00:05:00Z'))
    expect(new Date(store.serverNowMs()).toISOString()).toBe('2026-03-15T08:05:00.000Z')
  })
})
