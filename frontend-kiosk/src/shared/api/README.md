# Cliente HTTP

| Fichero       | Que es                                                                    |
| ------------- | ------------------------------------------------------------------------- |
| `schema.d.ts` | **Generado** de `docs/api/openapi.yaml` con `npm run api:generate`        |
| `types.ts`    | Alias con nombre corto sobre lo generado. No define ninguna forma propia  |
| `client.ts`   | Transporte: cabeceras, tiempos de espera y traduccion de fallos a valores |

`schema.d.ts` **no se escribe a mano y no se edita**: el contrato es la fuente de verdad de
la API (CLAUDE.md, orden de autoridad 2; ADR-013). Esta excluido de Prettier, porque no
tiene sentido discutir el formato de un fichero que nadie toca.

Si el contrato cambia, se regenera y `vue-tsc` senala en `types.ts` todo lo que ha dejado de
encajar.

## La regla que gobierna `client.ts`

**Nada de lo que pase aqui puede impedir fichar** (regla dura 19). Por eso no lanza
excepciones hacia arriba: devuelve un `ApiResult` y quien llama ramifica. Un `reject` sin
capturar en el camino del escaneo es una pantalla en blanco delante de una cola de gente.

Y `recordScan` manda siempre `Idempotency-Key: <scan_id>` (regla dura 8): el mismo
identificador en el envio original y en todos los reintentos.

## `fetchBranding` (RF-PD-08, tarea 5.8)

`GET /api/v1/branding` es la unica ruta de este cliente que va `authenticated: false` sin ser
parte del emparejamiento: es publica a proposito, porque la pantalla de espera necesita el
nombre y el logotipo del cliente antes de que nadie se identifique. Valida el cuerpo con
`parseBranding` de `@kronoqr/web-kit/branding` en vez de duplicar sus reglas aqui; quien la
consume (`shared/branding/useBranding.ts`) la trata siempre en segundo plano.
