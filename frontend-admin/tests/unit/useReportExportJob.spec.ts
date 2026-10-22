// Seguimiento de una exportacion en diferido (14.4): el registro en `trackedExports`
// es simetrico con la vida de la pantalla que la sigue.
import { defineComponent, h } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { trackedExports } from '@/features/reports/useReportReadyNotices'
import { useReportExportJob } from '@/features/reports/useReportExportJob'
import { reportExportRow } from './support/fixtures'
import { jsonResponse, mountView, settle, stubFetch } from './support/harness'

type Job = ReturnType<typeof useReportExportJob>

async function mountHost(): Promise<{ job: Job; unmount: () => void }> {
  let job: Job | null = null
  const Host = defineComponent({
    setup() {
      job = useReportExportJob()

      return () => h('div')
    },
  })

  const wrapper = await mountView(Host)

  if (job === null) {
    throw new Error('el composable no se ha creado')
  }

  return { job, unmount: () => wrapper.unmount() }
}

const INPUT = { kind: 'legal', format: 'csv', from: '2026-01-01', to: '2026-01-31' } as const

afterEach(() => {
  vi.unstubAllGlobals()
  trackedExports.clear()
})

describe('useReportExportJob, trackedExports', () => {
  it('registra la exportacion mientras la pantalla vive y la retira al desmontarse', async () => {
    stubFetch((_url, init) =>
      init?.method === 'POST'
        ? jsonResponse({ data: reportExportRow() }, 202)
        : jsonResponse({ data: [reportExportRow()] }),
    )
    const { job, unmount } = await mountHost()

    await job.start(INPUT)
    await settle()

    expect(trackedExports.has(reportExportRow().uuid)).toBe(true)

    unmount()

    expect(trackedExports.has(reportExportRow().uuid)).toBe(false)
  })

  it('una peticion que termina despues de desmontarse no deja el uuid registrado para siempre', async () => {
    let release: () => void = () => undefined

    stubFetch(
      (_url, init) =>
        new Promise<Response>((resolve) => {
          if (init?.method === 'POST') {
            release = () => resolve(jsonResponse({ data: reportExportRow() }, 202))
          } else {
            resolve(jsonResponse({ data: [reportExportRow()] }))
          }
        }),
    )
    const { job, unmount } = await mountHost()

    const starting = job.start(INPUT)

    unmount()
    release()
    await starting

    expect(trackedExports.has(reportExportRow().uuid)).toBe(false)
  })
})
