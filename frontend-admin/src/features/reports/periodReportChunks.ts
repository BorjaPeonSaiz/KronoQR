// El cuerpo del informe por periodo, partido en trozos (RNF-P-04).
//
// POR QUE. El servidor admite hasta 20 000 filas por informe (~12 MB). Leerlas con
// `response.json()` es una sola tarea de cientos de ms en el hilo principal —con la
// CPU ×4 del portatil de recepcion, mas de 300— durante la cual el panel no atiende
// teclas ni clics, y la primera fila no se pinta hasta que se ha leido la ultima.
//
// QUE. El texto se parsea en un Worker (`periodReport.worker.ts`), que devuelve la
// CABECERA —`meta`, `group_by` y las primeras filas— y despues el resto en trozos de
// `ROWS_PER_CHUNK`. El hilo principal pinta con la cabecera mientras llegan los demas
// y nunca recibe mas de un trozo por tarea. El resultado final es identico, fila por
// fila, al de `response.json()`: aqui no se calcula, no se filtra ni se reordena nada.
//
// SIN WORKER (jsdom, un navegador que lo bloquee, un fallo al arrancarlo) se parsea
// en el hilo principal, entero: mas lento con 20 000 filas, pero el informe sale.
import type { PeriodReport } from '@/shared/api/types'
import { yieldToMain } from '@/shared/scheduling/yieldToMain'

/** Filas de la cabecera: lo que cabe en pantalla con holgura. */
export const HEAD_ROWS = 100
/** Cuanto se espera a que el Worker arranque antes de leer el cuerpo en el hilo principal. */
const WORKER_START_TIMEOUT_MS = 1_000
/** Filas por mensaje del Worker: cada trozo se deserializa en una tarea corta. */
export const ROWS_PER_CHUNK = 1_000

export type PeriodReportRows = PeriodReport['data']
export type PeriodReportHead = Omit<PeriodReport, 'data'>

/** Lo que el Worker devuelve, en este orden: `head`, cero o mas `rows`, `done`. */
export type ChunkMessage =
  | { kind: 'ready' }
  | { kind: 'head'; head: PeriodReportHead; rows: PeriodReportRows }
  | { kind: 'rows'; rows: PeriodReportRows }
  | { kind: 'done' }
  | { kind: 'whole'; payload: unknown }
  | { kind: 'failed' }

/** Lo que el hilo principal le dice al Worker para que envie el siguiente trozo. */
export interface NextChunk {
  kind: 'next'
}

/** Parte un informe ya parseado en los mensajes que enviaria el Worker. */
export function* splitReport(payload: unknown): Generator<ChunkMessage> {
  if (!isReportShape(payload)) {
    yield { kind: 'whole', payload }

    return
  }

  const { data, ...head } = payload

  yield { kind: 'head', head: head as PeriodReportHead, rows: data.slice(0, HEAD_ROWS) }

  for (let start = HEAD_ROWS; start < data.length; start += ROWS_PER_CHUNK) {
    yield { kind: 'rows', rows: data.slice(start, start + ROWS_PER_CHUNK) }
  }

  yield { kind: 'done' }
}

function isReportShape(payload: unknown): payload is PeriodReport {
  return (
    typeof payload === 'object' &&
    payload !== null &&
    Array.isArray((payload as { data?: unknown }).data) &&
    typeof (payload as { meta?: unknown }).meta === 'object'
  )
}

/**
 * El Worker se perdio DESPUES de recibir el cuerpo (que se le cedio sin copiarlo): el hilo
 * principal ya no lo tiene. Quien pidio el informe lo vuelve a pedir sin Worker.
 */
export class ReportReaderLost extends Error {
  constructor() {
    super('report worker lost after taking the body')
    this.name = 'ReportReaderLost'
  }
}

/** El navegador no puede ceder la corriente del cuerpo a un Worker. */
class NotTransferable extends Error {}

export interface ReportReader {
  /** Lee la respuesta. `onHead` (de `startReportReader`) recibe las primeras filas en cuanto estan. */
  read: (response: Response) => Promise<unknown>
  /** Libera el Worker si la peticion no llego a dar respuesta. Seguro llamarlo siempre. */
  dispose: () => void
}

/**
 * Prepara la lectura del informe ANTES de pedirlo: arrancar un Worker cuesta cientos de ms en
 * un portatil lento (descargar y evaluar su modulo), y asi ese tiempo corre en paralelo a la
 * espera de la respuesta en vez de sumarse a ella.
 */
