// Funciones puras del filtrado y la paginacion EN CLIENTE del panel de
// credenciales (RF-QR-08). Sin Vue, sin `fetch`: solo datos de entrada y
// datos de salida.
import { describe, expect, it } from 'vitest'
import type { CredentialStatusRow } from '@/shared/api/types'
import {
  NO_DEPARTMENT,
  departmentOptionsFrom,
  filterRows,
  paginate,
} from '@/features/credentials/useCredentialRows'

function row(overrides: Partial<CredentialStatusRow> = {}): CredentialStatusRow {
  return {
    employee_uuid: crypto.randomUUID(),
    employee_code: 'E7QK2MXPR',
    full_name: 'Lucia Martinez Prieto',
    department_name: 'Recepcion',
    status: 'pending_print',
    credential: null,
    ...overrides,
  }
}

describe('useCredentialRows (RF-QR-08)', () => {
  describe('filterRows', () => {
    it('la busqueda ignora mayusculas y acentos sobre el nombre y el codigo', () => {
      const rows = [
        row({ full_name: 'García Núñez, María', employee_code: 'ABC123' }),
        row({ full_name: 'Otra Persona', employee_code: 'XYZ999' }),
      ]

      const found = filterRows(rows, { search: 'GARCIA nunez', department: '', status: '' })

      expect(found).toHaveLength(1)
      expect(found[0]?.employee_code).toBe('ABC123')
    })

    it('la busqueda tambien encuentra por el codigo de empleado', () => {
      const rows = [row({ employee_code: 'E7QK2MXPR' }), row({ employee_code: 'OTRO001' })]

      const found = filterRows(rows, { search: 'e7qk2mxpr', department: '', status: '' })

      expect(found.map((item) => item.employee_code)).toEqual(['E7QK2MXPR'])
    })

    it('filtra por estado exacto', () => {
      const rows = [
        row({ status: 'pending_print' }),
        row({ status: 'delivered' }),
        row({ status: 'revoked' }),
      ]

      const found = filterRows(rows, { search: '', department: '', status: 'delivered' })

      expect(found).toHaveLength(1)
      expect(found[0]?.status).toBe('delivered')
    })

    it('filtra por el nombre exacto de un departamento', () => {
      const rows = [row({ department_name: 'Cocina' }), row({ department_name: 'Recepcion' })]

      const found = filterRows(rows, { search: '', department: 'Cocina', status: '' })

      expect(found).toHaveLength(1)
      expect(found[0]?.department_name).toBe('Cocina')
    })

    it('el sentinela NO_DEPARTMENT filtra a quien no tiene departamento', () => {
      const rows = [row({ department_name: null }), row({ department_name: 'Cocina' })]

      const found = filterRows(rows, { search: '', department: NO_DEPARTMENT, status: '' })

      expect(found).toHaveLength(1)
      expect(found[0]?.department_name).toBeNull()
    })

    it('combina busqueda, departamento y estado con AND', () => {
      const rows = [
        row({ full_name: 'Ana Ruiz', department_name: 'Cocina', status: 'delivered' }),
        row({ full_name: 'Ana Ruiz', department_name: 'Recepcion', status: 'delivered' }),
        row({ full_name: 'Otro', department_name: 'Cocina', status: 'delivered' }),
      ]

      const found = filterRows(rows, { search: 'ana', department: 'Cocina', status: 'delivered' })

      expect(found).toHaveLength(1)
      expect(found[0]?.department_name).toBe('Cocina')
    })
  })

  describe('departmentOptionsFrom', () => {
    it('devuelve los nombres distintos, ordenados, y el sentinela si falta alguno', () => {
      const rows = [
        row({ department_name: 'Recepcion' }),
        row({ department_name: 'Cocina' }),
        row({ department_name: 'Cocina' }),
        row({ department_name: null }),
      ]

      expect(departmentOptionsFrom(rows)).toEqual(['Cocina', 'Recepcion', NO_DEPARTMENT])
    })

    it('no incluye el sentinela cuando todo el mundo tiene departamento', () => {
      const rows = [row({ department_name: 'Cocina' })]

      expect(departmentOptionsFrom(rows)).toEqual(['Cocina'])
    })
  })

  describe('paginate', () => {
    it('trocea en paginas de tamaño fijo y calcula el total de paginas', () => {
      const rows = Array.from({ length: 62 }, (_, index) => row({ employee_code: String(index) }))

      const page1 = paginate(rows, 1, 25)

      expect(page1.data).toHaveLength(25)
      expect(page1.total).toBe(62)
      expect(page1.totalPages).toBe(3)
      expect(page1.page).toBe(1)

      const page3 = paginate(rows, 3, 25)

      expect(page3.data).toHaveLength(12)
    })

    it('acota una pagina fuera de rango a la ultima disponible', () => {
      const rows = Array.from({ length: 10 }, () => row())

      const result = paginate(rows, 99, 25)

      expect(result.page).toBe(1)
      expect(result.data).toHaveLength(10)
    })

    it('nunca deja una pagina por debajo de 1', () => {
      const result = paginate([], 0, 25)

      expect(result.page).toBe(1)
      expect(result.totalPages).toBe(1)
      expect(result.data).toEqual([])
    })
  })
})
