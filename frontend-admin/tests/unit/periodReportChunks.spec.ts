// El informe por periodo leido por trozos (RF-IN-01, RNF-P-04): la cabecera con las
// primeras filas sale antes que las demas, y el resultado final es identico, fila por fila,
// al de `response.json()`. Sin Worker (jsdom) se parsea en el hilo principal; con un Worker
// que falla, tambien. Si el Worker ya tiene el cuerpo (la corriente cedida) y se pierde, se
// vuelve a pedir; si vence el plazo, es un `timeout` y no una segunda peticion.
import { ApiError, DEFAULT_READ_TIMEOUT_MS } from '@kronoqr/web-kit/http'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { generatePeriodReport } from '@/features/reports/periodReport.api'
import {
  HEAD_ROWS,
  ROWS_PER_CHUNK,
  ReportReaderLost,
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

/** Como arranca el Worker falso. */
type StartMode = 'ready' | 'error' | 'error-after-ready'
/** Que contesta cuando recibe el cuerpo. */
type AnswerMode = 'report' | 'failed' | 'error' | 'messageerror' | 'silent'

interface FakeBehavior {
  /** Acepta la corriente del cuerpo (Chromium) o lanza `DataCloneError` (otros navegadores). */
  acceptStream: boolean
  start: StartMode
  answer: AnswerMode
}

const DEFAULT_BEHAVIOR: FakeBehavior = { acceptStream: false, start: 'ready', answer: 'report' }

/** Un Worker que responde como el real, de forma sincrona, para probar el orden de los mensajes. */
class FakeWorker extends EventTarget {
  static terminated = 0
  static created = 0
  /** Lo que se le ha mandado con `postMessage` (sin los `next`). */
  static received: ('stream' | 'buffer')[] = []
  static behavior: FakeBehavior = { ...DEFAULT_BEHAVIOR }
  private pending: Generator<ChunkMessage> | null = null
  private dead = false

  constructor() {
    super()
    FakeWorker.created += 1

    const { start } = FakeWorker.behavior

    queueMicrotask(() => {
      if (start === 'error') {
        this.dispatchEvent(new Event('error'))

        return
      }

      this.emit({ kind: 'ready' })

      if (start === 'error-after-ready') {
        queueMicrotask(() => {
          this.dispatchEvent(new Event('error'))
        })
      }
    })
  }

  postMessage(body: ArrayBuffer | ReadableStream<Uint8Array> | NextChunk): void {
    if (body instanceof ArrayBuffer) {
      FakeWorker.received.push('buffer')
      this.answerWith(new TextDecoder().decode(body))

      return
    }

    if (body instanceof ReadableStream) {
      if (!FakeWorker.behavior.acceptStream) {
        throw new DOMException('no se puede ceder la corriente', 'DataCloneError')
      }

      FakeWorker.received.push('stream')
      void new Response(body).text().then((text) => {
        this.answerWith(text)
      })

      return
    }

    queueMicrotask(() => {
      this.emitNext()
    })
  }

  terminate(): void {
    FakeWorker.terminated += 1
    this.dead = true
  }

  private answerWith(text: string): void {
    const { answer } = FakeWorker.behavior

    queueMicrotask(() => {
      if (this.dead) {
        return
      }

      switch (answer) {
        case 'report':
          this.pending = splitReport(JSON.parse(text))
          this.emitNext()
          break
        case 'failed':
          this.emit({ kind: 'failed' })
          break
        case 'error':
          this.dispatchEvent(new Event('error'))
          break
        case 'messageerror':
          this.dispatchEvent(new Event('messageerror'))
          break
        case 'silent':
          break
      }
    })
  }

  private emitNext(): void {
    const next = this.pending?.next()

    if (next !== undefined && next.done !== true) {
      this.emit(next.value)
    }
  }

  private emit(message: ChunkMessage): void {
    this.dispatchEvent(new MessageEvent('message', { data: message }))
  }
}

function behave(overrides: Partial<FakeBehavior>): void {
  FakeWorker.behavior = { ...DEFAULT_BEHAVIOR, ...overrides }
  vi.stubGlobal('Worker', FakeWorker)
}

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
  FakeWorker.terminated = 0
  FakeWorker.created = 0
  FakeWorker.received = []
  FakeWorker.behavior = { ...DEFAULT_BEHAVIOR }
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
    behave({})

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
    behave({})

    const report = reportOf(HEAD_ROWS + 10)
    const reader = startReportReader()

    await expect(reader.read(bodyOf(report))).resolves.toEqual(report)
    expect(FakeWorker.received).toEqual(['buffer'])
  })

  it('cede la corriente del cuerpo al Worker cuando el navegador puede', async () => {
    behave({ acceptStream: true })

    const report = reportOf(HEAD_ROWS + ROWS_PER_CHUNK + 3)
    const result = await startReportReader().read(bodyOf(report))

    expect(result).toEqual(report)
    expect(FakeWorker.received).toEqual(['stream'])
  })

  it('si el Worker falla al arrancar, no se le habla y se lee en el hilo principal', async () => {
    behave({ start: 'error' })

    const report = reportOf(HEAD_ROWS + 10)
    const reader = startReportReader()

    await expect(reader.read(bodyOf(report))).resolves.toEqual(report)
    expect(FakeWorker.received).toEqual([])
    expect(FakeWorker.terminated).toBeGreaterThanOrEqual(1)
  })

  it('si el Worker muere despues de avisar de que esta listo, tampoco se le habla', async () => {
    behave({ acceptStream: true, start: 'error-after-ready' })

    const report = reportOf(HEAD_ROWS + 10)
    const reader = startReportReader()

    // Deja que se emitan `ready` y `error` antes de pedir la lectura.
    await new Promise((resolve) => setTimeout(resolve, 0))

    await expect(reader.read(bodyOf(report))).resolves.toEqual(report)
    expect(FakeWorker.received).toEqual([])
  })

  it.each(['failed', 'error', 'messageerror'] as const)(
    'si el Worker contesta «%s» a la copia del cuerpo, el hilo principal conserva el suyo',
    async (answer) => {
      behave({ answer })

      const report = reportOf(HEAD_ROWS + 10)

      await expect(startReportReader().read(bodyOf(report))).resolves.toEqual(report)
      expect(FakeWorker.received).toEqual(['buffer'])
    },
  )

  it.each(['failed', 'error', 'messageerror'] as const)(
    'si el Worker contesta «%s» con la corriente ya cedida, avisa de que el cuerpo se perdio',
    async (answer) => {
      behave({ acceptStream: true, answer })

      await expect(startReportReader().read(bodyOf(reportOf(5)))).rejects.toBeInstanceOf(
        ReportReaderLost,
      )
    },
  )

  it('dispose libera el Worker aunque no se haya leido nada', () => {
    behave({})

    startReportReader().dispose()

    expect(FakeWorker.terminated).toBe(1)
  })
})

