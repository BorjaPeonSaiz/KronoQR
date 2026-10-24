/**
 * Los tokens con los que el grafico del panel pinta sus series (barras y
 * rosco), en orden. Cada uno se mide como componente grafico (>= 3:1, WCAG
 * 1.4.11) contra `surface-raised`, donde se dibuja el lienzo
 * (`tests/unit/theme.spec.ts`). El color solo NO identifica la serie: cada una
 * lleva ademas un patron (`ChartWithTable`), porque con un acento de
 * marca verdoso la salvia fija se confunde con el acento.
 *
 * Modulo aparte y minimo para que el bundle del panel no cargue la tabla de
 * parejas entera por importar cuatro nombres.
 */
export const chartSeriesTokens: readonly string[] = [
  '--kq-color-primary-strong',
  '--kq-color-accent',
  '--kq-color-warning',
  '--kq-color-text-muted',
]
