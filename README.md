# Brikkin Command Deck

Project tracking dashboard for Brikkin, live at https://barterro.com/commanddeck/

| Folder | What it is |
|---|---|
| `commanddeck/index.html` | The dashboard page. Every change pushed to `main` is uploaded to barterro.com automatically. |
| `apps-script/Code.gs` | Google Apps Script backend (users, login log, VIRA messages, sheet data). Backup only: paste it into Apps Script and deploy a new version by hand. |
| `.github/workflows/deploy.yml` | GitHub Action that uploads `commanddeck/` to the hosting by FTP. |

## Repository secrets (Settings → Secrets and variables → Actions)

| Name | Example |
|---|---|
| `FTP_SERVER` | ftp.barterro.com |
| `FTP_USERNAME` | your FTP user |
| `FTP_PASSWORD` | your FTP password |
| `FTP_SERVER_DIR` | public_html/commanddeck/ (must end with /) |

Keep this repository **private**.
