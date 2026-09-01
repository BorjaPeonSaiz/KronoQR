# ADR-023 — Frontera entre registro legal y funcionalidad accesoria

| Campo | Valor |
|---|---|
| **Estado** | **Aceptada — lista confirmada por el responsable de producto** |
| **Fecha** | 12 de agosto de 2026 (confirmada el 13 de agosto de 2026) |
| **Decide** | `producto-licencia` propone · confirmada por el responsable de producto como decisión de producto, no como aprobación comercial formal — una oferta comercial concreta puede ampliarla o restringirla, lo que requeriría un nuevo ADR |
| **Afecta a** | Tarea 5.3 |
| **Requisitos** | RF-PD-04, RF-PD-05 · ADR-018, ADR-019 |

## Contexto

ADR-019 y RF-PD-05 establecen que **la caducidad de la licencia nunca bloquea el registro ni su consulta**, y explican por qué en términos que no dejan margen: bloquear el fichaje dejaría al cliente incumpliendo la ley por una acción del fabricante, e impediría el acceso a datos que está obligado a conservar cuatro años. La palanca comercial son los avisos y «las funcionalidades accesorias».

Al desarrollar la tarea 5.3 apareció el hueco: **ningún documento dice qué es accesorio.** RF-PD-05 y ADR-019 enumeran con precisión lo que nunca se degrada; la otra mitad de la frontera no está escrita en ninguna parte. Sin esa lista no se puede implementar la degradación honesta, porque no hay nada que degradar.

Y hay un riesgo peor que no tener la lista: **que cada desarrollador la decida en el sitio**, con un `if (license.expired)` repartido por el código. Eso hace imposible responder a *«¿qué deja de funcionar exactamente?»* —una pregunta que un cliente va a hacer antes de firmar— y convierte cada nueva funcionalidad en una decisión implícita sobre si el registro legal es rehén del negocio.

## Decisión

**La frontera se declara en un solo sitio, en configuración, y es una lista cerrada.** Toda funcionalidad del producto pertenece a uno de dos conjuntos, y el conjunto se declara en el campo `features` de la licencia, no en condicionales repartidos.

### Nunca degradable — el registro legal

Sigue funcionando íntegramente con la licencia caducada, revocada o ausente:

| Funcionalidad | Por qué es intocable |
|---|---|
| Fichaje por QR y por PIN de respaldo | Es la obligación del art. 34.9 ET. Sin esto el cliente incumple |
| Sincronización de la cola offline del quiosco | Un fichaje encolado ya ocurrió: negarle la subida sería destruir un registro |
| Consulta de jornadas y tramos en el panel | El registro debe ser accesible (RL-03) |
| Portal del empleado | Exigencia legal explícita (RL-05, RF-ID-05) |
| Exportación legal para Inspección | RL-06. Negarla ante un requerimiento es inconcebible |
| Registro y consulta de auditoría | RL-04, valor probatorio |
| Correcciones trazadas de jornada | Sin ellas el registro no se puede mantener veraz |
| Copias de seguridad y restauración | Es la garantía de conservación de cuatro años |
| Sondas de salud y `error_events` | El cliente debe poder diagnosticar su propia instalación |

### Degradable — funcionalidad accesoria

Se desactiva con aviso explícito en la interfaz, indicando que se recupera al renovar:

| Funcionalidad | Qué ocurre al caducar |
|---|---|
| Informes avanzados y comparación entre periodos | Se desactivan. El registro y su exportación legal siguen disponibles |
| Cuadro de impacto y adopción (RF-IN-08) | Se desactiva. Es material de gestión, no registro |
| Exportación configurable para nómina (RF-IN-07) | Se desactiva. La salida legal no la sustituye ni la bloquea |
| Resumen semanal por correo (RF-PR-05) | Deja de enviarse |
| Presencia en tiempo real por WebSocket | **Degrada a sondeo**, no se apaga: el *fallback* del ADR-011 ya existe. La información sigue estando, con menos frescura |
| Marca blanca (RF-PD-08) | Vuelve a la marca por defecto del producto |
| Telemetría opcional (RF-PD-12) | Se desactiva. Ya viene desactivada de serie |

### Cómo se implementa

- El campo `features` de la tabla `license` enumera las funcionalidades **accesorias habilitadas**. Las del primer conjunto no aparecen en ese campo: **no son licenciables**, y por tanto no hay forma de expresar su desactivación.
- La comprobación es un único punto de decisión —un puerto tipado que responde «¿está habilitada esta función accesoria?»—, nunca un `if (license.expired)` en el código de negocio.
- **Que una funcionalidad no aparezca en ninguna de las dos listas es un error de diseño, no un caso por defecto.** Añadir funcionalidad obliga a clasificarla, y el valor por defecto de lo no clasificado es **no degradable**: ante la duda, el registro gana.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Degradar por módulos completos** | `Reporting` contiene a la vez la exportación legal para Inspección y los informes de gestión. Apagar el módulo apagaría RL-06 |
| **Solo mostrar avisos, sin degradar nada** | Deja al producto sin palanca comercial alguna, y ADR-019 sí contempla que exista |
| **Decidirlo funcionalidad por funcionalidad al implementar** | Es lo que este ADR evita. Produce condicionales dispersos y hace imposible responder qué deja de funcionar |