/** Un `fetch` que sirve el informe en cada llamada, con un cuerpo nuevo. */
function stubReportFetch(report: PeriodReport): ReturnType<typeof vi.fn> {
  const spy = vi.fn(() => Promise.resolve(bodyOf(report)))

  vi.stubGlobal('fetch', spy)

  return spy
}

describe('generatePeriodReport', () => {
  const query = { from: '2026-03-01', to: '2026-03-31' }

  it('si el Worker se pierde con el cuerpo en la mano, vuelve a pedir el informe sin Worker', async () => {
    behave({ acceptStream: true, answer: 'error' })

    const report = reportOf(HEAD_ROWS + 10)
    const fetchSpy = stubReportFetch(report)

    await expect(generatePeriodReport(query)).resolves.toEqual(report)
    expect(fetchSpy).toHaveBeenCalledTimes(2)
    // La segunda peticion no abre otro Worker: se lee con response.json().
    expect(FakeWorker.created).toBe(1)
  })

  it('si el plazo vence con la corriente cedida, es un timeout y no una segunda peticion', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    behave({ acceptStream: true, answer: 'silent' })

    const fetchSpy = stubReportFetch(reportOf(5))
    const outcome = generatePeriodReport(query).then(
      () => null,
      (failure: unknown) => failure,
    )

    await vi.advanceTimersByTimeAsync(DEFAULT_READ_TIMEOUT_MS + 1)

    const failure = await outcome

    expect(failure).toBeInstanceOf(ApiError)
    expect((failure as ApiError).kind).toBe('timeout')
    expect(fetchSpy).toHaveBeenCalledTimes(1)
    expect(FakeWorker.terminated).toBeGreaterThanOrEqual(1)
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
