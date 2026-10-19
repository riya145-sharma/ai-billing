# AI Content Generator

PHP 8.2+ application with JSON file storage and Bootstrap. The application contains:

- Content generator: create text with the default Azure chat deployment, choose format and tone, copy/download results, and view private history.
- Text to speech: convert text to MP3, choose a voice, play/download audio and view private history.
- Automation: three motivational messages with matching MP3 audio, generated automatically every day by cron for selected users.
- Azure deployments (administrators): configure text deployments, select the default, discover deployments and test connections.
- Users (administrators): create users, edit roles and status, reset passwords and unlock accounts.

Login, logout, CSRF protection, role checks and session expiry protect access. The home dashboard links to Content generator and Text to speech. Other former application pages are removed. Existing stored records are retained.

## Local setup

Use PHP 8.2+ with curl, mbstring, json, fileinfo and openssl enabled. The application has its own autoloader; Composer installation is optional.

```powershell
php bin/install.php
php bin/create-admin.php --email=you@example.com --name="Your Name"
php -S localhost:8000 -t public public/index.php
```

Set `AZURE_OPENAI_API_KEY` in `.env`. For local HTTP, use `APP_ENV=local` and `SESSION_SECURE_COOKIE=false`. For production, use HTTPS and secure session cookies.

The seeded chat deployment is `gpt-4.1-mini`, with endpoint `https://snd-ai-2-resource.openai.azure.com/openai/v1`. Set an active default chat deployment under Azure deployments. API keys remain on the server and are never sent to the browser.

## Storage and hosting

### Text-to-speech setup

Deploy an Azure OpenAI TTS model such as `gpt-4o-mini-tts`, `tts-1` or `tts-1-hd` in a supported Azure region. Under **Azure deployments**, add its exact deployment name, choose purpose **Text to speech**, enter its resource endpoint/key variable, and mark it as default. Speech and chat have separate defaults. Your existing `gpt-4.1-mini` deployment cannot generate audio. Legacy API endpoints are also supported when configured with their required API version.

Open `/speech`, enter up to 4,096 characters and choose a voice. Audio is saved under private `storage/data/speech/audio/` and served only to its owner through authenticated routes. The page labels audio as AI-generated. Binary speech responses do not report token usage; token counts and prices remain unknown rather than estimated. Input validation, shared API rate limiting, duplicate-request protection and uncertain-outcome handling apply.

