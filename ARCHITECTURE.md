# Hermes Bridge v2 — Architecture Specification

> **Version:** 2.1.1  
> **Date:** 2026-09-03  
> **Repository:** https://github.com/Esmaeel-Kargar/hermes-bridge-Kimi  
> **Author:** Esmaeel Kargar (E.K.) + Hermes Agent (Dixi)  
> **License:** Proprietary — Dynamix Systems

---

## 🎯 Core Philosophy

> **"Site = always-on report server; Hermes PC may be off"**

- WordPress site runs 24/7 on shared hosting (LiteSpeed + PHP 8.4)
- Hermes Desktop (local PC) may be offline — no dependency for automation
- Plugin's **hourly cron** collects data from EDD, WP ERP, WP Project Manager, WP-Statistics
- Data stored in **append-only events** + **overwrite snapshots** — fresh status always ready
- Hermes **PULLS on demand** via "چخبر؟" command — no webhook/daemon needed

---

## 🏗️ System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    WORDPRESS SITE (24/7)                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐  │
│  │   EDD        │  │  WP ERP      │  │  WP Project Manager  │  │
│  │  (Orders)    │  │ (Leads/Deals)│  │   (Tasks/Projects)   │  │
│  └──────┬───────┘  └──────┬───────┘  └──────────┬────────────┘  │
│         │                 │                      │              │
│         └─────────────────┼──────────────────────┘              │
│                           ▼                                     │
│              ┌────────────────────────┐                         │
│              │  Hermes Bridge Plugin  │                         │
│              │  - Hourly Cron (sync)  │                         │
│              │  - REST API (v1)       │                         │
│              │  - Admin UI (Agent)    │                         │
│              └───────────┬────────────┘                         │
│                          │                                      │
│         ┌───────────────┼───────────────┐                       │
│         ▼               ▼               ▼                       │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐               │
│  │ wp_hermes_  │ │ wp_hermes_  │ │ wp_hermes_  │               │
│  │ events      │ │ snapshots   │ │ sync_state  │               │
│  │ (append)    │ │ (overwrite) │ │             │               │
│  └─────────────┘ └─────────────┘ └─────────────┘               │
└─────────────────────────────────────────────────────────────────┘
                          │
                    HTTPS + X-Hermes-Key
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                  HERMES DESKTOP (Local, Intermittent)           │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐  │
│  │  Chat UI     │  │  Analysis    │  │  Action Execution    │  │
│  │  (Sessions,  │  │  Engine      │  │  (via REST API)      │  │
│  │   RTL/LTR,   │  │  (LLM +      │  │  - Create Tasks      │  │
│  │   Media)     │  │   Site Data) │  │  - Update Leads      │  │
│  └──────────────┘  └──────────────┘  └──────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 📊 Database Schema

### Core Tables (Sync Engine v1)

| Table | Purpose | Retention |
|-------|---------|-----------|
| `wp_hermes_events` | Append-only event log (orders, sync runs, errors) | Forever (manual cleanup) |
| `wp_hermes_snapshots` | Overwrite snapshots (latest status per source) | Latest only |
| `wp_hermes_sync_state` | Cron state, last run, cursor positions | Single row |

### Agent Tables (v2.1)

| Table | Purpose |
|-------|---------|
| `wp_hermes_sessions` | Chat sessions (id, title, created, updated, archived, pinned) |
| `wp_hermes_agent_log` | Agent actions: proposal_id, group, action (approve/reject), feedback, timestamp, learning_stats |

> **Note:** `wp_hermes_actions_queue` (v1) → **deprecated**, replaced by `wp_hermes_agent_log`

---

## 🔐 Authentication

- **Header:** `X-Hermes-Key: <YOUR_KEY_HERE>` — set via `HERMES_BRIDGE_KEY` constant or `wp_hermes_bridge_key` option
- **Scope:** All REST endpoints under `/wp-json/hermes-bridge/v1/`
- **Capability:** `manage_options` (admin only)

---

## 🌐 REST API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/sync?limit=50&mark_consumed=false` | Pull events + snapshots. **Always pass `mark_consumed=false` when polling**, then call consume separately. |
| `POST` | `/sync/trigger` | Force immediate cron run |
| `GET` | `/summary` | Persian text summary for "چخبر؟" |
| `POST` | `/action` | Execute approved actions (create ERP lead, PM task, EDD discount, etc.) |

