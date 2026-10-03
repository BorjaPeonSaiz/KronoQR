# ADR-048 — El texto libre del histórico de errores pasa por una lista blanca de palabras

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. Diseño revisado por `seguridad-cumplimiento` el 3 de octubre de 2026 (aprobado con condiciones H1–H9, incorporadas); revisión de la implementación pendiente dentro del Bloque 19 |
| **Fecha** | 3 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (Bloque 19 de la 2.2.0, hallazgos PR12, R4-BE-02, R4-PL-01 y R6-PL-08) |
| **Afecta a** | Precisa [ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md) (cómo se cumple «anonimizado por defecto» en el histórico de errores; no cambia la decisión) · `ErrorMessageSanitizer`, `ErrorContextAllowlist`, `RecordErrorEvent`, `ErrorEventsCollector`, `RedactPersonalDataProcessor` · clases nuevas `ErrorVocabulary`, `ErrorTextAllowlist`, `ResanitizeErrorHistory` y `RedactingLogManager` · tabla `error_events` (migración de datos) · reglas duras 16 y 21 |
| **Requisitos** | RF-PD-15, RF-PD-09, RL-17, RL-19 |

## Contexto

El histórico de errores (`error_events`, RF-PD-15) viaja al fabricante dentro del paquete de diagnóstico
([ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md)). Es **la única sección del paquete que lleva
texto libre**: el mensaje de una excepción del servidor o de un error del navegador, y los valores de texto
del contexto (`component`, `scope`, `reason`, `hook`, `source`…). El resto de secciones son listas
cerradas de columnas o recuentos, y el paquete no lee ningún fichero de log.

Ese texto se saneaba con **patrones**: correos, DNI y NIE, teléfonos móviles, IBAN en mayúsculas, códigos de
empleado canónicos, fechas y horas, secretos, SQL interpolado y todo lo que fuera entre comillas. La
verificación de la 2.2.0 generó un paquete real tras sembrar datos y **8 de 27 valores llegaron al paquete**:

1. **Nombres y apellidos sin comillas**, tanto en el mensaje como en los valores de contexto, y por las dos
   puertas de entrada (`POST /api/v1/client-errors` y el latido del quiosco). El propio saneador lo admitía:
   «Ana Ruiz» no se distingue de «Cocina Central» con ninguna expresión regular.
2. IBAN en minúsculas o con guiones; códigos de empleado en minúsculas o heredados sin etiqueta; pasaportes
   extranjeros; NAF; teléfonos fijos, con prefijo `00` o extranjeros; correos con tildes; texto entre
   comillas tipográficas o comillas invertidas.

Las guías del cliente prometían «ningún dato personal, y una prueba automática lo garantiza», y esa prueba
no sembraba nombres en `error_events`.

Al revisar el código aparecieron cuatro hechos que descartan las salidas más obvias:

- **Las excepciones del producto meten valores en el mensaje.** 85 de los 123 ficheros de
  `Modules/*/{Domain,Application}/Exception` componen su mensaje con valores (UUID, fechas, estados y, en
  un caso, un código de empleado). Un catálogo de «excepciones con mensaje fijo» se quedaría en una docena
  de clases.
- **Un patrón de identificador técnico** (`^[a-z0-9_.:/-]{1,64}$`) admite un nombre en minúsculas: `ana.ruiz`
  o `anaruiz` lo cumplen.
- **El canal `emergency` no admite `tap`**: `LogManager::createEmergencyLogger()` construye su manejador a
  mano y no lee ni `tap` ni `processors`. Declararlo en `config/logging.php` no tendría ningún efecto.
- **Las filas guardadas por versiones anteriores contienen nombres**, y su `fingerprint` es un `sha256` sin sal
  de ese texto, que con la plantilla conocida se puede atacar por diccionario.

## Decisión

**En el texto libre que se guarda en `error_events`, una palabra solo se conserva si pertenece a un
vocabulario técnico cerrado del producto. Cualquier otra se sustituye por `…`.** Se aplica al escribir, en
`RecordErrorEvent`, que es el único camino de entrada a la tabla, y otra vez al empaquetar, en
`ErrorEventsCollector`. La función es idempotente.

