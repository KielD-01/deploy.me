<p align="center">
  <img src="docs/assets/banner.svg" alt="deploy.me: деплой ваших проєктів на власні сервери через SSH" width="100%">
</p>

<p align="center">
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?style=for-the-badge&logo=laravel&logoColor=white">
  <img alt="PHP 8.5" src="https://img.shields.io/badge/PHP-8.5-777BB4?style=for-the-badge&logo=php&logoColor=white">
  <img alt="Livewire 4" src="https://img.shields.io/badge/Livewire-4-FB70A9?style=for-the-badge&logo=livewire&logoColor=white">
  <img alt="Docker Compose" src="https://img.shields.io/badge/Docker-Compose-2496ED?style=for-the-badge&logo=docker&logoColor=white">
  <br>
  <a href="docs/mcp.md"><img alt="MCP server" src="https://img.shields.io/badge/MCP-ready-6D4AC7?style=for-the-badge&logo=anthropic&logoColor=white"></a>
  <a href="LICENSE"><img alt="Ліцензія MIT" src="https://img.shields.io/badge/license-MIT-22C55E?style=for-the-badge"></a>
  <a href="#️-створено-разом-із-claude"><img alt="Створено з Claude" src="https://img.shields.io/badge/made_with-Claude_%E2%9D%A4-D97757?style=for-the-badge&logo=claude&logoColor=white"></a>
</p>

<p align="center">
  <a href="README.md">🇬🇧 English</a> · <b>🇺🇦 Українська</b>
</p>

---

**deploy.me** — локальна адмінка в Docker, яка деплоїть ваші проєкти на **ваші власні
сервери через SSH**. Вона працює на вашому Mac за адресою `https://deployme.localhost`.

- Сервери беруться прямо з `~/.ssh/config`.
- Проєкти — з GitHub або з локальної папки.
- Кожен запуск записується з живим логом, з якого вирізано секрети.
- Деплой іде в релізи з атомарним перемиканням або на місці.
- deploy.me збирає чи тягне образи, мігрує, піднімає нові контейнери поруч зі старими і сам
  відкочується, якщо health check каже «ні».

Не потрібні ні PaaS, ні хмарна панель, ні агент на сервері: на іншому боці досить `ssh`,
`rsync` і `docker compose`.

## ✨ Що вміє

| | |
|---|---|
| 🖥️ **Сервери** | З `~/.ssh/config`. Ключ хоста довіряється при першому підключенні і далі закріплений. Регулярні проби: CPU, пам'ять, диски, стеки Compose і десять найактивніших процесів за CPU, пам'яттю та трафіком |
| 📦 **Проєкти** | Імпорт із GitHub (через API, без клонування) або з папки. Визначає воркфлоу, образи GHCR, Compose і фреймворк. Env-файли зашифровані й версіоновані |
| 🚀 **Деплої** | `releases/` + `shared/` + атомарний `current`, або на місці. Віддалений лок, живий лог, скасування і повтор із кроку, що впав |
| 🐳 **Docker Compose** | Перевірка `compose config`, збірка або pull із ghcr.io із закріпленням тегів, `pg_dump` перед міграціями, міграції в одноразовому контейнері, health gate |
| 🔵🟢 **Blue/green** | Нові контейнери стартують поруч зі старими. Старі прибираються лише тоді, коли новий колір здоровий, тому простою немає |
| ⏪ **Відкат** | До будь-якого релізу, що лишився на диску, або назад на закріплені образи. Автоматично, якщо health check не пройшов |
| 🚚 **Стратегії доставки** | GHCR image · push archive · push rsync · server git. Перша, що підходить, перемагає. Деплой може чекати на CI або стартувати сам, коли CI зелений |
| 🧳 **Adopt і Convert** | Взяти під контроль проєкт, що вже працює, або перетворити стару папку на релізи: із планом, бекапом і undo |
| 🩺 **Здоров'я, алерти, аудит** | Історія health checks, алерти в Telegram, журнал аудиту, куди можна лише дописувати |
| 🤖 **MCP для ШІ** | 13 інструментів для Claude, Cursor і VS Code. За замовчуванням лише читання, `--allow-deploys` дозволяє деплой і відкат |

## 🚀 Швидкий старт

Потрібен лише Docker. deploy.me однаково працює на macOS, Linux і Windows і приносить власні
PostgreSQL, Valkey та HTTPS-edge, якщо у вас ще немає спільного стеку.

#### 🍎 macOS · 🐧 Linux

Потрібен Docker Desktop або OrbStack на macOS, чи Docker Engine із плагіном Compose на
Linux. Далі одна команда:

```bash
curl -fsSL https://raw.githubusercontent.com/KielD-01/deploy.me/main/install.sh | bash
```

Вона клонує deploy.me у `~/deploy.me` і запускає `bin/install`. Те саме вручну:

```bash
git clone https://github.com/KielD-01/deploy.me.git
cd deploy.me
bin/install
```

#### 🪟 Windows

Потрібні WSL2 і Docker Desktop з увімкненою інтеграцією WSL. Далі в PowerShell:

```powershell
irm https://raw.githubusercontent.com/KielD-01/deploy.me/main/bin/install.ps1 | iex
```

`bin/install` робить усе за один раз:

- перевіряє потрібні інструменти;
- описує вашу машину в `.env`;
- стягує готовий PHP-образ з Docker Hub (amd64 і arm64), тож нічого не компілюється, і запускає стек;
- додає локальний CA до довірених у браузерах;
- друкує одноразове посилання, щоб налаштувати passkey або пароль.

Особливості кожної ОС і розв'язання проблем описано в **[docs/install.md](docs/install.md)**.

Далі на **https://deployme.localhost**:

1. **Servers → Add server**: оберіть хост, довіртеся його ключу, вкажіть шлях для деплою.
2. **Projects → Import project**: оберіть репозиторій GitHub або локальну папку.
3. **Targets → Assign to server**, потім **Prepare**.
4. **Deploy** 🎉

> [!WARNING]
> Збережіть `APP_KEY` з `src/api/.env` у менеджері паролів. Без нього збережені токени й
> env-файли не розшифрувати.

## 🤖 Поговоріть із ним

```bash
claude mcp add deployme -- docker exec -i deployme-web php artisan deployme:mcp
```

> 💬 *«Що де задеплоєно і чи все здорове?»*
>
> 💬 *«Чому впав запуск #42? Покажи крок, що зламався.»*

Налаштування для Claude Desktop, Cursor і VS Code, а також усі інструменти описано в
**[docs/mcp.md](docs/mcp.md)**.

## 📚 Документація

- 📘 [Посібник користувача](docs/usage.md): від установки до відкатів, blue/green і бекапів
- 🤖 [MCP-сервер](docs/mcp.md): deploy.me для ШІ-агентів
- 🗂️ [CLAUDE.md](CLAUDE.md): детальна карта коду

## ❤️ Створено разом із Claude

deploy.me спроєктував і написав **[Роман Козін](https://github.com/KielD-01)** разом із
**[Claude](https://claude.ai)**, ШІ від Anthropic, у парі в
[Claude Code](https://claude.com/claude-code). Разом пройшли все: план, дизайн, движок
деплою з його bash-скриптами, тести, MCP-сервер і цей README, коміт за комітом. Рішення
ухвалював Роман, а більшість коду Claude писав разом із ним. 🤝

## 📄 Ліцензія

[MIT](LICENSE) © 2026 Роман Козін. Користуйтеся, форкайте, деплойте.

<p align="center"><sub>Зроблено в Україні 🇺🇦 з ☕, 🐳 і великою кількістю <code>ssh</code>.</sub></p>
