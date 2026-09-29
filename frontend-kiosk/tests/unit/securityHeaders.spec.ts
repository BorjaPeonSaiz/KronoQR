// Lector del snippet de cabeceras de Nginx que usan los E2E (PIN-04).
//
// Lo que se vigila es que NUNCA degrade en silencio a «sin cabeceras»: eso es
// lo que dejo llegar PIN-01 a produccion.

import { describe, expect, it } from 'vitest'
import {
  cspDirective,
  parseSecurityHeaders,
  readProductionSecurityHeaders,
  SecurityHeadersSnippetError,
} from '../e2e/support/securityHeaders'

const SECURITY_HEADERS_CSP_LINE = `add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'" always;`

describe('lector del snippet de cabeceras de seguridad', () => {
  it('el snippet real de Nginx declara una CSP con script-src', () => {
    const headers = readProductionSecurityHeaders()

    expect(cspDirective(headers['Content-Security-Policy'] ?? '', 'script-src')).not.toBeNull()
  })

  it('lee cada add_header e ignora comentarios y lineas vacias', () => {
    const conf = `# comentario\n\n${SECURITY_HEADERS_CSP_LINE}\nadd_header X-Content-Type-Options "nosniff" always;\n`

    expect(parseSecurityHeaders(conf, 'prueba')).toEqual({
      'Content-Security-Policy': "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'",
      'X-Content-Type-Options': 'nosniff',
    })
  })

  it('un snippet que no existe es un error, no «sin cabeceras»', () => {
    expect(() => readProductionSecurityHeaders('/no/existe/security-headers.conf')).toThrow(
      SecurityHeadersSnippetError,
    )
  })

  it.each([
    ['sin CSP', 'add_header X-Content-Type-Options "nosniff" always;'],
    ['CSP sin script-src', `add_header Content-Security-Policy "default-src 'self'" always;`],
    ['add_header sin always', `add_header Content-Security-Policy "script-src 'self'";`],
    ['valor con variable de nginx', 'add_header Cache-Control $kronoqr_spa_cache always;'],
    ['otra directiva', `${SECURITY_HEADERS_CSP_LINE}\nexpires 1h;`],
    ['cabecera duplicada', `${SECURITY_HEADERS_CSP_LINE}\n${SECURITY_HEADERS_CSP_LINE}`],
  ])('rechaza un snippet %s', (_caso, conf) => {
    expect(() => parseSecurityHeaders(conf, 'prueba')).toThrow(SecurityHeadersSnippetError)
  })

  it('devuelve las fuentes de una directiva y null si no esta', () => {
    expect(
      cspDirective("default-src 'self'; script-src 'self' 'wasm-unsafe-eval'", 'script-src'),
    ).toEqual(["'self'", "'wasm-unsafe-eval'"])
    expect(cspDirective("default-src 'self'", 'script-src')).toBeNull()
  })
})