1. **Palabras.** Cada secuencia de letras (`\p{L}+`) se parte por los cambios de mayúscula internos
   (`EmployeeCodeAlreadyTaken` → Employee|Code|Already|Taken, `getUserMedia` → get|User|Media) y se compara
   en minúsculas y sin tildes con `ErrorVocabulary`. **Si una parte no está en el vocabulario, se sustituye
   la secuencia entera**, de modo que `McDonald` da `…` y no `Mc…`. Una letra suelta pasa solo si es
   **latina** (`x`, `e`): en chino una letra es una palabra entera. Varios `…` seguidos se funden en uno,
   para que no se sepa cuántas palabras tenía el nombre.
2. **Cifras.** Ninguna lista de palabras puede filtrar un identificador numérico, así que los patrones se
   mantienen y se amplían: IBAN en mayúsculas o minúsculas, compacto, con espacios o con guiones, siempre
   que sume **10 cifras o más** y cada grupo tras el primero lleve alguna cifra (así no se come la palabra
   que sigue); NAF; pasaportes con etiqueta o sin ella; teléfonos en todas sus formas; código de empleado en
   minúsculas o detrás de su etiqueta completa, y detrás de la **etiqueta corta** («código», «code») solo si
   el valor tiene forma de código —letra y cifra, o 4 cifras o más—, de modo que `status code 500` (axios) y
   `exit code 137` conservan el número; correo Unicode; todas las variantes de comillas; horas también
   pegadas a una `T`, con `am`/`pm` o como `22.30` detrás de «a las» o «at»; IP v4 y v6 como `[ip]`. Por
   encima de ellos actúan tres reglas generales:
   - **toda serie de grupos de cifras que sume 7 cifras o más** pasa a `[n]`;
   - **toda secuencia alfanumérica con 4 cifras seguidas o más** pasa entera a `[n]`;
   - **toda secuencia alfanumérica de 5 caracteres o más con 2 cifras o más y alguna letra** (H4: `a1b2c3`,
     `x7k2m9`) pasa a `[n]`, salvo que esté entera en el vocabulario (`sha256`, `base64`, `utf8mb4`).

   La excepción son las **posiciones técnicas** (H3), cada una con su prueba: detrás de `line`, `línea`,
   `.php:`, `.js:`, `.ts:` o `.vue:` (y la columna de `.js:línea:columna`) se conserva **un único grupo de
   hasta 6 cifras**; detrás de un `#` (`Argument #1`, `#12` de una traza), **hasta 2**, porque
   `Empleado #739104` es un código. Un grupo más largo, o seguido de otro grupo, sigue las reglas generales.
   Los límites de cada patrón tratan como «pegado» lo mismo una letra o una cifra que un marcador (`[`, `]`,
   `…`): con `\b`, un dato pegado a una letra no casaba en la primera pasada y sí en la segunda, y el saneado
   dejaba de ser idempotente. Una prueba de propiedad con fragmentos pegados lo vigila.
3. **Identificadores que se conservan.** Los UUID (en cualquier caja) y los hexadecimales **en minúsculas**
   de exactamente 16, 32, 40 o 64 caracteres **con al menos una letra y una cifra** (H1: span, traza,
   commit, sha256) se apartan antes de aplicar los patrones y se restauran al final; también `SQLSTATE[…]`,
   que puede llevar letras (`23P01`). Es la forma en que una persona y un dispositivo aparecen en el
   histórico (`employee_uuid`, `device_id`), y la traza que permite correlacionar. Se fijan esas
   longitudes, y no «8 o más», porque con «8 o más» quedaría protegido un IBAN alemán en minúsculas; se
   exige letra porque un número de tarjeta de 16 cifras también es «hexadecimal»; se exigen minúsculas
   porque un código de empleado heredado (mayúsculas y cifras) podría tener esa forma; y no se protege lo
   que tiene forma de IBAN (dos letras y solo cifras: un IBAN belga compacto en minúsculas son 16
   caracteres hexadecimales).
4. **Contexto.** Las claves siguen siendo la lista cerrada de `ErrorContextAllowlist` y los valores anidados
   se siguen descartando. Los valores de texto pasan por la misma lista blanca, y el vocabulario hace de
   catálogo para todos: no hay un catálogo distinto para cada clave. `source` se reduce en el servidor a
   `pathname:línea`, quitando el origen, la consulta y el fragmento. Los valores numéricos y booleanos pasan
   por su tipo.