export function startReportReader(onHead?: (partial: PeriodReport) => void): ReportReader {
  const worker = openWorker()
  // Cierto cuando el Worker avisa de que ya arranco; falso si falla o tarda demasiado.
  const ready = new Promise<boolean>((resolve) => {
    if (worker === null) {
      resolve(false)

      return
    }

    const timer = setTimeout(() => {
      resolve(false)
    }, WORKER_START_TIMEOUT_MS)

    worker.addEventListener('message', (event: MessageEvent<ChunkMessage>) => {
      if (event.data.kind === 'ready') {
        clearTimeout(timer)
        resolve(true)
      }
    })
    worker.addEventListener('error', () => {
      clearTimeout(timer)
      resolve(false)
    })
  })

  return {
    read: async (response) => {
      const body = response.body

      // Con el Worker ya arrancado, la propia CORRIENTE del cuerpo se le cede: ni se copia ni
      // se lee en el hilo principal, donde recibir 12 MB es una tarea de ~100 ms con la CPU ×4
      // (y el navegador lo paga cediendo el hilo otro tanto despues). No todos los navegadores
      // pueden transferir una corriente; en ese caso se lee a mano, como sin Worker.
      if (worker !== null && body !== null && (await ready)) {
        try {
          return await parseInWorker(
            worker,
            () => {
              worker.postMessage(body, [body])
            },
            onHead,
          )
        } catch (failure) {
          if (!(failure instanceof NotTransferable)) {
            throw new ReportReaderLost()
          }
        }
      }

      const buffer = await response.arrayBuffer()

      if (worker === null) {
        return parseHere(buffer)
      }

      // Sin la corriente: el cuerpo se COPIA al Worker (lo recogera al arrancar) y, si falla, el
      // hilo principal conserva el suyo.
      try {
        return await parseInWorker(
          worker,
          () => {
            worker.postMessage(buffer)
          },
          onHead,
        )
      } catch {
        return parseHere(buffer)
      }
    },
    dispose: () => {
      worker?.terminate()
    },
  }
}

function openWorker(): Worker | null {
  if (typeof Worker === 'undefined') {
    return null
  }

  try {
    return new Worker(new URL('./periodReport.worker.ts', import.meta.url), { type: 'module' })
  } catch {
    return null
  }
}

/** Pide el siguiente trozo cediendo antes el hilo: lo que este esperando (un clic, el pintado) pasa primero. */
function askForNext(worker: Worker): void {
  void yieldToMain().then(() => {
    worker.postMessage({ kind: 'next' } satisfies NextChunk)
  })
}

function parseHere(buffer: ArrayBuffer): unknown {
  return JSON.parse(new TextDecoder().decode(buffer))
}

function parseInWorker(
  worker: Worker,
  send: () => void,
  onHead: ((partial: PeriodReport) => void) | undefined,
): Promise<unknown> {
  return new Promise((resolve, reject) => {
    let head: PeriodReportHead | null = null
    const rows: PeriodReport['data'][number][] = []

    // Los oyentes se quitan al terminar o al descartar el intento: el Worker puede servir despues
    // a otro (si la corriente no se pudo ceder) y no debe contestar a dos lectores.
    function detach(): void {
      worker.removeEventListener('message', onMessage)
      worker.removeEventListener('error', onError)
    }

    function finish(settle: () => void): void {
      detach()
      worker.terminate()
      settle()
    }

    function onMessage(event: MessageEvent<ChunkMessage>): void {
      const message = event.data

      switch (message.kind) {
        case 'ready':
          break
        case 'head':
          head = message.head
          rows.push(...message.rows)
          onHead?.({ ...head, data: [...rows] })
          askForNext(worker)
          break
        case 'rows':
          rows.push(...message.rows)
          askForNext(worker)
          break
        case 'whole':
          finish(() => {
            resolve(message.payload)
          })
          break
        case 'failed':
          finish(() => {
            reject(new Error('report worker failed'))
          })
          break
        case 'done':
          finish(() => {
            resolve(head === null ? null : { ...head, data: rows })
          })
          break
      }
    }

    function onError(): void {
      finish(() => {
        reject(new Error('report worker error'))
      })
    }

    worker.addEventListener('message', onMessage)
    worker.addEventListener('error', onError)

    try {
      send()
    } catch {
      detach()
      reject(new NotTransferable())
    }
  })
}
