# Runbook — vigilancia normativa del registro horario

Este runbook **no dice qué dice la ley**: dice quién la mira, cada cuánto, y por
dónde entra un cambio normativo en el producto. No es asesoramiento jurídico; la
lectura de la norma y su aplicación al centro son de la asesoría laboral y del
DPD del cliente. Viaja en el paquete del cliente y lo ejecuta el cliente.

**Por qué existe.** El registro horario obligatorio (art. 34.9 ET) y la
protección de datos de la plantilla cambian por convenio, por criterio de la
autoridad de control y por sentencia, no por versión del producto. El sistema
traduce esa norma a números —jornada máxima, descanso mínimo, pausas, años de
conservación— en el **perfil de cumplimiento**, y alguien tiene que saber cuándo
esos números han dejado de ser los correctos. Sin ese alguien, el riesgo R3 del
doc 01 («la norma cambia y el sistema no») está sin mitigar.

---

## 1. Quién

**Una persona con nombre**, designada por el cliente —normalmente quien firma el
registro de actividades de tratamiento ([`../cliente/obligaciones-legales.md`](../cliente/obligaciones-legales.md)
§2)—, con la asesoría laboral como apoyo. No es un rol del producto ni una
cuenta del panel: es una responsabilidad de la organización.

Se escribe aquí, en la copia del cliente, y se mantiene al día:

| Campo | Valor |
| --- | --- |
| Responsable de la vigilancia normativa | *(nombre y cargo)* |
| Fecha de designación | *(AAAA-MM-DD)* |
| Asesoría laboral de apoyo | *(despacho o persona de contacto)* |
| Sustituto en ausencia | *(nombre)* |

Un responsable sin nombre es un repaso que no se hace.

---

## 2. Cada cuánto

- **Repaso ordinario semestral**, con fecha fija en el calendario (por ejemplo,
  la primera semana de enero y la primera de julio). Se hace aunque «no haya
  pasado nada»: la conclusión «sin cambios» también se escribe (§5).
- **Repaso extraordinario** ante cualquiera de estos tres disparadores:
  1. **Convenio colectivo nuevo o revisado** en el sector o en la empresa.
  2. **Resolución, guía o criterio de la AEPD** sobre registro de jornada, sobre
     tratamientos de control laboral o sobre detección de patrones.
  3. **Cualquier cambio en el centro que amplíe el tratamiento**: cámaras o
     videovigilancia nuevas, control de accesos por tarjeta, geolocalización de
     flotas, un segundo edificio, un tratamiento que cruce datos con el
     registro horario.

La **revisión anual de seguridad** del producto (RS-11,
`docs/seguridad/paquete-revisor.md` del repositorio del fabricante, que no viaja en el paquete) es una
cita distinta, del fabricante, y **no sustituye** a esta: aquella mira el
código; esta mira la norma.

---

## 3. Qué se mira, con nombre

| | Fuente | Qué se busca | Dónde impacta |
| --- | --- | --- | --- |
| (a) | **Art. 34.9 ET** y su desarrollo reglamentario o interpretativo (criterios de la Inspección de Trabajo, guías del Ministerio) | Qué debe contener el registro, cómo se conserva, cómo se pone a disposición | Formato de la exportación para la Inspección; años de conservación del perfil |
| (b) | **El convenio colectivo aplicable** al centro | Jornada máxima diaria y semanal, descanso mínimo entre jornadas, pausas y su cómputo, cómputo anual | Es literalmente lo que el perfil de cumplimiento traduce a números ([`../cliente/configuracion.md`](../cliente/configuracion.md) §2.4) |
| (c) | **Criterios y guías de la AEPD** sobre registro de jornada y sobre tratamientos de control y detección de patrones en el ámbito laboral | Base jurídica, información previa, proporcionalidad de la detección de patrones de uso de credencial, plazos de conservación | EIPD y registro de actividades (`obligaciones-legales.md` §2); ajustes de detección de patrones (`configuracion.md` §2.1) |
| (d) | **Jurisprudencia** sobre el valor probatorio del registro y sobre la carga de la prueba en reclamaciones de horas | Qué hace defendible un registro: integridad, trazabilidad de correcciones, sello de tiempo | Nada que configurar: si la doctrina exige algo que el registro no acredita, es petición al fabricante (§4) |
| (e) | **Art. 20.3 ET y arts. 87 y 91 LOPDGDD** | Facultad de vigilancia del empleador, información previa a la plantilla y a su representación legal | La comunicación a la plantilla (`obligaciones-legales.md` §3) antes de activar o endurecer cualquier control |

