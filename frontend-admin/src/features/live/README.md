# live

Presencia en vivo con Reverb y respaldo por sondeo (RF-PA-01, RF-PA-02, ADR-011). Tarea 2.4.

| Fichero                    | Qué es                                                                                                                            |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `live.api.ts`              | `GET /attendance/live` con los filtros del contrato y la firma de suscripción (`auth_endpoint`)                                   |
| `realtime/pusherClient.ts` | Cliente mínimo del protocolo Pusher 7 (el de Reverb): saludo, firma de canales privados, latidos, reconexión con espera creciente |
| `presence.store.ts`        | Foto + `presence.updated` aplicados fila a fila; sondeo cada `poll_interval_seconds` cuando el canal no está; reloj del servidor  |
| `LivePresenceView.vue`     | Filtros, recuentos, indicador de vía (tiempo real / sondeo / desactivado) y estados vacío/carga/error                             |
| `PresenceTable.vue`        | Lista, virtualizada a partir de 80 filas; horas en la zona del centro; tiempo transcurrido contra `meta.generated_at`             |

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## Decisiones

- **Sin `laravel-echo` ni `pusher-js`.** Lo que hace falta cabe en `pusherClient.ts` y así el E2E simula el
  servidor con `page.routeWebSocket` sin depender de los reintentos internos de una librería.
- **Los detalles de conexión llegan en `meta.realtime`**, nunca compilados (ADR-017, regla dura 13).
  Host y puerto son los del propio origen del panel.
- **Degradación anunciada** (RNF-D-03): el indicador `data-test="transport"` lleva `role="status"` y
  `data-degraded`; el sondeo empieza en cuanto el canal no está `live` y para al recuperarlo, con una
  foto nueva para recuperar lo perdido.
- **Reloj del servidor**: el tiempo transcurrido se extrapola desde `meta.generated_at` con el reloj
  local sólo como delta; un navegador con la hora mal no inventa minutos (regla dura 3).
