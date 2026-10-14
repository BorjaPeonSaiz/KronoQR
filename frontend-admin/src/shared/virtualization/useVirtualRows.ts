// Virtualizacion de filas de las tablas largas del panel (RNF-P-04).
//
// Cuatro tablas (informe por periodo, hallazgos de cumplimiento, bandeja de
// incidencias y presencia) pintaban lo mismo copiado: por debajo de un umbral
// se pintan todas las filas, que es mas simple y se prueba en jsdom sin medir
// nada; por encima solo la ventana visible mas un sobrepintado. Aqui vive ese
// mecanismo una sola vez, para que un ajuste no tenga que repetirse cuatro.
//
// LAS FILAS TIENEN ALTO FIJO: el virtualizador no mide el DOM, y el contenedor
// de las filas (`totalHeight`) reserva el alto de todas para que el scroll sea
// el real. Cada fila va posicionada con `start` (`translateY`).
import { useVirtualizer } from '@tanstack/vue-virtual'
import { computed, type ComputedRef, type Ref } from 'vue'

/** A partir de cuantas filas merece la pena no pintarlas todas. */
export const VIRTUALIZE_FROM = 80
/** Filas pintadas por encima y por debajo de la ventana visible. */
export const DEFAULT_OVERSCAN = 10

export interface VirtualRowsOptions<T> {
  /** Alto fijo de cada fila, en px. */
  rowHeight: number
  /** El contenedor con desplazamiento. */
  scroller: Readonly<Ref<HTMLElement | null>>
  /** Clave estable de la fila; por defecto, su indice. */
  key?: (item: T, index: number) => string
  /** Filas extra fuera de la ventana. */
  overscan?: number
}

export interface VirtualRow<T> {
  item: T
  /** Posicion en la lista completa, empezando en 0. */
  index: number
  /** Desplazamiento vertical de la fila, en px. */
  start: number
  key: string
}

export interface VirtualRows<T> {
  /** Las filas a pintar: todas por debajo del umbral, la ventana visible por encima. */
  visibleRows: ComputedRef<VirtualRow<T>[]>
  /** Alto del contenedor de filas, en px. */
  totalHeight: ComputedRef<number>
  virtual: ComputedRef<boolean>
}

export function useVirtualRows<T>(
  items: Readonly<Ref<readonly T[]>>,
  options: VirtualRowsOptions<T>,
): VirtualRows<T> {
  const keyOf = options.key ?? ((_item: T, index: number) => String(index))

  const virtual = computed(() => items.value.length >= VIRTUALIZE_FROM)

  const virtualizer = useVirtualizer(
    computed(() => ({
      count: virtual.value ? items.value.length : 0,
      getScrollElement: () => options.scroller.value,
      estimateSize: () => options.rowHeight,
      overscan: options.overscan ?? DEFAULT_OVERSCAN,
    })),
  )

  const visibleRows = computed<VirtualRow<T>[]>(() => {
    if (!virtual.value) {
      return items.value.map((item, index) => ({
        item,
        index,
        start: index * options.rowHeight,
        key: keyOf(item, index),
      }))
    }

    const rows: VirtualRow<T>[] = []

    for (const entry of virtualizer.value.getVirtualItems()) {
      const item = items.value[entry.index]

      if (item !== undefined) {
        rows.push({ item, index: entry.index, start: entry.start, key: keyOf(item, entry.index) })
      }
    }

    return rows
  })

  const totalHeight = computed(() =>
    virtual.value ? virtualizer.value.getTotalSize() : items.value.length * options.rowHeight,
  )

  return { visibleRows, totalHeight, virtual }
}
