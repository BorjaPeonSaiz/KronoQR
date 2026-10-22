#!/usr/bin/env bash
#
# KronoQR — programa el simulacro trimestral de restauracion al instalar y al actualizar
# (RNF-D-05; V5-DV-04, decision del propietario del 22-10-2026).
#
# NO SE EJECUTA SOLO: lo cargan install.sh y update.sh, que ya cargan lib/messages.sh.
#
# LA DECISION. El simulacro se programa por defecto, sin que nadie tenga que acordarse: install.sh
# lo deja puesto y update.sh lo deja puesto o actualizado en las instalaciones que vienen de antes
# (idempotente). Quien no lo quiera pasa `--no-drill-schedule`; esa eleccion se RECUERDA
# (schedule-drill.sh optout) para que una actualizacion posterior no la deshaga, y `doctor.sh`
# sigue avisando de que no hay simulacro. Un fallo aqui NUNCA deshace una instalacion o una
# actualizacion que ya han terminado bien: se avisa y se dice la orden para repetirlo.

set -euo pipefail
IFS=$'\n\t'

# kq_drill_schedule_step DIRECTORIO_DEL_PAQUETE OPT_NO_DRILL(0|1)
# Siempre devuelve 0.
kq_drill_schedule_step() {
  local dir="$1" optout="$2" output

  if [ ! -f "${dir}/schedule-drill.sh" ]; then
    return 0
  fi

  if [ "${optout}" = "1" ]; then
    if output="$(bash "${dir}/schedule-drill.sh" optout 2>&1)"; then
      printf '%s\n' "$(kq_text drill_optout)"
    else
      printf '%s\n' "$(kq_format drill_failed "${output}")" >&2
    fi
    return 0
  fi

  if output="$(bash "${dir}/schedule-drill.sh" ensure 2>&1)"; then
    case "${output}" in
    *"NO se programa"*) printf '%s\n' "$(kq_text drill_remembered_optout)" ;;
    *) printf '%s\n' "$(kq_text drill_scheduled)" ;;
    esac
  else
    printf '%s\n' "$(kq_format drill_failed "${output}")" >&2
  fi
  return 0
}