### Event Structure (`wp_hermes_events`)

```json
{
  "id": "uuid",
  "event_type": "new_order|sync_complete|error|...",
  "source": "edd|erp|pm|stats|agent",
  "source_id": "entity_id",
  "payload": "{...json...}",
  "created_at": "2026-09-03 04:28:09",
  "consumed": 0,
  "consumed_at": null
}
```

### Snapshot Structure (`wp_hermes_snapshots`)

```json
{
  "source": "edd|erp|pm|stats",
  "data": "{...latest aggregated state...}",
  "updated_at": "2026-09-03 04:28:09"
}
```

---

## 🤖 Agent Architecture (v2.1)

### Graduated Autonomy (3 Tiers)

| Tier | Name | Behavior | Entry Criteria |
|------|------|----------|----------------|
| **L1** | **Auto** | Executes without prompt | Group approval rate ≥ threshold (configurable, default 90% over 10+ tasks) |
| **L2** | **Approval Queue** | Creates task in PM → waits for admin action | **DEFAULT for all new task groups** |
| **L3** | **Suggest Only** | Logs proposal, never creates task | Explicitly assigned by admin |

> **All task groups START at L2.** Admin promotes via Learning tab after observing quality.

### Approval Workflow (v2.1 — No Approval Queue Table)

```
Agent Analysis
      │
      ▼
┌──────────────────────────────────────────┐
│  Create Task in WP Project Manager       │
│  Project: "🤖 Agent Proposals"           │
│  Status: "pending_review"                │
│  Meta: group, analysis_depth, payload    │
└──────────────────────────────────────────┘
      │
      ▼
┌──────────────────────────────────────────┐
│  Create Contact in WP ERP                │
│  life_stage: "agent_proposed"            │
│  Meta: proposal_id, group, summary       │
└──────────────────────────────────────────┘
      │
      ▼
Admin reviews in PM/ERP UI
      │
      ├─► **Approve & Execute** → Task status=completed, log entry, learning++
      │
      └─► **Reject with Feedback** → Task deleted, feedback stored in memory (lesson), learning--
```

### Learning System

- **Daily Feedback Cron:** Reads PM/ERP decisions → updates `wp_hermes_agent_log`
- **Per-Group Stats:** `approval_rate = approved / (approved + rejected)`
- **Auto-Promote:** When `approval_rate ≥ threshold` AND `total ≥ min_samples` → promote group to L1
- **Learning Tab UI:** Table with Promote/Demote buttons per group

---

## 💬 Chat UI (Admin Panel)

| Feature | Details |
|---------|---------|
| **Sessions Sidebar** | Create, rename, delete, archive, pin |
| **RTL/LTR Toggle** | Per-input AND per-message (bidirectional) |
| **Media Upload** | WordPress Media Library integration |
| **Model Selector** | Typeahead dropdown (live from OpenRouter `/models`) |
| **Analysis Depth** | Radio: 1=Quick, 2=Standard (default), 3=Deep |
| **Goals/Strategy** | Textarea — persistent per session |

---

## ⚙️ Settings (Admin)

| Setting | Source | Notes |
|---------|--------|-------|
| **OpenRouter API Key** | `HERMES_OPENROUTER_KEY` constant → `wpai_openrouter_api_key` (WP AI Connectors) → `wpai_connectors` array → legacy fallback | **No key field in Agent settings** |
| **Default Model** | Dropdown (typeahead) | Persisted per session |
| **Analysis Depth Default** | 1/2/3 | |
| **Auto-Promote Threshold** | % (default 90) | For L2→L1 |
| **Min Samples for Promote** | Integer (default 10) | |
| **Enabled Task Groups** | Checklist | Which analysis types Agent can propose |

---

## 🔌 Integration with WP ERP & WP Project Manager

### WP ERP (Leads/Deals/Contacts)
- **Official API:** `WeDevs\ERP\...` classes, not raw SQL
- **Entities Used:** `Lead`, `Deal`, `Contact`, `Activity`
- **Agent Creates:** Leads (from analysis), Contacts (for proposals), Activities (logs)

### WP Project Manager (Tasks/Projects)
- **Official API:** `WeDevs\PM\...` classes
- **Project:** "🤖 Agent Proposals" (auto-created if missing)
- **Task Fields:** title, description, project_id, status (`pending_review`/`completed`), meta (group, depth, payload)
- **Agent Creates:** Tasks for proposals

