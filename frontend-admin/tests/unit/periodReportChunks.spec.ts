// El informe por periodo leido por trozos (RF-IN-01, RNF-P-04): la cabecera con las
// primeras filas sale antes que las demas, y el resultado final es identico, fila por fila,
// al de `response.json()`. Sin Worker (jsdom) se parsea en el hilo principal; con un Worker
// que falla, tambien.
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HEAD_ROWS,
  ROWS_PER_CHUNK,
  splitReport,
  startReportReader,
  type ChunkMessage,
  type NextChunk,
} from '@/features/reports/periodReportChunks'
import type { PeriodReport, PeriodReportRow } from '@/shared/api/types'
import { yieldToMain } from '@/shared/scheduling/yieldToMain'

function reportRow(index: number): PeriodReportRow {
  return {
    period: { from: '2026-03-01', to: '2026-03-31' },
    subject: {
      kind: 'employee',
      employee_uuid: `0199f0c2-1f4a-7c3e-9b21-${String(index).padStart(12, '0')}`,
      employee_code: String(100000 + index),
      full_name: `Persona ${index}`,
      department_id: 3,
      label: `Persona ${index}`,
    },
    worked_minutes: 9720,
    worked: '162:00',
    shift_count: index,
    days_in_period: 31,
    days_with_activity: 21,
    days_without_activity: 10,
    open_shift_days: 0,
    incident_days: 1,
    contracted_minutes: 9257,
    contracted: '154:17',
    deviation_minutes: 463,
    deviation: '07:43',
    overtime_minutes: 463,
    overtime: '07:43',
    days_without_contract: 0,
    absence_days: 0,
    holiday_days: 0,
    unjustified_absence_days: 10,
  }
}

function reportOf(rowCount: number): PeriodReport {
  return {
    from: '2026-03-01',
    to: '2026-03-31',
    granularity: 'month',
    group_by: 'employee',
    data: Array.from({ length: rowCount }, (_, index) => reportRow(index + 1)),
    meta: {
      time_zone: 'Europe/Madrid',
      generated_at: '2026-04-01T07:12:03.114000Z',
      row_count: rowCount,
      criteria: ['Los totales salen del registro horario ya consolidado.'],
      contract_coverage: {
        days_without_contract: 0,
        employees_without_contract: 0,
        complete: true,
      },
    },
  }
}

function bodyOf(report: unknown): Response {
  return new Response(JSON.stringify(report))
}

/** Un Worker que responde como el real, de forma sincrona, para probar el orden de los mensajes. */
class FakeWorker extends EventTarget {
  static terminated = 0
  private pending: Generator<ChunkMessage> | null = null

  constructor() {
    super()
    queueMicrotask(() => {
      this.emit({ kind: 'ready' })
    })
  }

  postMessage(body: ArrayBuffer | ReadableStream<Uint8Array> | NextChunk): void {
    if (body instanceof ArrayBuffer) {
      this.pending = splitReport(JSON.parse(new TextDecoder().decode(body)))
    } else if (!('kind' in body)) {
      throw new DOMException('no se puede ceder la corriente', 'DataCloneError')
    }

    queueMicrotask(() => {
      const next = this.pending?.next()

      if (next !== undefined && next.done !== true) {
        this.emit(next.value)
      }
    })
  }

  terminate(): void {
    FakeWorker.terminated += 1
  }

  private emit(message: ChunkMessage): void {
    this.dispatchEvent(new MessageEvent('message', { data: message }))
  }
}

afterEach(() => {
  vi.unstubAllGlobals()
  FakeWorker.terminated = 0
})

describe('splitReport', () => {
  it('parte un informe grande en cabecera, trozos y fin, sin perder ni reordenar filas', () => {
    const report = reportOf(HEAD_ROWS + ROWS_PER_CHUNK * 2 + 5)
    const messages = [...splitReport(report)]

    expect(messages.map((message) => message.kind)).toEqual([
      'head',
      'rows',
      'rows',
      'rows',
      'done',
    ])

    const rows = messages.flatMap((message) =>
      message.kind === 'head' || message.kind === 'rows' ? message.rows : [],
    )

    expect(rows).toEqual(report.data)

    const head = messages[0]

    expect(head?.kind === 'head' ? head.head.meta : null).toEqual(report.meta)
  })

  it('un informe corto va entero en la cabecera', () => {
    const messages = [...splitReport(reportOf(3))]

    expect(messages.map((message) => message.kind)).toEqual(['head', 'done'])
  })

  it('un cuerpo que no tiene la forma de un informe pasa tal cual', () => {
    expect([...splitReport({ title: 'x' })]).toEqual([{ kind: 'whole', payload: { title: 'x' } }])
  })
})

describe('startReportReader', () => {
  it('sin Worker lee el cuerpo entero en el hilo principal, igual que response.json()', async () => {
    const report = reportOf(250)
    const onHead = vi.fn()
    const reader = startReportReader(onHead)

    await expect(reader.read(bodyOf(report))).resolves.toEqual(report)
    expect(onHead).not.toHaveBeenCalled()
  })

  it('con Worker entrega la cabecera con las primeras filas y despues todas, en orden', async () => {
    vi.stubGlobal('Worker', FakeWorker)

    const report = reportOf(HEAD_ROWS + ROWS_PER_CHUNK + 7)
    const partials: PeriodReport[] = []
    const reader = startReportReader((partial) => {
      partials.push(partial)
    })

    const result = await reader.read(bodyOf(report))

    expect(partials).toHaveLength(1)
    expect(partials[0]?.data).toHaveLength(HEAD_ROWS)
    expect(partials[0]?.meta).toEqual(report.meta)
    expect(result).toEqual(report)
    expect(FakeWorker.terminated).toBe(1)
  })

  it('si no se puede ceder la corriente del cuerpo, lo lee a mano y lo copia al Worker', async () => {
    vi.stubGlobal('Worker', FakeWorker)

    const report = reportOf(HEAD_ROWS + 10)
    const reader = startReportReader()

    await expect(reader.read(bodyOf(report))).resolves.toEqual(report)
  })

  it('dispose libera el Worker aunque no se haya leido nada', () => {
    vi.stubGlobal('Worker', FakeWorker)

    startReportReader().dispose()

    expect(FakeWorker.terminated).toBe(1)
  })
})

describe('yieldToMain', () => {
  it('cede el hilo: la continuacion corre despues del trabajo ya encolado', async () => {
    const order: string[] = []

    setTimeout(() => {
      order.push('encolado')
    }, 0)
    await yieldToMain()
    order.push('continuacion')

    expect(order).toEqual(['encolado', 'continuacion'])
  })

  it('usa scheduler.yield cuando existe', async () => {
    const schedulerYield = vi.fn(() => Promise.resolve())

    vi.stubGlobal('scheduler', { yield: schedulerYield })
    await yieldToMain()

    expect(schedulerYield).toHaveBeenCalledOnce()
  })
})
