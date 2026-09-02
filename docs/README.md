# 📚 Documentación — ScrapperClub

Este repositorio tiene **dos módulos independientes**. Cada uno con su propia documentación mínima:

## 🧩 scraping/ — Scraper de clubes de fútbol (Python, raíz)
Extrae `federacion, nombre, telefono, email` de federaciones españolas (plataforma NOVA y otras).

| Documento | Qué es |
|---|---|
| [`scraping/ESTADO_LISTADOS_CLUBES.md`](scraping/ESTADO_LISTADOS_CLUBES.md) | Estado real por federación + órdenes de scraping |
| [`scraping/PLAN_SCRAPING.md`](scraping/PLAN_SCRAPING.md) | Pendientes y comandos operativos |

> Referencia de código: `README.md` de la raíz (arquitectura y uso).

## 🧩 outbound/ — CRM FutProtec Outbound (PHP, `public_html/outbound`)
Gestión de leads, envío masivo con tracking, Bandeja, Pipeline… (PHP 8 + SQLite, SiteGround).

| Documento | Qué es |
|---|---|
| [`outbound/ROADMAP_OUTBOUND.md`](outbound/ROADMAP_OUTBOUND.md) | TODO verificado del módulo: **hecho** + **pendiente** (fuente única de tareas) |
| [`outbound/PLAN_AJUSTES_OUTBOUND.md`](outbound/PLAN_AJUSTES_OUTBOUND.md) | Plan de ajustes pendiente, priorizado y resumido |

> Referencia técnica de código: `public_html/outbound/README.md` (incluida en el módulo).

---

**Reglas:** ejecución según `.clinerules` · cambios solo en local hasta pedir deploy/push · no tocar `output/` ni `checkpoints/`.