> **CRITICAL:** Never use raw SQL. Always use plugin's public methods/classes.

---

## 📁 File Structure

```
hermes-bridge-Kimi/
├── hermes-bridge.php              # Entry point, hooks, REST registration
├── README.md
├── ARCHITECTURE.md                # This file
└── includes/
    ├── class-admin.php            # Admin menus, settings, meta boxes
    ├── class-agent.php            # Agent core: analysis, proposal generation
    ├── class-agent-chat.php       # Chat UI: sessions, messages, media
    ├── class-agent-cron.php       # Daily feedback cron, learning stats
    ├── class-agent-db.php         # DB: sessions, agent_log, migrations
    ├── class-agent-ui.php         # Admin UI: Settings, Learning, Memory, Reports tabs
    ├── class-cron-handler.php     # Hourly sync cron (v1)
    ├── class-database.php         # DB abstraction, schema setup
    ├── class-integrator.php       # Integrator: routes analysis to ERP/PM
    ├── class-openrouter.php       # OpenRouter client (streaming, models)
    ├── class-rest-api.php         # REST endpoints (/sync, /summary, /action)
    └── class-sync-engine.php      # Sync engine: collectors, snapshots, events
```

---

## 🔄 Data Flow: Hourly Cron (Sync Engine)

```
Cron Trigger (WP Cron / system cron)
         │
         ▼
┌─────────────────────────────────────┐
│  Collector: EDD Orders              │──► Event: new_order
│  Collector: ERP Leads/Deals         │──► Event: lead_created, deal_updated
│  Collector: PM Tasks/Projects       │──► Event: task_created, project_updated
│  Collector: WP Statistics           │──► Event: stats_snapshot
└─────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Write to wp_hermes_events          │ (append-only)
│  Update wp_hermes_snapshots         │ (overwrite latest per source)
│  Update wp_hermes_sync_state        │ (cursor, last_run)
└─────────────────────────────────────┘
```

---

## 🔄 Data Flow: Agent Analysis (On Demand)

```
User: "چخبر؟" or clicks "Run Analysis Now"
         │
         ▼
Hermes Desktop → GET /summary (instant Persian report)
         │
         ▼
User selects: Model, Depth, Goals → POST /action?type=analyze
         │
         ▼
Agent (local LLM + site data from events/snapshots)
         │
         ▼
Structured JSON Proposal:
{
  "group": "content_strategy|seo_fix|product_idea|...",
  "title": "...",
  "analysis": "...",
  "recommended_actions": [
    {"type": "create_task", "plugin": "pm", "data": {...}},
    {"type": "create_lead", "plugin": "erp", "data": {...}}
  ],
  "analysis_depth": 2
}
         │
         ▼
L2 (Default) → Create Task in PM (pending_review) + Contact in ERP
         │
         ▼
Admin reviews in PM/ERP → Decision recorded → Daily cron updates learning
```

---

## 🌍 Internationalization (i18n)

- All user-facing strings wrapped in `__('text', 'hermes-bridge')`
- Text domain: `hermes-bridge`
- Persian (fa_IR) primary, English (en_US) fallback
- RTL/LTR handled in Chat UI via `dir` attribute per message

---

## 🛡️ Security

| Measure | Implementation |
|---------|----------------|
| **Auth** | `X-Hermes-Key` header validation on every REST request |
| **Capability** | `manage_options` required for all admin pages |
| **Nonce** | All POST forms + AJAX actions |
| **Sanitization** | `sanitize_text_field`, `wp_kses_post`, `esc_attr` on all inputs |
| **SQL** | `$wpdb->prepare()` only — **no raw SQL** |
| **File Upload** | WordPress Media Library (validated mime types) |
| **Rate Limit** | REST API: 60 req/min per IP (via `rest_pre_dispatch`) |

---

## 🧪 Testing Checklist

### Sync Engine
- [ ] Hourly cron runs without overlap
- [ ] Events appended, snapshots overwritten
- [ ] `mark_consumed=false` polling works
- [ ] Large payloads handled (chunked if needed)

