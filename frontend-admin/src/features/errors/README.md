# errors

Historico de errores agrupado por huella (RF-PD-15, tarea 5.12). Ruta
`/errors`, entrada de navegacion «Errores».

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## Que hay aqui

| Fichero                | Que hace                                                                                                                                                |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `errors.api.ts`        | Cliente tipado de `GET /diagnostics/errors` y `POST /diagnostics/errors/{id}/resolve`, con los filtros en camelCase que usa el panel.                   |
| `errors.store.ts`      | Pinia: la pagina, los filtros, el reloj del servidor para la antiguedad de `last_seen_at`, y la sustitucion/retirada de fila al resolver.               |
| `errorPresentation.ts` | Presentacion pura (sin Vue): el color del badge de nivel, la clave de «que hacer» por origen y nivel, la antiguedad y los limites de cada periodo.      |
| `ErrorsView.vue`       | La pantalla: cabecera con recuentos abiertos, filtros (origen, nivel, estado, periodo), paginacion, estados vacio/carga/error y el dialogo de resolver. |
| `ErrorTable.vue`       | Las filas, con expansion por fila (clase, `file:line`, contexto, «que hacer») y el `trace_id` copiable.                                                 |

## Por que es un store de Pinia y no una consulta de TanStack Query

Mismo motivo que `features/incidents/incidents.store.ts`: la antiguedad de
`last_seen_at` se calcula contra el reloj del **servidor** (`meta.generated_at`),
no contra `Date.now()` del navegador (regla dura 3). Una consulta de TanStack
Query no tiene un sitio natural para llevar ese reloj extrapolado; un store si.

## Por que la tabla no esta virtualizada

El contrato limita `per_page` a 100 -el mismo techo que la bandeja de soporte y
la de exportaciones-, y cada fila puede expandirse a una altura variable
(contexto, bloque «que hacer»). Con un volumen tan acotado, una lista
virtualizada de altura estimada fija no aporta nada y complica la expansion:
se sigue el mismo criterio que `SupportView.vue` para su tabla de accesos, no
el de `IncidentTable.vue` (que virtualiza porque no tiene filas expandibles y
comparte codigo con paneles sin ese techo).

## El periodo «personalizado» es UTC explicito, nunca la zona del navegador

Los presets (7/30/90 dias) se cuentan hacia atras desde el reloj del
navegador: son un filtro de conveniencia sobre `last_seen_at`, no un dato que
se presente (regla dura 3 protege la presentacion del tiempo, no una consulta).
El periodo personalizado declara sus dos campos como **UTC** («Desde (UTC)»,
«Hasta (UTC)») en vez de adivinar la zona de quien mira: es una simplificacion
deliberada frente a resolver los limites en la zona del centro -que exigiria
conocer esa zona ANTES de poder pedir la primera pagina, un problema que no
tienen los informes de jornadas porque ahi el rango es de fechas civiles, no
de instantes-. Los instantes que se PINTAN (primera vez, ultima vez) si usan
`meta.time_zone`, como cualquier otra pantalla del panel.

## El boton de resolver no tiene una señal de servidor fiable al cien por cien

`ErrorsView.vue` oculta «Marcar como resuelto» cuando la sesion no lleva
`support:*` (`session.can(SUPPORT_MANAGE)`). Es una cortesia, no seguridad
(regla dura 18): la autorizacion real es `ErrorEventPolicy` en el servidor, que
rechaza a **cualquier** acceso de soporte con `403`, sea cual sea su alcance
(decision 8 de la ficha 5.12). La señal del cliente distingue bien dos de los
tres alcances de soporte:

- `diagnostics` y `read_only`: sus abilities NUNCA incluyen `support:*`, asi
  que el boton se oculta correctamente.
- `configuration`: actua como administrador (abilities `['*']`), que
  `hasAbility` reconoce para cualquier ambito -incluido `support:*`-. Es
  indistinguible de un administrador real desde el cliente. El boton se
  enseña, y si se pulsa, el `403` del servidor se traduce con `ErrorNotice`
  como cualquier otro rechazo: no es un error de la pantalla, es el limite de
  lo que la interfaz puede saber sin preguntarle al servidor.

## `context` se pinta tal cual, sin interpretar

A diferencia de `incidentContext.ts`, el contexto de un error es una lista
cerrada de claves tecnicas (`route`, `method`, `job`, `queue`...) pensada para
alguien que SI conoce el sistema una vez ha expandido la fila; el bloque «que
hacer» de al lado es el que traduce la situacion para quien no lo conoce. Por
eso `ErrorTable.vue` no reformatea ninguna clave del contexto: las pinta en
crudo, como la ultima aparicion las trajo.
