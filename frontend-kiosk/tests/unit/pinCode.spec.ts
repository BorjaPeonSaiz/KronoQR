import { describe, expect, it } from 'vitest'
import {
  hasEmployeeCodeShape,
  isValidPinShape,
  MAX_EMPLOYEE_CODE_LENGTH,
  normalizeEmployeeCode,
  PIN_MAX_LENGTH,
  PIN_MIN_LENGTH,
} from '@/features/pin/domain/pinCode'

describe('forma del PIN de 6 a 8 digitos (ADR-050)', () => {
  it('fija el rango en 6 a 8', () => {
    expect(PIN_MIN_LENGTH).toBe(6)
    expect(PIN_MAX_LENGTH).toBe(8)
  })

  it.each(['483920', '4839201', '48392016'])('acepta %s', (pin) => {
    expect(isValidPinShape(pin)).toBe(true)
  })

  it('rechaza 5 y 9 digitos y el vacio', () => {
    expect(isValidPinShape('48392')).toBe(false)
    expect(isValidPinShape('483920165')).toBe(false)
    expect(isValidPinShape('')).toBe(false)
  })

  it('rechaza cualquier caracter que no sea digito', () => {
    expect(isValidPinShape('48392a')).toBe(false)
    expect(isValidPinShape('483 20')).toBe(false)
    expect(isValidPinShape('483-20')).toBe(false)
    expect(isValidPinShape('48392016\n')).toBe(false)
  })
})

describe('forma del codigo de empleado', () => {
  it('acepta cualquier codigo no vacio dentro del techo del contrato', () => {
    expect(hasEmployeeCodeShape('E7QK2MXPR')).toBe(true)
    expect(MAX_EMPLOYEE_CODE_LENGTH).toBe(32)
  })

  it('rechaza vacio o solo espacios: no gasta cola por nada', () => {
    expect(hasEmployeeCodeShape('')).toBe(false)
    expect(hasEmployeeCodeShape('   ')).toBe(false)
  })

  it('rechaza lo que excede el techo del contrato', () => {
    expect(hasEmployeeCodeShape('A'.repeat(32))).toBe(true)
    expect(hasEmployeeCodeShape('A'.repeat(33))).toBe(false)
  })

  it('no restringe el alfabeto (regla dura 19): un codigo antiguo con 0/O/1/I/L se acepta igual', () => {
    // El alfabeto SIN esos caracteres es solo el de generacion de codigos
    // NUEVOS; `EmployeeCode::fromString()` en el servidor sigue aceptando
    // cualquier alfanumerico para no dejar sin fichar a alguien con un codigo
    // de antes de ese cambio. El quiosco no puede ser mas estricto que el
    // servidor al que le manda el codigo.
    expect(hasEmployeeCodeShape('E0O1IL234')).toBe(true)
  })

  it('normaliza a mayusculas y sin espacios en los bordes', () => {
    expect(normalizeEmployeeCode('  e7qk2mxpr  ')).toBe('E7QK2MXPR')
  })
})