No hace falta leerlo todo cada seis meses: hace falta saber si **ha cambiado**
algo de esa lista desde el último repaso, y quién lo confirma.

---

## 4. Qué se hace al detectar un cambio

**Se cambia el perfil de cumplimiento, no el código** (ADR-017, regla dura 14
del proyecto). Umbrales, pausas y años de conservación son filas editables en
Panel → **Perfil de cumplimiento**; el cambio queda auditado con autor, fecha,
valor anterior y nuevo, y **aplica desde ese momento** sin tocar ninguna versión
del producto. Rige hacia delante: no reescribe incidencias ni jornadas pasadas
(`configuracion.md` §2.4, «Cambiar un umbral rige desde el cambio, no hacia
atrás»).

Tres casos, y en cada uno una acción distinta:

1. **El cambio cabe en el perfil** (un descanso mínimo distinto, una jornada
   máxima nueva, otro plazo de conservación): se edita el perfil, se anota el
   motivo con la referencia normativa exacta y se archiva el repaso (§5).
   **Nunca bajar `retention_years` sin que lo diga la asesoría por escrito**:
   por debajo del plazo legal se destruye prueba (`obligaciones-legales.md`
   §4).
2. **El cambio no cabe en el perfil** —un dato nuevo que hubiera que registrar,
   un formato de exportación distinto, una regla de cálculo que el perfil no
   expresa—: **es petición al fabricante, no una edición local.** Se abre con
   la referencia normativa concreta (artículo, fecha, órgano) y con lo que el
   producto tendría que hacer distinto. Nada específico de un cliente vive en
   el código, así que la petición beneficia a todas las instalaciones.
3. **El cambio afecta a lo que se informa a la plantilla** (un control nuevo,
   una detección más estricta, una finalidad distinta): **se rehace la
   información previa** a las personas trabajadoras y a su representación legal
   **antes** de aplicar nada (`obligaciones-legales.md` §3). Endurecer la
   detección de patrones bajando `ATTENDANCE_PATTERN_MIN_REPEATS` o subiendo
   `ATTENDANCE_PATTERN_WINDOW_SECONDS` es de este caso, no del primero.

Si el repaso concluye que **un riesgo aceptado ya no lo es** —por ejemplo, la
asesoría fija por fin el plazo de conservación de las ausencias—, la respuesta
va a quien mantiene el producto para que la fila correspondiente del doc 07 §6
se cierre con la fecha y la evidencia.

---

## 5. Cómo se deja constancia

**Media hoja fechada por repaso**, ni más ni menos:

- Fecha del repaso y si fue ordinario o extraordinario (y, en ese caso, qué lo
  disparó).
- Qué se revisó, por letra del §3, y qué fuentes concretas se consultaron
  (con fecha de publicación).
- Conclusión: **«sin cambios» también vale, y hay que escribirlo.**
- Qué se hizo: fila del perfil cambiada (con la referencia normativa), petición
  abierta al fabricante (con su número) o información previa rehecha (con
  fecha de comunicación).
- Quién firma.

Se archiva **junto a la EIPD y al registro de actividades**
(`obligaciones-legales.md` §2 y §3), que es donde una inspección o una
auditoría irán a buscarlo. Un repaso sin constancia es indistinguible de un
repaso que no se hizo.

---

## 6. Enlaces

- [`../cliente/obligaciones-legales.md`](../cliente/obligaciones-legales.md) §4
  (conservación por tipo de dato) y §7 (el perfil de cumplimiento es del
  cliente).
- [`../cliente/preguntas-asesoria.md`](../cliente/preguntas-asesoria.md): las
  ocho preguntas cerradas que hay que llevarle a la asesoría, con qué se hace
  con cada respuesta. Es el punto de partida del primer repaso.
- [`../cliente/configuracion.md`](../cliente/configuracion.md) §2.1 (ajustes de
  detección de patrones) y §2.4 (perfil de cumplimiento).
- [`solicitud-derechos-rgpd.md`](solicitud-derechos-rgpd.md) y
  [`requerimiento-inspeccion.md`](requerimiento-inspeccion.md): los dos
  procedimientos que más dependen de que la norma vigilada sea la correcta.
- `docs/07-seguridad-madurez-y-amenazas.md` §6, filas **A-17** (plazo de
  conservación de las ausencias) y **A-18** (uso del indicio de patrones): las
  dos que esperan respuesta de la asesoría. Documento interno del fabricante;
  no viaja en el paquete.
