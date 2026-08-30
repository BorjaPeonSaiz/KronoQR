// Filtrado y paginacion EN CLIENTE del panel de credenciales (RF-QR-08).
//
// El contrato devuelve toda la plantilla del centro sin paginar, a proposito:
// es lo que permite decir «faltan 3 de 60» sin que el filtro mienta sobre el
// denominador. Esta pantalla filtra y pagina lo que ya tiene en memoria, y
// deja aparte —puras, sin Vue— las dos operaciones para poder probarlas sin
// montar el componente.
import type {
  CredentialCoverage,
  CredentialLifecycleStatus,
  CredentialStatusRow,
} from '@/shared/api/types'

/** Valor de `department` que significa «sin departamento asignado». */
export const NO_DEPARTMENT = '__no_department__'

/** Tamano de pagina EN CLIENTE del tablero (RF-QR-08). */
export const CLIENT_PER_PAGE = 30

export interface CredentialRowFilters {
  /** Subcadena sobre nombre y codigo de empleado. Insensible a mayusculas y a acentos. */
  search: string
  /** `''` = todos. El nombre exacto de un departamento, o `NO_DEPARTMENT`. */
  department: string
  /** `''` = todos. */
  status: CredentialLifecycleStatus | ''
}

export interface PagedResult<T> {
  data: T[]
  page: number
  perPage: number
  total: number
  totalPages: number
}

/**
 * Quita diacriticos y pasa a minusculas, para comparar «García» con «garcia».
 *
 * Se descompone en NFD (la vocal acentuada pasa a ser vocal + marca de
 * combinacion) y se descartan los puntos de codigo del bloque Unicode de
 * marcas diacriticas combinantes (U+0300–U+036F), sin depender de un literal
 * de caracter no-ASCII en el codigo fuente.
 */
function normalize(value: string): string {
  const COMBINING_DIACRITICS_START = 0x0300
  const COMBINING_DIACRITICS_END = 0x036f

  let result = ''

  for (const char of value.normalize('NFD')) {
    const codePoint = char.codePointAt(0) ?? 0

    if (codePoint < COMBINING_DIACRITICS_START || codePoint > COMBINING_DIACRITICS_END) {
      result += char
    }
  }

  return result.toLowerCase().trim()
}

export function filterRows(
  rows: readonly CredentialStatusRow[],
  filters: CredentialRowFilters,
): CredentialStatusRow[] {
  const search = normalize(filters.search)

  return rows.filter((row) => {
    if (filters.status !== '' && row.status !== filters.status) {
      return false
    }

    if (filters.department !== '') {
      const isNoDepartment = row.department_name === null
      const matches =
        filters.department === NO_DEPARTMENT
          ? isNoDepartment
          : row.department_name === filters.department

      if (!matches) {
        return false
      }
    }

    if (search === '') {
      return true
    }

    return normalize(`${row.full_name} ${row.employee_code}`).includes(search)
  })
}

/** Los `department_name` distintos presentes en las filas, mas «sin departamento» si aplica. */
export function departmentOptionsFrom(rows: readonly CredentialStatusRow[]): string[] {
  const names = new Set<string>()
  let hasNoDepartment = false

  for (const row of rows) {
    if (row.department_name === null) {
      hasNoDepartment = true
    } else {
      names.add(row.department_name)
    }
  }

  const sorted = [...names].sort((a, b) => a.localeCompare(b))

  return hasNoDepartment ? [...sorted, NO_DEPARTMENT] : sorted
}

/** Avance de la reimpresion durante una rotacion de clave (RF-QR-07). */
export interface ReprintProgress {
  /** La clave saliente. Va impresa en cada tarjeta: no es un secreto. */
  keyId: string
  /** Cuantas personas siguen fichando con ella. */
  pending: number
  /** Cuantas ya llevan la tarjeta nueva. */
  done: number
  total: number
  /** Entero de 0 a 100, para la barra y para el texto. */
  percent: number
}

/**
 * El avance de la reimpresion, o `null` cuando no hay ninguna rotacion abierta
 * —que es el estado normal—.
 *
 * Se deriva del `summary` y **no de las filas**: las filas pueden venir
 * filtradas en cliente, y el avance tiene que medirse contra la plantilla
 * entera. Es puro y vive aqui, fuera del componente, para poder probarlo sin
 * montar nada.
 *
 * `pending` cuenta a quien todavia ficha con la clave vieja. Esas personas **si
 * pueden fichar**: durante el solape las dos claves valen, y ese es justo el
 * motivo de que la rotacion se pueda repartir en semanas.
 */
export function reprintProgressOf(summary: CredentialCoverage | null): ReprintProgress | null {
  if (summary === null || summary.retiring_key_id === null) {
    return null
  }

  const total = summary.employees
  const pending = Math.min(summary.pending_reprint, total)
  const done = total - pending

  return {
    keyId: summary.retiring_key_id,
    pending,
    done,
    total,
    // Sin plantilla no hay nada que reimprimir, y eso es un 100 % hecho, no una
    // division por cero.
    percent: total === 0 ? 100 : Math.round((done / total) * 100),
  }
}

export function paginate<T>(rows: readonly T[], page: number, perPage: number): PagedResult<T> {
  const total = rows.length
  const totalPages = Math.max(Math.ceil(total / perPage), 1)
  const clampedPage = Math.min(Math.max(page, 1), totalPages)
  const start = (clampedPage - 1) * perPage

  return {
    data: rows.slice(start, start + perPage),
    page: clampedPage,
    perPage,
    total,
    totalPages,
  }
}
