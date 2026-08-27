import { describe, expect, it } from 'vitest'
import { CREDENTIALS_MANAGE, EMPLOYEES_MANAGE, hasAbility } from '@/features/auth/abilities'

describe('ambitos del token', () => {
  it('reconoce el ambito exacto', () => {
    expect(hasAbility(['employees:*'], EMPLOYEES_MANAGE)).toBe(true)
  })

  it('reconoce el comodin de familia', () => {
    expect(hasAbility(['employees:*'], 'employees:read')).toBe(true)
  })

  it('reconoce el comodin total de administracion', () => {
    expect(hasAbility(['*'], CREDENTIALS_MANAGE)).toBe(true)
  })

  it('no concede una familia distinta', () => {
    expect(hasAbility(['employees:*'], CREDENTIALS_MANAGE)).toBe(false)
    expect(hasAbility(['attendance:read'], EMPLOYEES_MANAGE)).toBe(false)
  })

  it('no concede nada cuando el token no trae ambitos', () => {
    expect(hasAbility([], EMPLOYEES_MANAGE)).toBe(false)
  })
})