See the [Azure speech quickstart](https://learn.microsoft.com/en-us/azure/foundry-classic/openai/text-to-speech-quickstart?view=foundry-classic) and [Azure API reference](https://learn.microsoft.com/en-us/azure/foundry/openai/reference-preview-latest).

Keep `.env`, `storage/`, `app/` and other private project files outside the public web root. Serve only `public/`, or use the included Apache configuration on XAMPP. Back up `storage/` and `.env` securely. Never commit API keys.

Generated briefs and text are saved per user in private JSON storage. The page shows the latest 20 requests. Reusing a request ID does not make another Azure call; uncertain requests are recorded without automatic retry. API calls retain internal usage logs. Legacy shared storage utilities remain for compatibility with existing data.

## Daily motivation automation

For each enabled user, cron creates three motivational texts per day and converts each text into a matching MP3. Users need to take no action.

- **Schedule:** 07:00, 13:00 and 19:00 IST by default. All times, dates and daily limits use Asia/Kolkata, whatever the server clock's timezone is. Keep `APP_TIMEZONE=Asia/Kolkata`.
- **Content:** uses the active default chat deployment (`gpt-4.1-mini`) and asks for 100–150 words in the configured language, with an encouraging tone. The prompt forbids quotations and attributing words to anyone. Each run uses a different theme from the admin's list, and the list rotates daily. Texts that still look like attributed quotations are flagged for admins. The text is never altered.
- **Speech:** uses the active default speech deployment (`tts`) and sends it exactly the saved text. The speech deployment's `rpm_limit` (3) is enforced across cron and manual web requests: there are never more than 3 speech requests in any 60 seconds.
- **Keys:** API keys are read from the server environment or `.env` (`AZURE_OPENAI_API_KEY`, `AZURE_OPENAI_SPEECH_API_KEY`). They are never stored, logged or shown.

### Enabling and disabling automation

1. Sign in as an administrator and open **Automation**.
2. Under **Schedule and content**, set up to three IST times, the language, the voice and the themes (one per line). Then select **Save settings**. Jobs that have not started yet use the new settings.
3. Under **Users**, switch automation on for the users you choose, then select **Save users**. If you enable a user during the day, today's earlier times are caught up on the next cron run.
4. To disable automation, switch the user off and save. Jobs that have not started are removed. A job that is already in progress finishes.

Each user sees their own messages, audio player and MP3 download on the Automation page. Audio is served only to its owner, and administrators are included in this rule. Administrators see job status, errors, allowances, upcoming jobs and the last cron heartbeat, but they cannot see other users' texts or audio.

### Daily limits

Users with automation enabled get at most **3 successful content generations and 3 successful speech generations per IST day**. Manual requests on Content generator and Text to speech share this limit with automation.

- Failed requests do not use the allowance. Pending and uncertain requests hold a place, so concurrent requests cannot go over the limit.
- Before a job starts, automation reserves the content and speech allowance for its text/audio pair together. If manual use has already taken the remaining allowance, the job is **Blocked**. Nothing is sent to Azure, and the job message explains which requests used the allowance. A blocked job is checked again on later runs that day in case capacity frees up.
- The allowance resets at midnight IST. History is never deleted. The ledger is in `storage/data/allowance/YYYY/MM/YYYY-MM-DD.json`.
- Users without automation keep unlimited manual generation, as before.

### Reliability rules

- **Duplicate prevention:** job IDs are fixed by date, user and time (`job_20261019_5_0700`). Overlapping runs are skipped using a lock. Each Azure request is claimed atomically before it is sent.
- **Text before speech:** the text is saved before speech is requested. If speech fails, retries reuse the saved text and the text is never generated again.
- **Retries:** only confirmed temporary failures are retried: HTTP 429, 500, 502 and 503, or a connection that was never made. There are up to 3 attempts per step, with backoff of 60 s, then 120 s, and so on. `Retry-After` and `retry-after-ms` are honoured, and a 429 pauses that deployment for every caller. Permanent errors (400, 401, 403, 404) fail immediately with a hint.
- **Uncertain outcomes:** a timeout after sending, HTTP 408/504, an invalid audio response or a run that stopped mid-request is marked **Uncertain** and is never repeated automatically. It holds its allowance place. An administrator can check the Azure usage log, then select **Retry** to confirm nothing was produced and run the job again.
- **Missed times:** missed times from the current IST day are caught up. Jobs left open from earlier days are marked **Expired**, with any saved text kept. Earlier days are never caught up.

### Cron setup

The entry point is CLI-only and runs every 5 minutes. Each run lasts at most about 4 minutes, plus any request still in flight: `--budget=SECONDS` changes the default of 240. It processes whatever is due.

**Windows (XAMPP), Task Scheduler.** Open *Command Prompt as Administrator* and run this as one line:

```bat
schtasks /Create /TN "AI Billing Daily Motivation" /SC MINUTE /MO 5 /F /RL HIGHEST /RU "%USERDOMAIN%\%USERNAME%" /RP * /TR "C:\xampp\php\php.exe -f C:\xampp\htdocs\ai-billing\bin\automation-cron.php"
```

- `/RP *` prompts for your Windows password. The task then runs every 5 minutes whether or not you are signed in, and independently of any browser session.
- Use the Windows account that runs Apache, so that both can write `storage/`. If Apache is installed as a Windows service under Local System, use `/RU SYSTEM` and remove `/RP *`.
- New tasks default to *Do not start a new instance*, and the application's lock also prevents overlapping runs.
- Test it with `schtasks /Run /TN "AI Billing Daily Motivation"`. Check it with `schtasks /Query /TN "AI Billing Daily Motivation" /V /FO LIST` and look at "Last Result", which is 0 when the run succeeded.
- Remove it with `schtasks /Delete /TN "AI Billing Daily Motivation" /F`.

**Linux (production).** Run `crontab -e` and add this line, changing the paths to your install. Find the PHP path with `which php`.

```cron
*/5 * * * * cd /home/u123456789/domains/example.com/ai-billing && /usr/bin/php bin/automation-cron.php >> storage/logs/automation-cron.log 2>&1
```

You do not need `CRON_TZ`, because the application calculates IST itself. Keep the existing maintenance job (`bin/cron.php`) as well.

Run `php bin/automation-cron.php --status` at any time. It shows the timezone, the last heartbeat, the last error and today's jobs, and makes no Azure calls.

### Logs and files

| What | Where |
|---|---|
| Run results and job completions/failures | `storage/logs/app-YYYY-MM-DD.log` (lines starting `automation:`; secrets redacted) |
| Cron output (from the Linux redirect above) | `storage/logs/automation-cron.log` |
| Last heartbeat and last error | `storage/data/automation/heartbeat.json`, also shown on the Automation page |
| Settings and enabled users | `storage/data/automation/config.json` |
| Jobs, including every attempt | `storage/data/automation/jobs/YYYY/MM/YYYY-MM-DD.json` |
| Audio | `storage/data/automation/audio/<user id>/<job id>.mp3` (private) |
| Azure request log | `storage/data/usage/…` (one entry per attempt) |

### Troubleshooting

| Symptom | Fix |
|---|---|
| "The automation cron has never run" or "has not run for …" | The scheduled task is missing or failing. Run `C:\xampp\php\php.exe C:\xampp\htdocs\ai-billing\bin\automation-cron.php` by hand and read its output, then check the task's "Last Result". |
| Job **Blocked: Waiting for configuration** | Set an active default chat deployment and speech deployment under Azure deployments, and make sure their key variables are set in `.env`. The job continues on the next run. |
| Job **Blocked: today's 3 … generations are already taken** | Manual requests used the allowance. The job resets at midnight IST. |
| Job **Failed** with HTTP 401/403 | The API key variable is wrong or missing. Fix `.env`, then select **Retry**. |
| Job **Failed** with HTTP 404 | Check the deployment name and endpoint. Then select **Retry**. |
| Job **Uncertain** | Check the Azure usage log for that time. Select **Retry** only if nothing was produced, because retrying may cause a duplicate charge. |
| Many "Retrying" jobs, or long waits | Azure is throttling you (429). The runner respects the speech limit of 3 per minute, so several users at the same time take a few minutes. |
| Times look shifted | Set `APP_TIMEZONE=Asia/Kolkata`. The Automation page warns if it is set to anything else. |

## Verification

```powershell
php -d extension=gd tests/run.php
node --check public/assets/js/content.js
node --check public/assets/js/automation.js
```

GD is used by the legacy storage regression tests, not by content generation. Tests use temporary storage and fake Azure responses, without network requests or modifying production records.
