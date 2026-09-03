# Hermes Bridge — WordPress Plugin

**Smart data bridge between EDD, WP ERP, WP Project Manager, WP Statistics and an AI Agent (OpenRouter).**

**Version:** 2.1.0  
**Author:** Dynamix Systems  
**License:** GPL v2

---

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    cPanel Shared Hosting                     │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  WordPress + EDD + WP ERP + WP Project Manager       │  │
│  │                                                        │  │
│  │  ┌────────────────────────────────────────────────┐  │  │
│  │  │         Hermes Bridge Plugin                   │  │  │
│  │  │  • Sync Engine (hourly cron)                   │  │  │
│  │  │  • REST API for Hermes (desktop)               │  │  │
│  │  │  • AI Agent (OpenRouter chat + analysis)        │  │  │
│  │  │  • Graduated automation (propose → learn → auto)│  │  │
│  │  └────────────────────────────────────────────────┘  │  │
│  │                                                        │  │
│  │  ┌────────────────────────────────────────────────┐  │  │
│  │  │  WP ERP CRM  ◄── Agent Proposals ──►  WP PM    │  │  │
│  │  │  ("Requests" tab)     (learns)     (🤖 Agent    │  │  │
│  │  │                       ← feedback →   Proposals) │  │  │
│  │  └────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────┘  │
│                              │                              │
│                    REST API  │  X-Hermes-Key                 │
│                              ▼                              │
│                    ┌─────────────────┐                       │
│                    │  Hermes (Desktop)│                      │
│                    │  "چخبر؟"        │                      │
│                    └─────────────────┘                      │
└─────────────────────────────────────────────────────────────┘
```

## ✨ Features

| Module | Description |
|--------|-------------|
| **Sync Engine** | Hourly cron collects data from EDD, ERP, PM, WP Statistics into events + snapshots tables |
| **REST API** | `/hermes-bridge/v1/sync`, `/summary`, `/action`, `/chat` — for Hermes desktop client |
| **AI Agent Chat** | Private admin chat with sessions, file/image upload, RTL/LTR, model selector |
| **Scheduled Analysis** | Periodic AI analysis (15min/30min/hourly/daily) with configurable depth |
| **Graduated Automation** | Agent proposes → E.K approves/rejects in ERP CRM "Requests" tab → learns → auto-promotes groups |
| **Memory** | Long-term fact storage with learning from decisions |
| **Reports** | Full analysis history with feedback loop |

## 📦 Installation

1. Upload `hermes-bridge-Kimi/` to `/wp-content/plugins/`
2. Activate from WordPress Plugins screen
3. Set OpenRouter API key in **Settings → Connectors** (or `HERMES_OPENROUTER_KEY` constant)
4. Configure models in **Hermes Bridge → Agent → Settings**
5. Run your first analysis!

## 🔑 API Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/hermes-bridge/v1/sync` | Events + snapshots |
| GET | `/hermes-bridge/v1/summary` | Quick status |
| POST | `/hermes-bridge/v1/action` | Queue system action |
| POST | `/hermes-bridge/v1/sync/trigger` | Force sync |
| POST | `/hermes-bridge/v1/chat` | Agent chat message |
| GET | `/hermes-bridge/v1/chat/sessions` | Chat sessions list |
| POST | `/hermes-bridge/v1/chat/upload` | File/image upload |

**Auth:** `X-Hermes-Key` header or WordPress `manage_options` capability.

## 📋 Requirements

- WordPress 6.2+
- PHP 7.4+
- OpenRouter API key
- Optional: WP ERP, WP Project Manager, EDD, WP Statistics

## 🤖 Automation Learning Cycle

```
1. Agent analyzes site data + goals + memory
2. Creates proposals as tasks in PM "🤖 Agent Proposals" project
3. E.K reviews in ERP CRM → "Requests" tab
4. Approve ✅ / Reject with feedback ⛔
5. Decisions logged to learning stats
6. Group approval rate > 90% → auto-promoted
7. Promoted groups execute directly without asking
```

## 📄 License

GPL v2 — see [LICENSE](LICENSE) file.