## Consecuencias

- **La lista es contractual, no técnica**, y sigue siéndolo tras confirmarse: lo que se degrada es lo que se le puede decir a un cliente que perderá. La confirmación del 13 de agosto de 2026 es una decisión de producto que permite a la tarea 5.3 dar la lista por buena para construir; no sustituye a una aprobación comercial formal si una oferta concreta necesita ampliarla o restringirla, lo que se haría con un ADR nuevo, no editando este.
- **El código separa dos conjuntos explícitos**, que es lo que ADR-019 anticipaba al advertir que exige «separar en el código lo que es registro legal de lo que es producto».
- **Toda funcionalidad nueva se clasifica al añadirse.** Entra en la Definición de Terminado de cualquier tarea que añada funcionalidad de la Fase 3 o posterior.
- **La degradación a sondeo del tiempo real es la única degradación parcial** y merece mención: apagarlo del todo se percibiría como una avería, no como una licencia caducada.

## Verificación

- Prueba que, con una licencia caducada, `POST /api/v1/scan` responde 200 y el tramo se registra.
- Prueba que, con una licencia caducada, la exportación legal para Inspección y el portal del empleado siguen respondiendo.
- Prueba que cada funcionalidad de la lista de accesorias responde con el aviso de licencia y no con un error genérico.
- Prueba de arquitectura: no existe ninguna comprobación de estado de licencia fuera del punto único de decisión.

## Enmienda 01-09-2026 (tarea 5.3): la lista, implementada

La lista no cambia. Se registra cómo quedó atada al código y dónde cae el borde
de una funcionalidad que estaba a caballo entre las dos columnas.

### El catálogo es un `enum` y lo manda este documento

`Shared\Domain\ValueObject\Feature` tiene **exactamente los siete casos** de la
tabla «Degradable», y `tests/Architecture/LicenseBoundaryTest.php` **lee este
fichero** y falla si alguien añade un caso que el ADR no lista o retira uno que
sí. La lista sigue siendo contractual antes que técnica: ampliarla o restringirla
exige un ADR nuevo, no un caso nuevo.

El conjunto legal no tiene caso, y esa es la implementación literal de «no existe
forma de expresar su desactivación»: el puerto solo acepta ese tipo, así que no
hay ninguna cadena que alguien pueda pasar para preguntar si el fichaje está
habilitado.

### Dónde cae el borde del informe por periodo

`GET /api/v1/reports/period` estaba a caballo: la tabla «Degradable» lo nombra
—«informes avanzados y comparación entre periodos»— y la tabla del registro legal
dice que la «consulta de jornadas y tramos» nunca se degrada, y ese informe
enseña horas trabajadas.

**Se resuelve como degradable, y la regla de desempate de este ADR es la que lo
decide del otro lado**: la consulta del registro que nunca se degrada es la de
`GET /api/v1/employees/{uuid}/workdays`, la de `GET /api/v1/me/workdays` y la
exportación de `GET /api/v1/reports/legal-export`, que son las tres vías por las
que el cliente cumple RL-03, RL-05 y RL-06. Ninguna de ellas se toca. El informe
por periodo es la **herramienta de gestión** que cruza esas horas con lo
contratado, y su ausencia no impide a nadie acceder a su registro ni atender un
requerimiento.

El texto del `402` lo dice: nombra la exportación para la Inspección, la consulta
de jornadas y el portal, para que quien se lo encuentre sepa por dónde sacar las
horas de este mes mientras se renueva. Sin esa frase, la degradación sería
técnicamente correcta y prácticamente inútil.

### La degradación parcial del tiempo real, comprobada

`GET /api/v1/attendance/live` **sigue devolviendo el listado completo** con la
licencia caducada: lo único que cambia es `meta.realtime.enabled`, que pasa a
`false` con un motivo y una fecha, y el panel sondea (ADR-011). Recortar el
listado sería degradar una vista de lectura sobre el registro.

El motivo solo viaja **cuando la causa es la licencia**. Si además falta la
configuración de Reverb, lo que hay que arreglar primero es eso, y anunciar
«licencia caducada» mandaría a quien lo lee a hablar con el comercial en lugar de
con quien administra el servidor.

### Lo que todavía no existe no se anuncia como pérdida

Cuatro de los siete casos —cuadro de impacto, exportación para nómina, resumen
semanal y marca blanca— llegan en tareas posteriores. El estado de licencia los
marca como no implementados y ni el panel ni `license:show` los presentan como
algo que el cliente acaba de perder: anunciar la pérdida de algo que nunca ha
visto es una llamada de soporte garantizada.