5. **Columnas.** También pasan por la lista `app_version` y, cuando no tiene forma de ruta del proyecto,
   `file`. Del `code` del servidor solo se conserva el que tiene forma de `SQLSTATE` o de número corto; si
   no, se guarda `null`. `exception_class` se conserva si es un nombre de clase cualificado; si no (por
   ejemplo una `class@anonymous` con su ruta), pasa por la lista.
6. **Marcador, no hash.** La agrupación ya se hace en la instalación, y la huella se calcula sobre el texto
   **ya filtrado**: el mismo fallo de dos personas distintas es un solo grupo con `occurrences: 2`. Un hash
   del texto desconocido no aportaría al fabricante ninguna agrupación que no le den ya `fingerprint` y
   `occurrences`, y un hash con sal de un nombre es un seudónimo (art. 4.5 RGPD).
7. **El vocabulario** es una constante literal del dominio (`ErrorVocabulary`): ordenada, en minúsculas,
   sin tildes y de dos letras como mínimo. Se siembra con las palabras de los mensajes del propio producto,
   de los errores habituales de PHP, PostgreSQL, Redis, Laravel y los navegadores, y de los valores que
   envían los clientes. Dos pruebas lo vigilan:
   - una exige que **no comparta ninguna palabra** con un conjunto de datos de nombres y apellidos
     frecuentes (INE y nacionalidades habituales en hostelería), sin excepciones;
   - otra exige que contenga todas las palabras que el producto emite en sus propios mensajes y contextos,
     para que no se pierda diagnóstico sin que nadie lo note.
8. **Log técnico** (regla dura 21; no forma parte del paquete). `RedactPersonalDataProcessor` aplica la
   lista blanca al mensaje de cada línea, al de cada `Throwable` de la cadena y a las claves de los mapas
   anidados. Los valores del contexto los escribe el código del producto y siguen pasando solo por los
   patrones. Las claves de correlación (`employee_uuid`, `device_id`, `scan_id`, `trace_id`,
   `traceparent`) solo se dejan sin tocar si tienen su forma. El canal `emergency` recibe el mismo
   processor mediante `RedactingLogManager`, que sobrescribe `createEmergencyLogger()`.
9. **Filas anteriores.** Una migración de datos invoca `ResanitizeErrorHistory`, que vuelve a filtrar
   `message` y `context`, recalcula `fingerprint` y **fusiona** los grupos que pasan a coincidir: suma
   `occurrences`, toma el primer `first_seen_at` y el último `last_seen_at`, y deja el grupo abierto si
   alguna de las filas lo estaba. Su `down()` no hace nada: un saneado no se deshace. Es una excepción
   justificada a la exigencia de que las migraciones sean reversibles.
10. **El contrato no cambia.** `/client-errors` y el latido aceptan exactamente lo mismo que antes, y las PWA
    anteriores siguen funcionando.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Ampliar los patrones** | Es una lista negra: no atrapa un nombre sin comillas. Es el origen de PR12 |
| **Patrón de identificador técnico** (`^[a-z0-9_.:/-]{1,64}$`) para los valores de contexto | Admite un nombre en minúsculas (`ana.ruiz`, `anaruiz`). Una forma no distingue una palabra técnica de un nombre |
| **Catálogo de excepciones con mensaje fijo; el resto, clase + hash** | El 70 % de las excepciones del producto meten valores en el mensaje, y un `TypeError` o un `QueryException` perderían justo lo que los hace diagnosticables. Va contra la consecuencia de ADR-020 de que «los errores tienen que ser autoexplicativos» |
| **Hash truncado con sal del texto desconocido** | Sería un seudónimo: permitiría saber que «es la misma persona en tres errores», que es más información, no menos. Y no aporta agrupación, porque ya se agrupa en la instalación |
| **Quitar todo el texto libre (solo código y clase)** | Un `web.vue_error` sin mensaje no se puede diagnosticar, y todos los errores de Vue de una instalación volverían a juntarse en una sola fila (el defecto que corrigió la ficha 5.12) |
| **Filtrar solo al empaquetar** | La regla dura 21 protege también la tabla, que se ve desde el panel. Se filtra al escribir y, como red de seguridad, también al leer |
| **Lista negra de nombres** (diccionario del INE) | La lista de nombres posibles no tiene fin, sobre todo en una plantilla de muchas nacionalidades. Solo se usa en la prueba que vigila el vocabulario |
| **Reconocimiento de entidades (NER) o un modelo** | Es una dependencia pesada, no determinista y probabilística: no admite prueba de mutación y metería infraestructura en el dominio |
| **Declarar `tap` en el canal `emergency`** | Laravel no lo lee en ese canal (`createEmergencyLogger()`). Sería una configuración que aparenta proteger y no protege |

