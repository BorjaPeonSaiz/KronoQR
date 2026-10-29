// El Worker del informe por periodo: parsea el cuerpo fuera del hilo principal y lo
// devuelve en trozos (`periodReportChunks.ts` explica el porque).
//
// El primer trozo (cabecera y primeras filas) sale en cuanto esta parseado; los demas, de uno
// en uno y cada vez que el hilo principal pide el siguiente (`NextChunk`): asi la
// deserializacion de 20 000 filas no se le echa encima de golpe, y entre trozo y trozo
// atiende teclas, clics y el pintado.
import { splitReport, type ChunkMessage, type NextChunk } from './periodReportChunks'

type Inbound = ArrayBuffer | ReadableStream<Uint8Array> | NextChunk

interface WorkerScope {
  onmessage: ((event: MessageEvent<Inbound>) => void) | null
  postMessage: (message: ChunkMessage) => void
}

const scope = self as unknown as WorkerScope

let pending: Generator<ChunkMessage> | null = null

function isNextChunk(data: Inbound): data is NextChunk {
  return !(data instanceof ArrayBuffer) && !(data instanceof ReadableStream)
}

scope.onmessage = (event) => {
  if (isNextChunk(event.data)) {
    postNext()
  } else {
    void parse(event.data)
  }
}

function postNext(): void {
  const next = pending?.next()

  if (next === undefined || next.done === true) {
    pending = null

    return
  }

  scope.postMessage(next.value)
}

async function parse(body: ArrayBuffer | ReadableStream<Uint8Array>): Promise<void> {
  let payload: unknown

  try {
    const buffer = body instanceof ReadableStream ? await new Response(body).arrayBuffer() : body

    payload = JSON.parse(new TextDecoder().decode(buffer))
  } catch {
    scope.postMessage({ kind: 'failed' })

    return
  }

  pending = splitReport(payload)
  postNext()
}

// Avisa de que ya esta listo: mientras no lo diga, el cuerpo se le copia en vez de cederle la corriente.
scope.postMessage({ kind: 'ready' })
