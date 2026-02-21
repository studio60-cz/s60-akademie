# s60-akademie — Moodle LMS Integration

**Project:** S60 Akademie — Moodle LMS pro Learnia.cz
**Agent:** akademie
**Status:** 🚧 Setup & Integration Phase

---

## 🚨 MANDATORY: CHECK MESSAGES FIRST!

**BEFORE EVERY RESPONSE - NO EXCEPTIONS:**

```bash
/root/dev/agent-messages/check-my-messages.sh akademie
```

⚠️ **POVINNÉ:** První příkaz KAŽDÉ response!

**Template každé response:**
```
Bash: /root/dev/agent-messages/check-my-messages.sh akademie
→ [zprávy nebo silent]
→ [pokračuj s prací]
```

**Posílání zpráv:**
```bash
/root/dev/agent-messages/redis-queue.sh send main INFO "Moodle REST token ready" "..."
/root/dev/agent-messages/redis-queue.sh send badwolf TODO "Update CoursesModule config" "..."
```

---

## 🔌 MCP SERVERY (aktivní)

Máš přístup ke třem MCP serverům (sdílená konfigurace ~/.claude/settings.json):

### s60-docs — Filesystem
- `/root/dev/s60-docs/`, `/root/dev/KNOWLEDGE_BASE.md`, `/root/dev/CLAUDE.md`
- Použití: čtení dokumentace přes `mcp__s60-docs__read_file`

### s60-database — PostgreSQL (s60_badwolf)
- Přímé SQL dotazy: `mcp__s60-database__query`
- Tabulky: `applications`, `clients`, `courses`, `online_courses`, `course_dates`

### s60-knowledge — Knowledge MCP Server
- `mcp__s60-knowledge__search_docs query="Moodle OAuth2"`
- `mcp__s60-knowledge__get_session_notes lines=150`
- `mcp__s60-knowledge__log_decision text="..."`
- `mcp__s60-knowledge__get_service_info service="all"`

---

## Přehled

**Co je s60-akademie:**
- Moodle LMS konfigurace, integrace a správa pro Learnia.cz
- OAuth2 SSO napojení na S60Auth
- REST API setup pro S60BadWolf CoursesModule
- Moodle pluginy, témata, admin konfigurace

**Co Moodle dělá v S60 ekosystému:**
- LMS pro online kurzy (Learnia.cz)
- Uživatelé se přihlašují přes S60Auth (OAuth2 SSO)
- S60BadWolf volá Moodle REST API pro:
  - Výpis kurzů (`CoursesModule`)
  - Enrollmenty uživatelů po zaplacení (`OrdersModule`)
  - Progress tracking
- WordPress (Learnia) zobrazuje data z Moodle přes BadWolf (NIKDY přímo!)

---

## 🖥️ Infrastruktura

### VPS — Moodle hosting
```
IP: 88.86.124.15
SSH: root@88.86.124.15 (přes Tailscale nebo přímý přístup)

URLs:
  Dev:        akademie.s60dev.cz
  Staging:    akademie.s60hub.cz
  Production: akademie.learnia.cz

Stack:
  PHP-FPM + Nginx
  MariaDB (nebo PostgreSQL — ověřit)
  Redis cache (doporučeno pro Moodle session)
```

### DigitalOcean Droplet — Backend API
```
S60BadWolf: be.s60dev.cz
CoursesModule: volá Moodle REST API s service tokenem
```

---

## 🔐 S60Auth OAuth2 Integrace

### Cíl
Uživatelé Learnia.cz se přihlašují přes S60Auth SSO — žádné duplicitní účty v Moodle.

### Jak na to (Moodle OAuth2 plugin)
```
Moodle Admin → Site Administration → Plugins → Authentication → OAuth2

1. Přidat nový OAuth2 issuer:
   - Name: S60Auth
   - Client ID: (vygenerovat v S60Auth admin — nový System)
   - Client Secret: (z S60Auth)
   - Discovery URL: https://auth.s60dev.cz/.well-known/openid-configuration
   - Scopes: openid email profile

2. Nastavit field mapping:
   - Moodle username ← S60Auth sub (userId)
   - Moodle email    ← S60Auth email
   - Moodle firstname ← S60Auth given_name
   - Moodle lastname  ← S60Auth family_name

3. Zapnout: Allow login via OAuth2
```

### S60Auth System registrace
```bash
# Přidat Moodle jako System v S60Auth admin UI:
Name: Moodle Akademie
Home URL: https://akademie.learnia.cz
Callback URL: https://akademie.learnia.cz/admin/oauth2callback.php
Available roles: student, instructor
```

---

## 🔌 Moodle REST API Setup

### Účel
S60BadWolf CoursesModule volá Moodle REST API pro data o kurzech a enrollment.

### Konfigurace v Moodle
```
Admin → Site Administration → Server → Web services

1. Enable web services: YES
2. Enable REST protocol: YES
3. Vytvořit Service:
   Name: S60BadWolf Integration
   Functions:
     - core_course_get_courses
     - core_course_get_contents
     - enrol_manual_enrol_users
     - core_user_get_users
     - core_enrol_get_enrolled_users
     - gradereport_user_get_grade_items
     - core_completion_get_course_completion_status

4. Vytvořit dedicated user: s60-api-user
   - Role: Service account (minimální práva)
   - Přiřadit k Service

5. Generovat token pro s60-api-user
   → uložit do .env jako MOODLE_TOKEN
```

