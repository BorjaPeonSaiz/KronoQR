// Un enlace lento COMPARTIDO entre la pagina y su service worker (tarea 17.10).
//
// POR QUE NO BASTA CON CDP. `Network.emulateNetworkConditions` se aplica a la
// sesion de la PAGINA; las descargas del precacheo las hace el service worker,
// que es otro objetivo de DevTools, y salen sin estrangular. En la tablet es al
// reves: el precacheo (~1 460 KiB, 29 entradas) y el decodificador compiten por
// la misma wifi. Este proxy se pone delante de `vite preview` y reparte UN cubo
// de bytes por segundo entre todas las respuestas, con la latencia por peticion
// del perfil, sea quien sea quien las pide.
//
// Es un modelo, no una red real: sin TCP ni TLS, la latencia se aplica una vez
// por peticion (como el perfil Lighthouse de `perf-lcp.spec.ts`).

import { request as httpRequest, createServer } from 'node:http'
import type { IncomingMessage, ServerResponse } from 'node:http'
import type { AddressInfo } from 'node:net'

export interface LinkProfile {
  /** Latencia anadida a cada peticion, en ms. */
  readonly latencyMs: number
  /** Ancho de banda de bajada COMPARTIDO, en bytes por segundo. */
  readonly downloadBytesPerSecond: number
}

export interface ThrottledProxy {
  readonly origin: string
  close(): Promise<void>
}

/** Trozo con el que se reparte el cubo: pequeño para que dos descargas se intercalen. */
const SLICE_BYTES = 4 * 1024

function wait(ms: number): Promise<void> {
  // eslint-disable-next-line no-restricted-syntax -- es el enlace simulado el que espera, no la prueba
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * Un cubo de bytes compartido: cada trozo reserva su ventana de envio a
 * continuacion de la anterior, asi que N descargas a la vez van a 1/N.
 */
function sharedLink(bytesPerSecond: number): (bytes: number) => Promise<void> {
  let nextFreeAt = 0
  return async (bytes) => {
    const now = Date.now()
    const start = Math.max(now, nextFreeAt)
    nextFreeAt = start + (bytes / bytesPerSecond) * 1000
    await wait(nextFreeAt - now)
  }
}

export async function startThrottledProxy(
  upstreamOrigin: string,
  profile: LinkProfile,
): Promise<ThrottledProxy> {
  const upstream = new URL(upstreamOrigin)
  const transmit = sharedLink(profile.downloadBytesPerSecond)

  async function relay(incoming: IncomingMessage, outgoing: ServerResponse): Promise<void> {
    await wait(profile.latencyMs)
    const proxied = httpRequest(
      {
        hostname: upstream.hostname,
        port: upstream.port,
        path: incoming.url,
        method: incoming.method,
        headers: { ...incoming.headers, host: upstream.host },
      },
      (response) => {
        outgoing.writeHead(response.statusCode ?? 502, response.headers)
        void (async () => {
          for await (const chunk of response) {
            const buffer = chunk as Buffer
            for (let offset = 0; offset < buffer.length; offset += SLICE_BYTES) {
              const slice = buffer.subarray(offset, offset + SLICE_BYTES)
              await transmit(slice.length)
              outgoing.write(slice)
            }
          }
          outgoing.end()
        })().catch(() => outgoing.destroy())
      },
    )
    proxied.on('error', () => {
      outgoing.writeHead(502)
      outgoing.end()
    })
    incoming.pipe(proxied)
  }

  const server = createServer((incoming, outgoing) => {
    void relay(incoming, outgoing)
  })
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
  const { port } = server.address() as AddressInfo

  return {
    origin: `http://127.0.0.1:${port}`,
    close: () =>
      new Promise<void>((resolve) => {
        server.closeAllConnections()
        server.close(() => resolve())
      }),
  }
}
