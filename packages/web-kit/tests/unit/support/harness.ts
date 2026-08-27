// Andamiaje minimo para las pruebas de este paquete: solo lo que hace falta
// para poner un doble delante de `fetch`. A diferencia del arnes de cada SPA
// (`frontend-admin/tests/unit/support/harness.ts`), aqui no hay componentes que
// montar ni router/Pinia/i18n que instanciar: la suite de este paquete cubre
// logica pura y el cliente HTTP base, no vistas.
import { vi } from 'vitest'

/** Una respuesta JSON del servidor. */
export function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

/** Una respuesta `application/problem+json` (RFC 9457), como las del contrato. */
export function problemResponse(
  status: number,
  type: string,
  extra: Record<string, unknown> = {},
): Response {
  return new Response(JSON.stringify({ type, title: 'Problema', status, ...extra }), {
    status,
    headers: { 'Content-Type': 'application/problem+json' },
  })
}

export type FetchHandler = (
  url: string,
  init: RequestInit | undefined,
) => Response | Promise<Response>

/** Sustituye `fetch` por un enrutador de dobles. Devuelve el espia. */
export function stubFetch(handler: FetchHandler): ReturnType<typeof vi.fn> {
  const spy = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) =>
    handler(String(input), init),
  )

  vi.stubGlobal('fetch', spy)

  return spy as unknown as ReturnType<typeof vi.fn>
}
