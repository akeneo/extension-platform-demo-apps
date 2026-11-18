# Load tests (both apps)

Scripts to load-test either app, and the notes from the July 2026 load-test investigation. The results are on Notion: [Node app](https://app.notion.com/p/3a412ed8c59081bd878cc7a1ed26a304), [Symfony app](https://app.notion.com/p/39e12ed8c590813aacbee4acbf2a006a).

## Pointing a script at an app

Each script reads its target from environment variables, so the same script works for both apps.

| Variable | Symfony app | Node app |
|---|---|---|
| `APP_HOST` | `main-bvxea6i-gtipgifdob6ek.eu-5.platformsh.site` | `main-bvxea6i-5l73tu5p63wae.eu-5.platformsh.site` |
| `PROJ` | `gtipgifdob6ek` | `5l73tu5p63wae` |
| `AKENEO_WEBHOOK_SECRET` | Read it from the app's environment variables | Same |
| `CUTOFF` (`monitor_pressure.sh` only) | UTC time the test starts | Same |
| `WEBHOOK_TYPE` (`monitor_pressure.sh` only) | `webhook` (the default) | `productUpdated` |

## Files

| File | What it does |
|---|---|
| `realistic_rate_test_v2.js` | Sends signed webhooks at a steady rate with fake product UUIDs, to measure how much traffic the app accepts |
| `webhook_pressure_test.js` | Sends signed webhooks with real product UUIDs read from a file, to measure how a growing queue slows the app down |
| `monitor_backlog.sh`, `monitor_pressure.sh` | Sample queue depth, CPU, memory and database state during a test, through the `akeneo-extension-platform` CLI |
| `real_uuids.txt` | 571 product UUIDs from the Symfony app's PIM sandbox. The Node app is connected to another sandbox, so it needs its own list |
| `symfony-load-test-runbook.md` | How the Symfony tests were run |
| `symfony-stack-review.md` | Review of the Symfony setup before the tests |
| `node-load-test-handoff.md` | Findings and methods from the Node tests |