## Consecuencias

- **La garantía se puede enunciar con exactitud.** En el histórico de errores solo quedan palabras del
  vocabulario, marcadores (`…`, `[n]`, `[email]`, `[iban]`, `[id]`, `[code]`, `[phone]`, `[time]`, `[ip]`,
  `[secret]`, `'…'`) e identificadores técnicos (UUID, trazas y huellas). Las guías del cliente dicen eso y
  nada más (RL-19).
- **Riesgos que se aceptan, declarados en el doc 07:**
  - un nombre que coincida con una palabra del vocabulario y aparezca sin apellido puede pasar; la prueba
    de disjunción lo hace improbable para los nombres frecuentes;
  - un entero bajo una clave numérica admitida (`line`, `entries`…) no se inspecciona, porque es un
    recuento.
- **Se pierde diagnóstico**, y se acepta:
  - las palabras de librerías de terceros que no estén en el vocabulario; se recuperan añadiéndolas con
    un PR revisado;
  - los números de 4 cifras o más fuera de una posición técnica, como un puerto;
  - las direcciones IP.
- **La agrupación mejora**: los mensajes que solo se distinguían por el nombre de una persona forman un solo
  grupo.
- **Hay que mantener un vocabulario**, y lo vigilan dos pruebas: una de privacidad (la disjunción) y otra de
  diagnóstico (la cobertura).
- **Al actualizar se vuelven a sanear las filas antiguas** y se recalculan sus huellas, con una migración de
  datos irreversible por diseño.
- **`employee_uuid` no viaja en el paquete anonimizado, ni ningún UUID dentro del texto.** Es un seudónimo
  (art. 4.5 RGPD) cuya correspondencia conoce la instalación, y el responsable valora la identificabilidad
  desde su propia posición (TJUE, C-413/23 P): con el seudónimo dentro, el producto no podría afirmar que
  no se comunican datos personales. El colector lo omite y sustituye los UUID del texto por `[uuid]`;
  con `--with-personal-data` se conserva todo; en `error_events`, en local, la regla dura 21 sigue usando
  `employee_uuid`. Se quedan `device_id` (una tablet compartida) y `trace_id`, que no corresponden a una
  persona para quien recibe el paquete. Dictamen de `seguridad-cumplimiento` del 3 de octubre de 2026;
  la calificación jurídica final es de la asesoría y el DPO del cliente.

## Verificación

- **Prueba de volumen sobre un paquete real.** Se siembran nombres y apellidos (con mayúscula inicial, en
  mayúsculas, en minúsculas y como «Apellido, Nombre»), correos (ASCII y con tildes), DNI, NIE y pasaporte
  (español y extranjero), NAF, IBAN (de varios países, en mayúsculas y minúsculas, compacto, con espacios y
  con guiones), teléfonos en todas sus formas y códigos de empleado en todas sus formas. Se meten por
  `POST /client-errors` (con sesión de gestión y de portal), por el latido y por una excepción del
  servidor, y en el mensaje, en las claves, en los valores de cada clave de texto admitida y en valores
  anidados. **Ninguno aparece** en el paquete, en `error_events` ni en `GET /error-events`. Un control
  positivo comprueba que los grupos sembrados sí están y que un mensaje técnico sale legible.
- **Pruebas unitarias.** Una por cada forma de cada patrón; los identificadores técnicos que se conservan
  salen intactos; idempotencia; fallo cerrado; vocabulario disjunto de los nombres y apellidos frecuentes;
  mutación ≥ 80 % en las clases del dominio afectadas.
- **Arquitectura.** El vocabulario cubre las palabras que emiten los mensajes de las excepciones del
  producto, los identificadores de evento de log y los valores de contexto de los clientes.
- **Integración.** Con el canal por defecto roto, una línea con datos personales ficticios sale saneada por
  el canal `emergency`. La migración de datos deja las filas antiguas sin nombres y con huellas únicas.
