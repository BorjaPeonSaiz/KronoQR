// Cabeceras de seguridad de PRODUCCION para el servidor de los E2E (PIN-04,
// verificacion de la 2.1.0).
//
// POR QUE EXISTE. En la 2.1.0 el quiosco instalado no podia fichar por PIN:
// Nginx sirve `script-src 'self'` y el WebAssembly de libsodium
// (`pinSealing.ts`) no compila sin `'wasm-unsafe-eval'`. Ningun E2E lo vio
// porque `vite preview` no servia NINGUNA cabecera de seguridad. Desde aqui,
// el servidor de los E2E sirve las MISMAS cabeceras que Nginx, leidas del
// MISMO fichero que incluye Nginx (`infra/docker/nginx/snippets/
// security-headers.conf`, via `extra/spa.conf` -> `location ^~ /kiosk/`).
//
// NO SE COPIA LA CSP A MANO. Una segunda copia divergiria el primer dia que
// alguien tocara el snippet, y el E2E volveria a probar un navegador que no
// existe. Si Nginx cambia, el E2E cambia con el.
//
// FALLA RUIDOSAMENTE. Un snippet que no existe, que no declara CSP, cuya CSP
// no tiene `script-src` o que trae un `add_header` que este lector no sabe
// interpretar es un ERROR, nunca «sin cabeceras»: degradar en silencio es
// exactamente el hueco que dejo pasar PIN-01.

import { existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Variable de entorno que apunta a otro snippet. Solo para demostrar que el
 * E2E se pone en rojo con una CSP distinta (una copia fuera del repositorio);
 * nunca para relajar la CSP de las pruebas.
 */
export const SECURITY_HEADERS_SNIPPET_ENV = 'KRONOQR_SECURITY_HEADERS_SNIPPET'

/** El snippet real que incluye Nginx en produccion. */
// Con `resolve` y no con `new URL(..., import.meta.url)`: Vite reescribe ese
// patron como URL de recurso (http:) cuando el modulo pasa por Vitest.
export const PRODUCTION_SECURITY_HEADERS_SNIPPET = resolve(
  dirname(fileURLToPath(import.meta.url)),
  '../../../../infra/docker/nginx/snippets/security-headers.conf',
)

const CSP_HEADER = 'Content-Security-Policy'

/** `add_header Nombre "valor" always;` — la unica forma que usa el snippet. */
const ADD_HEADER_LINE = /^add_header\s+([A-Za-z0-9-]+)\s+"([^"$]*)"\s+always\s*;$/

export class SecurityHeadersSnippetError extends Error {
  constructor(source: string, reason: string) {
    super(`security-headers snippet ${source}: ${reason}`)
    this.name = 'SecurityHeadersSnippetError'
  }
}

/**
 * Interpreta los `add_header` del snippet. Las lineas vacias y los
 * comentarios (`#` al principio de linea) se ignoran; cualquier otra directiva
 * es un error, porque significaria que Nginx sirve algo que aqui no se sirve.
 */
export function parseSecurityHeaders(conf: string, source: string): Record<string, string> {
  const headers: Record<string, string> = {}

  for (const rawLine of conf.split(/\r?\n/)) {
    const line = rawLine.trim()
    if (line === '' || line.startsWith('#')) {
      continue
    }

    const match = ADD_HEADER_LINE.exec(line)
    const name = match?.[1]
    const value = match?.[2]
    if (name === undefined || value === undefined) {
      throw new SecurityHeadersSnippetError(source, `directiva no reconocida: ${line}`)
    }
    if (Object.keys(headers).some((known) => known.toLowerCase() === name.toLowerCase())) {
      throw new SecurityHeadersSnippetError(source, `cabecera duplicada: ${name}`)
    }
    headers[name] = value
  }

  const csp = headers[CSP_HEADER]
  if (csp === undefined) {
    throw new SecurityHeadersSnippetError(source, `no declara ${CSP_HEADER}`)
  }
  if (cspDirective(csp, 'script-src') === null) {
    throw new SecurityHeadersSnippetError(source, `la ${CSP_HEADER} no tiene script-src`)
  }

  return headers
}

/** Lee el snippet (el real, salvo que la variable de entorno apunte a otro). */
export function readProductionSecurityHeaders(
  snippetPath: string = process.env[SECURITY_HEADERS_SNIPPET_ENV] ??
    PRODUCTION_SECURITY_HEADERS_SNIPPET,
): Record<string, string> {
  if (!existsSync(snippetPath)) {
    throw new SecurityHeadersSnippetError(snippetPath, 'no existe')
  }

  return parseSecurityHeaders(readFileSync(snippetPath, 'utf8'), snippetPath)
}

/** Las fuentes de una directiva CSP (`script-src` -> `["'self'", ...]`), o `null`. */
export function cspDirective(csp: string, directive: string): string[] | null {
  for (const part of csp.split(';')) {
    const [name, ...sources] = part.trim().split(/\s+/)
    if (name === directive) {
      return sources
    }
  }
  return null
}
