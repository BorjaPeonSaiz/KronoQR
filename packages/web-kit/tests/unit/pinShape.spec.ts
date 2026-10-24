// La forma del PIN es la del contrato (V1-RV-02): si `docs/api/openapi.yaml`
// cambia `PortalLoginRequest.pin.pattern`, esta prueba falla antes de que el
// quiosco o el portal acepten una forma que el servidor ya no admite.
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { isValidPinShape, PIN_MAX_LENGTH, PIN_MIN_LENGTH, PIN_PATTERN } from '../../src/pinShape'

const contract = readFileSync(
  resolve(dirname(fileURLToPath(import.meta.url)), '../../../../docs/api/openapi.yaml'),
  'utf8',
)

/** El `pattern` de una propiedad dentro de un esquema de `components.schemas`. */
function schemaPropertyPattern(schema: string, property: string): string | null {
  const start = contract.indexOf(`\n    ${schema}:\n`)

  if (start === -1) {
    return null
  }

  // El esquema acaba en el siguiente nombre de esquema, a cuatro espacios.
  const rest = contract.slice(start + 1)
  const end = rest.slice(1).search(/\n {4}[A-Za-z]+:\n/)
  const body = end === -1 ? rest : rest.slice(0, end + 1)
  const match = new RegExp(`\\n {8}${property}:\\n(?: {10}.*\\n)*? {10}pattern: '([^']+)'`).exec(
    body,
  )

  return match?.[1] ?? null
}

describe('forma del PIN', () => {
  it('es el patron de PortalLoginRequest.pin del contrato', () => {
    expect(schemaPropertyPattern('PortalLoginRequest', 'pin')).toBe(PIN_PATTERN.source)
  })

  it('cubre las dos longitudes que se emiten (IssuedPin.pin)', () => {
    expect(schemaPropertyPattern('IssuedPin', 'pin')).toBe('^(?:[0-9]{6}|[0-9]{8})$')
    expect(PIN_MIN_LENGTH).toBe(6)
    expect(PIN_MAX_LENGTH).toBe(8)
  })

  it.each(['483920', '4839201', '48392016'])('admite %s', (pin) => {
    expect(isValidPinShape(pin)).toBe(true)
  })

  it.each(['', '48392', '483920165', '48392a', '483 20', '48392016\n'])('rechaza %j', (pin) => {
    expect(isValidPinShape(pin)).toBe(false)
  })
})