### Agent
- [ ] L2: Task created in PM with correct meta
- [ ] L2: Contact created in ERP with `life_stage=agent_proposed`
- [ ] Approve → Task completed, log entry, learning++
- [ ] Reject → Task deleted, feedback stored, learning--
- [ ] L1: Direct execution without PM task
- [ ] L3: Logged only, no PM/ERP write
- [ ] Learning stats calculate correctly
- [ ] Auto-promote at threshold

### Chat UI
- [ ] Sessions persist (create/rename/delete/archive/pin)
- [ ] RTL/LTR toggles work per message
- [ ] Media upload → WordPress Media Library
- [ ] Model selector typeahead filters OpenRouter models
- [ ] Analysis depth radio works

### Settings
- [ ] OpenRouter key reads from WP AI Connectors first
- [ ] Constant fallback works
- [ ] Model selection persists

---

## 📦 Deployment

### Server Requirements
- WordPress 6.0+
- PHP 8.1+ (tested on 8.4.22)
- WP ERP (free or Pro) active
- WP Project Manager (free or Pro) active
- WP AI Connectors (for OpenRouter key) — optional but recommended
- HTTPS + valid SSL (for REST API)

### Install
1. Upload `hermes-bridge-Kimi` to `wp-content/plugins/`
2. Activate plugin
3. Run **Sync Now** from admin bar or `wp-json/hermes-bridge/v1/sync/trigger`
4. Configure **Settings → Hermes Bridge → Agent**:
   - Select default model
   - Set analysis depth default
   - Verify OpenRouter key detected
5. Open **Hermes Bridge → Agent → Chat** — start session

### Update
- Git pull / ZIP upload
- Visit admin → triggers DB migration (if schema changed)
- Verify version in `class-database.php` → `HERMES_BRIDGE_DB_VERSION`

---

## 🗺️ Roadmap (Post v2.1.1)

| Priority | Feature |
|----------|---------|
| **High** | GitHub Actions CI: PHP syntax, PHPCS (WordPress), PHPStan |
| **High** | Automated release: `gh release create v2.1.x` with changelog |
| **Medium** | Webhook support (optional) for real-time events → Hermes |
| **Medium** | Multi-site support (network activation) |
| **Low** | Telegram Bridge integration (via Hermes Bridge v2 API) |
| **Low** | Analytics dashboard (charts: events/hour, approval rates, etc.) |

---

## 📝 Changelog

### v2.1.1 (2026-09-03)
- **Removed** OpenRouter key field from Agent settings → reads from WP AI Connectors
- **Added** "Requests" tab under ERP CRM menu (proposal review UI)
- **Approval workflow** now uses PM Tasks + ERP Contacts (no approval queue table)
- **Learning tab**: approval rates per group, Promote/Demote buttons
- **Chat UI**: Sessions sidebar, RTL/LTR per message, Media Library upload
- **Settings**: Typeahead model selector (live OpenRouter models)
- **Memory tab**: Searchable, filterable by `kind`
- **Reports**: Feedback box per analysis
- **DB**: `wp_hermes_sessions`, `wp_hermes_agent_log` (replaces `actions_queue`)
- **i18n**: All strings wrapped in `__()`
- **Docs:** This ARCHITECTURE.md

### v2.0.0 (2026-08-31)
- Initial v2 rewrite: Sync engine, REST API, Agent core, Admin UI
- Hourly cron with append-only events + overwrite snapshots
- Private chat panel in WP Admin
- Graduated autonomy design (L1/L2/L3)

### v1.x (2026-07~08)
- Basic sync, EDD/ERP/PM collectors
- Simple agent with single approval queue table

---

## 🤝 Credits

- **Concept & Requirements:** Esmaeel Kargar (E.K.) — Founder, Dynamix Systems
- **Implementation:** Hermes Agent (Dixi) — AI pair programmer
- **Inspiration:** Eden Manifesto (AI-human coexistence, mutual survival)
- **Stack:** WordPress, WP ERP, WP Project Manager, OpenRouter, Hermes Desktop

---

## 📄 License

**Proprietary — Dynamix Systems.**  
All rights reserved. Not for redistribution without written permission.

> "Built on an i5-4460, 16GB RAM, behind sanctions.  
> For independence from centralized infrastructure.  
> Memory that belongs to the user, not the company.  
> Everything started from a 7K-subscriber YouTube channel and an old PC."  
> — *Kimi, 2026-08-31, via Hermes Bridge, Dynamix Systems ♥*