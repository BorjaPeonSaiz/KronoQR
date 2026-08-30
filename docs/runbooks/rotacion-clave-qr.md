# Runbook — rotación de la clave de firma del QR

**Reimpresión progresiva sin dejar a nadie sin fichar** (doc 02 §5.3, RF-QR-07).

Esto **no es un incidente**: es un procedimiento programado que dura semanas.
Se ejecuta cuando toca por calendario (§7.7) o cuando hay sospecha de
compromiso de la clave — en ese segundo caso, lee antes la
[§7](#7-si-la-clave-esta-comprometida-no-hay-solape-que-valga).

**Impacto en el fichaje, que es lo primero que hay que saber:** **ninguno**,
si se sigue este orden. Durante el solape conviven dos claves y las tarjetas
antiguas siguen fichando exactamente igual. El único paso que puede dejar a
alguien sin fichar es retirar la clave antes de tiempo, y por eso el comando de
retirada **se niega** mientras quede una sola tarjeta viva firmada con ella.

---

## 1. Qué hay montado, en 30 segundos

| Pieza | Qué hace | Dónde |
| --- | --- | --- |
| `QR_SIGNING_KEY_CURRENT_ID` / `QR_SIGNING_KEY_CURRENT` | La clave con la que se firma todo lo que se imprime **hoy** | `.env` del servidor / gestor de secretos |
| `QR_SIGNING_KEY_PREVIOUS_ID` / `QR_SIGNING_KEY_PREVIOUS` | La clave **saliente**: ya no firma, pero sigue verificando | Igual. Vacías fuera de una rotación |
| `credentials.key_id` | Con qué clave se firmó cada tarjeta. Se acuña **al imprimir** (ADR-034) | PostgreSQL |
| `credentials:rotate-key` | Abre la rotación: reemite una tarjeta nueva por cada una firmada con la saliente | Contenedor `app` |
| `credentials:status --key-id=` | **Quién sigue fichando con la clave vieja** | Contenedor `app` |
| `credentials:retire-key` | Certifica que el solape se puede cerrar. Se niega si queda alguien | Contenedor `app` |
| `credentials_pending_reprint{site,key_id}` | El avance, como métrica. Llega a cero cuando se puede retirar | `BACKUP_PATH/metrics/kronoqr_credentials.prom` |

**El payload impreso es `FH1.<key_id>.<token>.<sig>`** (ADR-005). El `key_id`
va en la propia tarjeta: es lo que permite que el servidor sepa con cuál de las
dos claves tiene que verificar. **Rotar no cambia el prefijo `FH1`**: cambiarlo
invalidaría de golpe todo lo impreso.

**Cinco cosas que hay que saber antes de empezar:**

1. **La aplicación no genera claves y no las conoce hasta que las lees del
   entorno.** Las genera quien opera el servidor (regla dura 13, §7.7). Ningún
   comando de KronoQR imprime, pide ni almacena material de clave.
2. **Reemitir no invalida nada.** La reemisión nace **pendiente de imprimir** y
   una credencial pendiente no es escaneable: no tiene token ni hash (ADR-034).
   La tarjeta que la persona lleva encima sigue funcionando.
3. **La tarjeta vieja se revoca sola al entregar la nueva**, no al imprimirla.
   Entre la impresión y la entrega pueden pasar días, y durante esos días la
   persona sigue fichando con la vieja.
4. **No hay botón en el panel, y no lo habrá.** Rotar es un acto operativo con
   semanas de logística detrás; el panel solo **lee** el avance.
5. **`credentials:rotate-key` es idempotente.** Ejecutarlo dos veces no crea dos
   tarjetas para nadie: lo impide el índice
   `one_pending_credential_per_employee`.

Todos los comandos se ejecutan dentro del contenedor:

```bash
docker compose --env-file .env -f infra/compose.dev.yaml exec -T app <comando>
```

---

## 2. Antes de empezar: comprobar que no hay otra rotación abierta

```bash
php artisan credentials:status --no-metrics
```

Si la última línea dice `Rotacion en curso: N de M siguen fichando con la clave
X` con `N > 0`, **hay una rotación sin cerrar**. Ciérrala primero (§5): abrir
otra encima expulsaría del llavero a la clave `X` y dejaría a esas `N` personas
sin poder fichar de un día para otro.

`credentials:rotate-key` también se niega por su cuenta si encuentra tarjetas
activas firmadas con una clave que ya no está configurada. Ese mensaje significa
que hay gente **que no puede fichar ahora mismo**: ve a la
[§6](#6-si-alguien-ya-no-puede-fichar).

---

## 3. Paso 1 — poner las dos claves en el servidor

Genera 32 bytes al azar **en el servidor del cliente** y no los transmitas por
ningún canal:

```bash
openssl rand -base64 32
```

Edita el `.env` (o el gestor de secretos) dejando **la clave que había** como
saliente y la nueva como actual, con un `key_id` distinto de dos caracteres:

```
# Antes
QR_SIGNING_KEY_CURRENT_ID=a3
QR_SIGNING_KEY_CURRENT=<la de siempre>
QR_SIGNING_KEY_PREVIOUS_ID=
QR_SIGNING_KEY_PREVIOUS=

# Después
QR_SIGNING_KEY_CURRENT_ID=a4
QR_SIGNING_KEY_CURRENT=<los 32 bytes nuevos>
QR_SIGNING_KEY_PREVIOUS_ID=a3
QR_SIGNING_KEY_PREVIOUS=<la de siempre>
```

**El `key_id` nuevo no puede repetir uno usado antes** ni coincidir con el
saliente: es lo que distingue una tarjeta vieja de una nueva.

Reinicia la aplicación y comprueba que se sigue fichando con normalidad **antes
de tocar ninguna tarjeta**. Si el `.env` está mal, lo notarás aquí y no hay
nada que deshacer.

---

## 4. Paso 2 — abrir la rotación y reimprimir

### 4.1 En seco primero

```bash
php artisan credentials:rotate-key --dry-run
```

Salida esperada:

```
Clave saliente: a3 · clave actual: a4
Tarjetas activas firmadas con a3: 60
En seco: se reemitirian 60 credenciales.
```

No escribe nada. Si el número no cuadra con la plantilla, **para** y averigua
por qué antes de seguir.

### 4.2 La rotación de verdad

```bash
php artisan credentials:rotate-key
```

Deja 60 credenciales **pendientes de imprimir** y un asiento
`signing_key.rotated` en `audit_log`, más un `credential.reissued` por persona.
Ninguna tarjeta vigente se ha tocado.

### 4.3 Imprimir, entregar, repetir

Se reparte en tandas, al ritmo que se pueda:

```bash
php artisan credentials:print-batch --pending --out=/tmp/credenciales.pdf
php artisan credentials:deliver <uuid> --by=persona@ejemplo.es
```

- `print-batch --pending` es **idempotente**: la segunda pasada no encuentra
  nada y no imprime duplicados.
- **La entrega es la que cierra el relevo**: al registrarla, la tarjeta antigua
  de esa persona se revoca automáticamente con el motivo *«Sustituida por la
  rotación de la clave de firma a3»*, y queda su `credential.revoked` en
  `audit_log`. A partir de ese momento la vieja ya no ficha, así que **recógela
  y destrúyela** al entregar la nueva.
- Si alguien pierde la tarjeta a mitad de la rotación, no uses «reemitir»: su
  reemisión ya existe. Revoca la perdida (`credentials:revoke`) e imprime la
  pendiente (`credentials:print <empleado>`).

### 4.4 Ver cuánto falta

Por consola:

```bash
php artisan credentials:status --key-id=a3
```

Lista **quién sigue fichando con la clave vieja**, con nombre y departamento
—esa lectura deja asiento de divulgación (RS-05), como cualquier otra del
panel—. En el panel de gestión, la sección *«Rotación de la clave de firma en
curso»* de la pantalla de Credenciales dice lo mismo con una barra de avance y
un botón para ver quién falta.

Y como métrica, para el panel de Grafana:

```bash
grep credentials_pending_reprint "$BACKUP_PATH/metrics/kronoqr_credentials.prom"
```

**Vigila también `pin_fallback_scans_total{site}`.** Si sube durante la
rotación, alguien está fichando con PIN porque su tarjeta no le funciona: la
reimpresión va por detrás y hay que revisar qué se ha retirado antes de tiempo.

---

## 5. Paso 3 — cerrar el solape

Cuando `credentials:status --key-id=a3` no devuelva a nadie:

```bash
php artisan credentials:retire-key a3
```

- Si **queda alguien**, el comando se niega y dice cuántas tarjetas y en qué
  centro. No insistas: retirar la clave dejaría a esas personas delante del
  quiosco con un rechazo genérico que —correctamente, por RS-03— no les explica
  nada.
- Si **no queda nadie**, escribe el asiento `signing_key.retired` y te indica el
  último paso.

Ese último paso es manual y es tuyo:

```
QR_SIGNING_KEY_PREVIOUS_ID=
QR_SIGNING_KEY_PREVIOUS=
```

Reinicia la aplicación, comprueba un fichaje real y **destruye la copia de la
clave retirada** del gestor de secretos. Desde ese momento, cualquier tarjeta
que quedara por ahí con `key_id=a3` deja de verificar.

---

## 6. Si alguien ya no puede fichar

Síntoma: una persona escanea y el quiosco rechaza sin más explicación (RS-03,
y es correcto que no la dé). En el log del servidor aparece
`credential_rejected` con `result: rejected_signature`.

```bash
php artisan credentials:status --no-metrics | head -20
```

**Diagnóstico rápido:**

| Lo que ves | Qué pasó | Qué hacer |
| --- | --- | --- |
| El comando `rotate-key` se niega con «credencial(es) activa(s) firmada(s) con la clave X, que ya no está configurada» | Se retiró una clave con tarjetas vivas | Vuelve a poner esa clave en `QR_SIGNING_KEY_PREVIOUS_*`, reinicia y termina la reimpresión antes de retirarla |
| Su fila dice «Pendiente de imprimir» | Su tarjeta nueva no ha salido de la impresora | `credentials:print <empleado>` y entrégala |
| Su fila dice «Revocada» | Se revocó por error, o se entregó la nueva y no se la dieron | Reemitir e imprimir: son tres actos y los tres quedan en `audit_log` |

**Mientras se resuelve, esa persona ficha con PIN** (RF-AT-11): el quiosco nunca
la deja fuera (regla dura 19). El fichaje por PIN queda marcado como tal y
genera su incidencia para revisión humana.

---

## 7. Si la clave está comprometida no hay solape que valga

El procedimiento de arriba prioriza que nadie se quede sin fichar. **Si hay
sospecha fundada de que la clave se ha filtrado**, la prioridad cambia: con la
clave, cualquiera puede fabricar una tarjeta válida a nombre de quien quiera
(RS-01).

1. Trata el caso como incidente de seguridad y preserva evidencia:
   [`rotura-cadena-auditoria.md` §2](rotura-cadena-auditoria.md).
2. Haz los pasos 1 y 2 de este runbook **el mismo día**.
3. **Retira la clave saliente sin esperar** a que todo el mundo tenga la nueva:
   deja el `.env` sin `PREVIOUS` en cuanto la reimpresión esté lanzada. Habrá
   gente que no pueda fichar con tarjeta durante unas horas; ficharán con PIN,
   que es exactamente para lo que existe el respaldo.
4. Revisa `scan_events` del periodo sospechoso buscando escaneos aceptados que
   no cuadren con la presencia real (doc 01 §11, «QR falsificado»).

`credentials:retire-key` se negará mientras queden tarjetas vivas: en este
escenario, y **solo** en este, revócalas primero de forma explícita con
`credentials:revoke`, que deja su asiento por cada una.

---

## 8. Qué queda escrito

Toda la rotación es auditable sin depender de la memoria de nadie:

| Asiento | Cuándo | Qué lleva |
| --- | --- | --- |
| `signing_key.rotated` | Al abrir la rotación | Los dos `key_id` y los recuentos |
| `credential.reissued` | Una por persona | `credential_uuid`, `employee_uuid` |
| `credential.printed` | Al imprimir | `key_id` con el que se acuñó |
| `credential.delivered` | Al entregar | Quién responde de la entrega |
| `credential.revoked` | Al entregar la nueva | El motivo, con el `key_id` sustituido |
| `signing_key.retired` | Al cerrar el solape | `key_id` y cuántas tarjetas firmó en total |

**Ningún asiento lleva material de clave, tokens ni hashes** (regla dura 21).

---

## 9. A quién se escala

| Situación | Destinatario | Plazo |
| --- | --- | --- |
| La rotación no avanza y hay tarjetas por reimprimir a menos de una semana de la retirada prevista | RRHH del cliente | Mismo día |
| Alguien no puede fichar por una clave retirada antes de tiempo | IT del cliente | Inmediato |
| Sospecha de clave comprometida | Responsable de seguridad | Inmediato, y §7 |

**Relacionados:** [`rotacion-secretos.md`](rotacion-secretos.md) ·
[`ataque-a-credenciales.md`](ataque-a-credenciales.md) ·
[`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md)
