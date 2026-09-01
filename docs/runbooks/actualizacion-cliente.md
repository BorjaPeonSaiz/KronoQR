# Runbook — actualizar una instalación de cliente

> **Estado: esqueleto.** Este runbook lo completa la **tarea 5.7**, que es la
> que construye `update.sh`. Existe ya porque `install.sh` remite aquí cuando
> detecta una instalación previa y sale con código `3`, y un enlace roto en ese
> momento —con alguien delante que quería actualizar y acaba de ver un error—
> es exactamente el tipo de detalle que convierte un producto en una consultora.
>
> **Lo que hay aquí abajo ya es firme**: es lo que el actualizador tiene que
> hacer, decidido en el doc 02 §11.6.4 y en ADR-016. Lo que falta es el detalle
> operativo de cada paso y las salidas reales del script.

---

## 1. Lo primero: no estás en el sitio equivocado

Si has llegado aquí porque `install.sh` ha salido con **código 3** diciendo «se
ha encontrado una instalación previa», es correcto y es deliberado. **El
instalador no se instala encima de un registro horario**, que hay que conservar
cuatro años por ley. Para pasar a una versión nueva se usa `update.sh`.

```bash
cd /opt/kronoqr-<version-actual>
./doctor.sh            # cómo está ahora mismo
```

---

## 2. Lo que hace `update.sh`, en orden

El orden no es cosmético: cada paso existe para que el anterior se pueda
deshacer.

1. **Comprobar precondiciones.** Espacio, versión de origen soportada
   (§11.6.5), servicios sanos. Si algo falla aquí, **nada se toca** y sale con
   código `2`.
2. **Copia de seguridad completa y verificada.** **Bloqueante**: sin una copia
   que verifique, la actualización no continúa. Una actualización sin vuelta
   atrás no es una actualización.
3. **Modo mantenimiento.** El panel deja de aceptar escrituras. **Los quioscos
   siguen fichando**: encolan en local y sincronizan al terminar (regla dura
   19). Para la plantilla, la actualización es invisible.
4. **Migraciones en orden de versión, con punto de control entre cada una.**
   Un cliente puede estar en la 1.2.0 cuando ya va la 1.6.0: se encadenan las
   intermedias, no se salta directo (§11.6.4).
5. **Arrancar y verificar**, con las mismas sondas que usa el instalador:
   `/api/v1/health` y `/api/v1/ready`.
6. **Vuelta atrás automática** a la copia previa si algo falla.
7. **Informe del resultado**, guardado en el servidor del cliente.

---

## 3. Códigos de salida

`update.sh` usa **la misma tabla que los otros cuatro scripts**, publicada en
[`../cliente/operacion.md`](../cliente/operacion.md), sección 8:

| Código | Significa aquí |
| --- | --- |
| `0` | Actualizado y verificado |
| `1` | Uso incorrecto. Nada tocado |
| `2` | Requisitos no cumplidos. **NADA escrito** |
| `3` | La versión instalada ya es la de destino. Nada que hacer |
| `4` | Falló y **volvió a la versión anterior**. El sistema está operativo |
| `5` | Falló y la vuelta atrás quedó **incompleta**. El mensaje dice qué queda |
| `6` | Actualizado, pero la verificación posterior falló. **No se ha deshecho nada** |

---

## 4. Lo que no cambia nunca al actualizar

- **El `.env` con tus secretos.** El actualizador no regenera ninguno.
- **Los datos.** Las migraciones amplían el esquema; no borran registro
  horario.
- **La licencia.** Una versión nueva no exige clave nueva, y una clave emitida
  con una versión posterior se puede activar en una instalación anterior.
- **El fichaje.** Ni durante la actualización ni si falla.

---

## 5. A quién se escala

Si `update.sh` sale con `5`, o si tras un `6` las sondas siguen sin responder,
genera el paquete de diagnóstico y ábrele un caso al fabricante. **El paquete
va anonimizado por defecto** y no lleva nombres, correos ni registros de
jornada.
