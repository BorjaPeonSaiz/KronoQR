// Calculos puros del tablero de credenciales (RF-QR-07), sin Vue, para poder
// probarlos sin montar el componente.
//
// El filtrado y la paginacion YA NO viven aqui: desde la 2.3.0 los hace el servidor
// (q, department_id, without_department, status, page, per_page), porque con toda la
// plantilla en memoria el tablero no escalaba (V5-PA-2, HC1).
import type { CredentialCoverage } from '@/shared/api/types'

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
