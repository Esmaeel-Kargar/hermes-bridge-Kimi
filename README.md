# Hermes Bridge Plugin

## Smart Data Bridge for Dynamix Systems

پلاگین Bridge هوشمند برای اتصال Hermes AI Agent به سیستم‌های وردپرس.

### معماری

```
┌─────────────────────────────────────────────────────────────┐
│                    cPanel Shared Hosting                     │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  WordPress + EDD + WP ERP + WP Project Manager + ...   │  │
│  │                                                        │  │
│  │  ┌────────────────────────────────────────────────┐  │  │
│  │  │         Hermes Bridge Plugin                   │  │  │
│  │  │  • Cron Job: هر ساعت sync می‌کنه             │  │  │
│  │  │  • Smart Deduplication                         │  │  │
│  │  │  • REST API برای Hermes                        │  │  │
│  │  └────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ WiFi (وقتی Hermes روشنه)
                              ▼
                    ┌─────────────────┐
                    │  Hermes (Desktop)│
                    │  Read/Write API  │
                    └─────────────────┘
```

### استراتژی Sync

| نوع دیتا | جدول | رفتار | مثال |
|----------|------|-------|------|
| **Events** | `hermes_events` | Append-Only (یکتا) | سفارش جدید، Contact جدید |
| **Snapshots** | `hermes_snapshots` | Overwrite (بروزشونده) | درآمد امروز، تعداد بازدید |
| **Actions** | `hermes_actions_queue` | Queue → Process | ساخت Task از طرف Hermes |

### نصب

1. فایل `hermes-bridge.zip` رو توی وردپرس نصب کن
2. فعال‌سازی → جداول دیتابیس ساخته می‌شه
3. Cron job خودکار تنظیم می‌شه
4. به `Hermes Bridge` توی منوی ادمین برو و **Generate API Key** بزن
5. API Key رو توی Hermes تنظیم کن

### تنظیم Cron Job واقعی (cPanel)

برای اینکه sync دقیقاً هر ساعت اجرا بشه (حتی وقتی کسی سایت رو باز نکرده):

```bash
# cPanel → Cron Jobs
# Every hour:
curl -s https://your-site.com/wp-cron.php?doing_wp_cron=1 > /dev/null 2>&1
```

یا از WP Cron استفاده کن (پلاگین خودش schedule کرده).

### API Endpoints

| Endpoint | Method | توضیح |
|----------|--------|-------|
| `/wp-json/hermes-bridge/v1/sync` | GET | دریافت events + snapshots |
| `/wp-json/hermes-bridge/v1/sync?mark_consumed=false` | GET | فقط بخون، consumed نکن |
| `/wp-json/hermes-bridge/v1/action` | POST | ارسال دستور به سیستم |
| `/wp-json/hermes-bridge/v1/summary` | GET | خلاصه سریع |
| `/wp-json/hermes-bridge/v1/sync/trigger` | POST | اجرای دستی sync |

### Header Authentication

```
X-Hermes-Key: your-api-key-here
```

### مثال دریافت دیتا (Python/Hermes)

```python
import requests

url = "https://your-site.com/wp-json/hermes-bridge/v1/sync"
headers = {"X-Hermes-Key": "YOUR_API_KEY"}

response = requests.get(url, headers=headers)
data = response.json()

print(data['summary'])
# درآمد امروز: 15.50 USDT | سفارشات: 2 | تسک‌های باز: 5 | overdue: 1 | ویزیتور امروز: 45

for event in data['events']:
    print(f"{event['event_type']}: {event['payload']}")
```

### مثال ارسال دستور (Python/Hermes)

```python
import requests

url = "https://your-site.com/wp-json/hermes-bridge/v1/action"
headers = {"X-Hermes-Key": "YOUR_API_KEY"}

payload = {
    "type": "create_task",
    "target_system": "erp_crm",
    "payload": {
        "title": "فالوآپ Lead جدید از صفحه پهپاد",
        "contact_id": 123,
        "due_date": "2026-09-05"
    }
}

response = requests.post(url, json=payload, headers=headers)
print(response.json())
```

### Event Types

| Event Type | Source | توضیح |
|------------|--------|-------|
| `new_order` | edd | سفارش جدید EDD |
| `new_customer` | edd | مشتری جدید |
| `new_contact` | erp_crm | Contact جدید WP ERP |
| `new_deal` | erp_crm | Deal جدید |
| `new_task` | erp_crm | Task CRM جدید |
| `new_project` | pm | پروژه جدید WP Project Manager |
| `new_pm_task` | pm | Task پروژه جدید |
| `new_milestone` | pm | Milestone جدید |

### Snapshot Keys

| Key | Type | توضیح |
|-----|------|-------|
| `edd_revenue_today` | counter | درآمد امروز |
| `edd_orders_today` | counter | تعداد سفارش امروز |
| `edd_total_customers` | gauge | کل مشتری‌ها |
| `erp_total_contacts` | gauge | کل Contact‌ها |
| `erp_deals_by_stage` | gauge | Dealها بر اساس Stage |
| `erp_overdue_tasks` | gauge | Taskهای overdue |
| `pm_open_tasks` | gauge | Taskهای باز |
| `pm_completed_tasks` | counter | Taskهای تکمیل شده |
| `pm_total_projects` | gauge | کل پروژه‌ها |
| `stats_visitors_today` | counter | ویزیتور امروز |
| `stats_pageviews_today` | counter | Pageview امروز |
| `stats_top_pages` | gauge | صفحات پرترافیک |
| `stats_referrers` | gauge | منابع ترافیک |

### Action Types

| Action Type | Target System | Payload |
|-------------|---------------|---------|
| `create_task` | erp_crm | title, contact_id, due_date |
| `create_note` | erp_crm | contact_id, content |
| `update_deal_stage` | erp_crm | deal_id, stage |
| `create_task` | pm | title, project_id |
| `create_milestone` | pm | title, project_id |
| `create_discount` | edd | code, amount, type |

### Smart Deduplication

- **Events**: با ترکیب `source + source_id + event_type` چک می‌شه → تکراری ذخیره نمی‌شه
- **Snapshots**: با `checksum` مقایسه می‌شه → اگه تغییر نکرده، آپدیت نمی‌شه
- **Delta Tracking**: برای snapshotهای عددی، مقدار تغییر (delta) هم ذخیره می‌شه

### نکات مهم

1. **هاست ۳ گیگ**: پلاگین خیلی سبکه (< ۵۰KB). فقط جداول دیتابیس فضا می‌گیرن.
2. **Cron Job**: به صورت پیش‌فرض hourly هست. می‌تونی از Admin Dashboard به ۱۵ دقیقه تغییر بدی.
3. **API Key**: هر بار regenerate می‌کنی، key قبلی invalid می‌شه.
4. **Hermes Offline**: وقتی Hermes خاموشه، events توی دیتابیس می‌مونن و وقتی روشن می‌شه همه رو می‌گیره.
5. **mark_consumed**: وقتی Hermes sync می‌کنه، events consumed می‌شن. اگه می‌خوای دوباره بخونی، `mark_consumed=false` بفرست.

### نسخه

v1.0.0 - Dynamix Systems