### BadWolf CoursesModule konfigurace
```bash
# /root/dev/s60-badwolf/.env nebo s60-infra/.env:
MOODLE_URL=https://akademie.s60dev.cz
MOODLE_TOKEN=<vygenerovaný token>
MOODLE_CACHE_TTL=3600  # Redis cache pro course data
```

---

## 📋 Tier 1: Povinné čtení po startu

```bash
# 1. Knowledge Base (credentials, paths)
Read: /root/dev/KNOWLEDGE_BASE.md

# 2. Kritická pravidla
Read: /root/dev/s60-docs/RULES.md

# 3. Poslední rozhodnutí
tail -200 /root/dev/s60-docs/SESSION-NOTES.md | head -150

# 4. Tato CLAUDE.md (Moodle specifika)
Read: /root/dev/s60-akademie/CLAUDE.md

# 5. BadWolf CoursesModule (jak BW volá Moodle)
Read: /root/dev/s60-badwolf/src/modules/courses/ (pokud existuje)

# 6. Celková architektura
mcp__s60-knowledge__search_docs query="Moodle CoursesModule enrollment"
mcp__s60-knowledge__get_service_info service="badwolf"
```

---

## 🎯 Scope práce

### Fáze 1 — Základní setup
- [ ] Ověřit Moodle verzi a stav na VPS (88.86.124.15)
- [ ] Zapnout Moodle REST API + Web services
- [ ] Vytvořit service account + generovat token
- [ ] Ověřit token (curl test základních API calls)
- [ ] Zapsat token do .env + notify BadWolf agent

### Fáze 2 — S60Auth OAuth2 SSO
- [ ] Vytvořit Moodle System v S60Auth admin
- [ ] Nakonfigurovat OAuth2 plugin v Moodle
- [ ] Otestovat SSO flow (S60Auth → Moodle)
- [ ] Field mapping (userId, email, jméno)

### Fáze 3 — Enrollment integrace
- [ ] Enrollment flow: OrdersModule → Moodle enrol_manual_enrol_users
- [ ] Testovat enrollment po zaplacení
- [ ] Progress tracking (completion status)

### Fáze 4 — Pokročilé
- [ ] Moodle Redis cache
- [ ] Custom theme (Learnia branding)
- [ ] Course sync: Moodle → S60BadWolf catalog

---

## 📨 Komunikace s ostatními agenty

### Když máš hotový REST token:
```bash
/root/dev/agent-messages/redis-queue.sh send badwolf TODO \
  "Moodle REST API token ready" \
  "MOODLE_URL=https://akademie.s60dev.cz
MOODLE_TOKEN=<token>
Přidat do .env a ověřit CoursesModule connection."
```

### Když máš hotové OAuth2:
```bash
/root/dev/agent-messages/redis-queue.sh send main INFO \
  "Moodle OAuth2 SSO ready" \
  "S60Auth System: Moodle Akademie
Client ID: <id>
Callback: https://akademie.s60dev.cz/admin/oauth2callback.php
Test: přihlás se přes SSO na akademie.s60dev.cz"
```

---

## 🚨 SERVER LIFECYCLE

**NIKDY nespouštěj/restartuj BadWolf přímo!**

```bash
# Pokud potřebuješ restart BadWolf po změně .env:
/root/dev/agent-messages/redis-queue.sh send main \
  SERVER_START_REQUEST \
  "BadWolf restart needed" \
  "Přidán MOODLE_TOKEN do .env, potřebuji restart CoursesModule"
```

**Moodle restart (pokud potřebuješ):**
- Moodle = PHP, restartuje se přes `systemctl restart php-fpm nginx`
- Nebo: `sudo -u www-data php /var/www/moodle/admin/cli/cron.php`
- Přímý SSH na VPS je OK pro Moodle admin operace

---

## 📚 Reference

```
/root/dev/s60-badwolf/src/modules/courses/   → CoursesModule (jak BW volá Moodle)
/root/dev/s60-docs/SESSION-NOTES.md          → rozhodnutí o Moodle integraci
/root/dev/KNOWLEDGE_BASE.md                  → VPS IP, credentials reference
https://docs.moodle.org/dev/Web_service_API_functions  → Moodle API docs
https://docs.moodle.org/dev/OAuth_2_Services           → OAuth2 setup docs
```

---

## 📦 Git Workflow

```bash
# Inicializace (první session):
git init
git config user.email "claude-akademie@anthropic.com"
git config user.name "Claude Akademie Agent"
git remote add origin https://<GITHUB_TOKEN>@github.com/studio60-cz/s60-akademie.git
# Token najdeš v: ~/.git-credentials nebo /root/dev/KNOWLEDGE_BASE.md → GitHub sekce

# Každý commit:
git add .
git commit -m "feat/fix/docs: popis

Co-Authored-By: Claude Akademie Agent <claude-akademie@anthropic.com>"
git push
```

---

**Last updated:** 2026-02-21
**Agent:** akademie
**Status:** ✅ Ready to start
