import { describe, expect, it } from 'vitest'
import {
  hasEmployeeCodeShape,
  isSixDigitPin,
  MAX_EMPLOYEE_CODE_LENGTH,
  normalizeEmployeeCode,
  PIN_LENGTH,
} from '@/features/pin/domain/pinCode'

describe('forma del PIN de 6 digitos', () => {
  it('acepta exactamente 6 digitos', () => {
    expect(isSixDigitPin('483920')).toBe(true)
    expect(PIN_LENGTH).toBe(6)
  })

  it('rechaza cualquier otra longitud', () => {
    expect(isSixDigitPin('48392')).toBe(false)
    expect(isSixDigitPin('4839201')).toBe(false)
    expect(isSixDigitPin('')).toBe(false)
  })

  it('rechaza cualquier caracter que no sea digito', () => {
    expect(isSixDigitPin('48392a')).toBe(false)
    expect(isSixDigitPin('483 20')).toBe(false)
    expect(isSixDigitPin('483-20')).toBe(false)
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
