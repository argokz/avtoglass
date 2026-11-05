# AvtoGlass — магазин автостёкол

Laravel / Livewire / Filament, PostgreSQL, Blade и Tailwind. Модернизация магазина, оптовой закупки и отдельного ИИ-чата выполняется поэтапно.

Этап 0 и функциональная приёмка прототипов этапа 1 завершены. Доступны покупка, согласование, CSV/XLSX, повтор и изменение заказов, вход по демо-коду, история, профиль, адреса, шаблоны, доплата/возврат и отдельный чат с общим подбором. Добавлены выбор темы, мобильное основное действие, фокус ошибок и автосохранение. Проверки части 9: 113 целевых PHP-тестов / 784 assertions и 28 браузерных сценариев успешно. Новые интерфейсы используют демонстрационные данные в сессии; настоящая модель и общее ядро заказа ещё не подключены. Ручная проверка диктором, реальным телефоном и другими движками браузера остаётся обязательством перед выпуском. Полный PHP-прогон 893 / 1854 относится к навигации от 16 сентября.

Этап 2 в работе: в `app/Domain` реализованы `Money`, постоянные черновики и сохраняемый расчёт с отдельной версией и сроком действия. Расчёт учитывает персональные цены, резервы, применимость, точку/тариф, услуги и занятость поста. Добавлены идемпотентные команды и session/CSRF API черновика: повторы возвращают исходный результат, конфликтующие изменения отклоняются, права проверяются сервером. Добавлены единый `SearchOffers` (витрина/опт/чат), `SubmitDraft` с полным резервом и `outbox_events` для оповещений после COMMIT. Автоподтверждение розницы и API выключены по умолчанию (`ORDERING_API_ENABLED=false`, `ORDERING_AUTO_CHECKOUT_ENABLED=false`). Автоматический выезд ещё требует модели ресурсов/зон. Новые миграции проверялись только в отдельной тестовой БД.

## Документы

- [Полная спецификация и критерии приёмки](MODERNIZATION_SPEC.md).
- [Проверенная исходная точка](docs/modernization/00-baseline.md).
- [Навигация и первая часть интерфейса](docs/modernization/01-navigation.md).
- [Прототипы розницы, опта, повторения и изменения заказов](docs/modernization/02-purchase-prototypes.md).
- [Предложения менеджера и проверка оптового списка](docs/modernization/03-review-prototypes.md).
- [CSV/XLSX, отчёт проверки и повтор с удалёнными товарами](docs/modernization/04-file-import-repeat.md).
- [Вход по коду и история заказов](docs/modernization/05-cabinet-history.md).
- [Профиль, адреса, шаблоны и восстановление редакции](docs/modernization/06-profile-templates.md).
- [Замены, получение, доплата и возврат](docs/modernization/07-proposal-settlement.md).
- [Чат, общий подбор и явное оформление](docs/modernization/08-assistant-prototype.md).
- [Тема, мобильное действие и приёмка прототипов](docs/modernization/09-ux-completion.md).
- [Backend: точные суммы и постоянные черновики](docs/modernization/10-order-core-foundation.md).
- [Backend: версии расчёта, применимость и получение](docs/modernization/11-checkout-quotes.md).
- [Backend: идемпотентность, API и инструкции подключения](docs/modernization/12-idempotency-api.md).
- [Backend: единый поиск, SubmitDraft, резерв и outbox](docs/modernization/13-search-offers.md).
- `PLAN.md` — исторический план; его отметки не заменяют текущие проверки.

## Требования

| Компонент | Требование / проверенная версия |
|---|---|
| PHP | 8.4.1+; проверено на 8.4.23 |
| Composer | 2.x, установка из composer.lock |
| PHP extensions | pdo_pgsql, mbstring, intl, curl, zip, dom, xmlreader, xmlwriter, fileinfo, openssl |
| PostgreSQL | Проверено на 16.6; тесты используют PostgreSQL |
| Node | Требование Vite: ^20.19.0 или >=22.12.0; проверено 22.14.0 |
| npm | Проверено 11.2.0; установка из package-lock.json |
| Браузерные проверки | Chromium через Playwright |

Redis для начального preview не требуется: используется database cache/queue. Production-очереди и Redis предусмотрены следующими этапами. Экземпляр Redis другого проекта не использовать для тестовых очисток.

## Установка

Команды выполнять из корня проекта. На проверенной Windows-машине PHP вне PATH: `C:\php\php.exe`, Composer: `C:\php\composer.phar`.

```powershell
& C:/php/php.exe C:/php/composer.phar install --no-interaction
npm ci --ignore-scripts
& C:/php/php.exe C:/php/composer.phar check-platform-reqs
& C:/php/php.exe C:/php/composer.phar validate --no-check-publish
npm run build
```

Если PHP и Composer в PATH, использовать обычные `composer install`, `composer check-platform-reqs` и `composer validate`. Не обновлять зависимости только ради запуска.

Для нового клона создать `.env` по `.env.example`, если файла ещё нет, и указать локальное подключение PostgreSQL. Существующий `.env` и ключ работающего приложения сохранять. Preview создаёт собственный APP_KEY. Пароли и API-ключи не добавлять в Git.

`composer setup` содержит миграцию текущей БД и генерацию ключа. Для изолированного стенда использовать приведённые отдельные команды.

## Разделение баз

| Назначение | Имя | Использование |
|---|---|---|
| Рабочие данные | avtoglasskz в существующем окружении | Не используется тестами и preview |
| PHP-тесты | avtoglasskz_test | Runner пересоздаёт схему |
| UI-preview | avtoglasskz_modernization_preview | Синтетические товары, покупатели и заказы |

Если тестовая БД отсутствует, один раз создать её штатной утилитой PostgreSQL:

```powershell
createdb -h 127.0.0.1 -p 5440 -U postgres avtoglasskz_test
```

Пароль вводить по запросу PostgreSQL или через локальную конфигурацию. На другой машине порт может отличаться. DB_HOST, DB_PORT и DB_USERNAME задавать переменными окружения до запуска runner — иначе используются значения phpunit.xml. DB_PASSWORD можно задать окружением, игнорируемым `.env.testing` или локальным `.env`. Имя БД и драйвер принудительно зафиксированы в phpunit.xml; dotenv не переопределяет уже установленные runner переменные.

`TestDatabaseGuard` до RefreshDatabase проверяет testing, PostgreSQL, имя тестовой базы и реальный current_database(). Разрешён также суффикс Laravel parallel testing `_test_N`. DB_URL, ключи Gemini, mail, queue, cache и session изолированы. Незамоканные Laravel HTTP-запросы отклоняются. Тестовый APP_KEY фиксирован только для testing; конфигурационный кеш отделён от рабочего.

## PHP-тесты

```powershell
& C:/php/php.exe scripts/doctor.php --testing
./scripts/test.ps1
./scripts/test.ps1 --filter StorefrontModeTest
```

Если PHP в другом месте:

```powershell
./scripts/test.ps1 -PhpPath D:/runtime/php/php.exe --filter CartTest
```

Переносимый запуск без PowerShell:

```sh
php scripts/doctor.php --testing
php -d memory_limit=512M vendor/phpunit/phpunit/phpunit
composer test
```

Полный набор требует 512 MB памяти; первоначальный запуск с 128 MB исчерпал память. Тесты против рабочей базы запрещены. Не запускать два runner одновременно против одной тестовой БД. Ручной migrate:fresh не требуется.

## Изолированный preview

```powershell
& C:/php/php.exe scripts/prepare-preview.php
& C:/php/php.exe artisan migrate --env=preview --no-interaction
& C:/php/php.exe artisan db:seed --class=PreviewSeeder --env=preview --no-interaction
npm run build
& C:/php/php.exe -d memory_limit=512M artisan serve --env=preview --host=127.0.0.1 --port=8015 --no-reload
```

Открыть [магазин](http://127.0.0.1:8015), [чат](http://127.0.0.1:8015/chat) и [прототип покупки](http://127.0.0.1:8015/preview/ux). Сервер работает, пока открыт процесс последней команды. Обычный artisan serve без --env=preview использует другое окружение.

В основном чате доступны история, создание отдельного диалога и удаление старого. Для этой версии нужна миграция `2026_10_09_100000_allow_multiple_ai_conversations`; она сохраняет прежние переписки. После обновления кода перезапустить работника очереди `ai`, чтобы он загрузил новую логику проверки поиска и подготовки заявки. Поиск исключает известные несовпадения года/комплектации, а непроверенную применимость передаёт менеджеру. Цены и артикулы показываются отдельно в карточках каталога.

Ответы основного `/chat` теперь формирует отдельная очередь. Для проверки чата запустить во втором терминале:

```powershell
& C:/php/php.exe artisan queue:work database --queue=ai --timeout=60 --tries=1 --env=preview
```

В preview Gemini подменён локальным ответом; сетевой ключ не используется. Браузерные тесты основного чата требуют запущенного работника. Если очередь не работает, интерфейс покажет ожидание, а просроченный запрос можно повторить.

Для обычного окружения настроить `AI_PROVIDER_MODE=gemini_only` и ключ Gemini в `.env` (бесплатный проект без подключённого биллинга), либо `local_only` с разрешённым `qwen-chat` через локальный LiteLLM. Доступность чата и разрешённые модели определяются `config/ai.php`. Перед открытием нового чата проверить `php artisan migrate:status` и применить ожидающие миграции штатным `php artisan migrate --force`: нужны `ai_conversations`, `ai_messages`, `ai_turns`, `ai_tool_results`, `ai_checkouts`. Работник `queue:work` должен слушать отдельную очередь `ai`; `composer dev` запускает его для стандартного database-подключения. Для постоянно доступного сайта запускать работника под менеджером процессов, чтобы он восстанавливался после перезапуска. Параметры и результаты проверки локальной модели записаны в [плане ИИ](docs/AI-ASSISTANT-IMPLEMENTATION.md). Существующий `.env` и ключ приложения сохранять.

Прототип покупки доступен только в preview. Он использует собственные демонстрационные данные и сессионный подбор, не создаёт реальные заказы. Переключатель RU/KK и панель состояний позволяют проверить оформление, оптовый список, загрузку CSV/XLSX, ошибки, повторение с удалёнными строками и запрос изменения.

В [новом прототипе чата](http://127.0.0.1:8015/preview/ux?view=chat) доступны диалоги, выбор из общих карточек, переход в магазин с сохранением подбора и общая форма заказа. Ответы сценарные, на примере Camry 70. Это отдельный preview-экран; существующий `/chat` пока не заменён.

В [истории прототипа](http://127.0.0.1:8015/preview/ux?view=orders) номер `+77000000001` открывает 25 примеров заказов. Код показан в интерфейсе для проверки; SMS не отправляется. Новые заказы и изменения сохраняются в текущей сессии. Это не вход в реальный клиентский аккаунт.

Подготовка:

1. Читает локальные реквизиты PostgreSQL из .env; подключения с DB_URL отклоняет.
2. Создаёт отдельную preview-БД, если её нет; существующие базы не очищает.
3. Создаёт .env.preview, если файл отсутствует: отдельные ключ, cookie сессии и конфигурационный кеш.
4. Seeder добавляет три предложения Camry, услуги, пункт получения, розничного и одобренного оптового клиента, по одному демонстрационному заказу.
5. Пароли записываются в `.codex/modernization/preview-access.json`. Файл приватный, игнорируется Git. Повторный seed не пересоздаёт существующие демонстрационные записи.

Preview использует фиктивный ответ Gemini и отключённые внешние уведомления/платёжные функции. Реальное качество ИИ, SMS и банки здесь не проверяются. Laravel HTTP-вызовы без fake отклоняются. Рабочие интеграции в preview не включать.

Если файл доступа потерян, seeder не восстанавливает пароль автоматически: восстановить локальный файл из своей копии либо отдельно назначить новый пароль только демонстрационным аккаунтам.

## Браузерные проверки

Сначала запустить preview, затем в другом терминале:

```powershell
npx playwright install chromium
npm run test:e2e
```

Тесты используют loopback:8015, синтетических клиентов и две конфигурации Chromium: desktop 1365×900 и Pixel 7. Другой хост/порт отклоняются. Для выборочного запуска в PowerShell использовать прямой runner: так npm не поглощает опции.

```powershell
node node_modules/@playwright/test/cli.js test --project=mobile --grep 'switching modes'
node node_modules/@playwright/test/cli.js test tests/Browser/purchase-preview.spec.js
node node_modules/@playwright/test/cli.js test tests/Browser/review-preview.spec.js
node node_modules/@playwright/test/cli.js test tests/Browser/file-repeat-preview.spec.js
node node_modules/@playwright/test/cli.js test tests/Browser/cabinet-preview.spec.js
node node_modules/@playwright/test/cli.js test tests/Browser/preferences-preview.spec.js
node node_modules/@playwright/test/cli.js test tests/Browser/settlement-preview.spec.js
```

Сценарии: магазин, каталог, товар, чат, корзина, кабинет, повтор оптовой заявки, переключение режимов с корзиной/диалогом и Enter/Shift+Enter. Отдельный набор прототипа проверяет розницу, казахский опт, ошибки, сохранение подбора и работу с заказом. Mobile Chromium не заменяет Safari/iPhone.

Артефакты:

- HTML: `playwright-report/index.html`; открыть `npm run test:e2e:report`.
- Скриншоты: `.codex/modernization/screenshots/current/{desktop,mobile}/`.
- Прототип покупки: `.codex/modernization/screenshots/02-purchase-prototypes/{desktop,mobile}/`.
- Согласование и список: `.codex/modernization/screenshots/03-review-prototypes/{desktop,mobile}/`.
- Файлы и повтор: `.codex/modernization/screenshots/04-file-repeat/{desktop,mobile}/`.
- Кабинет и история: `.codex/modernization/screenshots/05-cabinet/{desktop,mobile}/`.
- Профиль, шаблоны и редакция: `.codex/modernization/screenshots/06-preferences/{desktop,mobile,extra}/`.
- Предложения и расчёты: `.codex/modernization/screenshots/07-settlement/{desktop,mobile,extra}/`.
- Ошибки и traces: `test-results/`.
- Переменная CAPTURE_STAGE задаёт отдельный каталог снимков, сохраняя предыдущий этап.

## Начальные замеры

```powershell
& C:/php/php.exe -d memory_limit=512M scripts/measure-preview.php
```

Только preview-БД: по шесть обработок пяти HTTP-маршрутов, первая исключается. Выводятся время Kernel::handle, число SQL и суммарное время SQL. Это серверный код в одном прогретом процессе, без сети, браузера и конкуренции. Измерение не подтверждает Web Vitals, p95/p99 или нагрузку на 50 000 предложений.

## Продолжение разработки

1. Прочитать актуальные требования этапа в спецификации.
2. Проверить git status; сохранять незакоммиченные изменения владельца.
3. Выполнять миграции только в явно выбранной изолированной среде.
4. Расчёт и правила заказа реализовывать в общем backend.
5. Проверить затронутые сценарии, сборку и браузер; записать результаты.
6. Обновить документы, отмечая только проверенную работу.

Сохраняемые черновики, история, память помощника и покупка через общее ядро реализованы с отдельными флагами. Production-переключение и внешние интеграции требуют приёмки. Подробности: [реализация и выпуск помощника](docs/ai-work/purchase-journey.md).

## Проверка помощника и покупки

Три миграции `2026_10_18_100000`–`2026_10_19_100200` добавляют состояние подбора, память, версии предложений, шифрование прежней истории и явные права на перенесённые гостевые заказы. Сохранять существующий APP_KEY; перед миграцией существующих данных сделать резервную копию.

`ORDERING_STOREFRONT_CORE` связывает каталог, корзину и чат с черновиком. `ORDERING_AUTO_CHECKOUT_ENABLED` и `ORDERING_WHOLESALE_AUTO_CHECKOUT_ENABLED` включают автоматическое подтверждение независимо. По умолчанию они выключены. Онлайн-оплата нового пути доступна только для драйверов из `PAYMENTS_VERIFIED_DRIVERS`, проверенных на конкретном merchant sandbox. Текущий Kaspi-адаптер в новый автоматический путь не допускается до реализации проверенного callback-контракта.

```powershell
./scripts/test.ps1 tests/Feature/Ai/AssistantJourneyTest.php
& C:/php/php.exe scripts/ai/evaluate-journey.php
& C:/php/php.exe scripts/ai/evaluate-journey.php --run
# Настоящий провайдер, синтетические диалоги, только изолированная preview-БД:
& C:/php/php.exe scripts/ai/evaluate-journey.php --live --complete
```

Первая команда оценки только экспортирует фиксированные 200 диалогов (100 RU / 100 KK), без сети. `--run` использует preview-заглушки и отдельный кеш. `--live` читает настроенный провайдер и ключи локального окружения, проверяет разрешённые хосты и ограничивает физические запросы; ключи в отчёт не попадают. По умолчанию четыре последовательных сбоя останавливают проверку. `--complete` сохраняет сбои и продолжает до конца фиксированной выборки в пределах бюджета. `--only=year-only` запускает отдельную группу, `--limit=4` — ограниченный smoke. Файлы находятся в игнорируемом `output/assistant-evaluation/`. Ни завершение job, ни прогон с заглушкой не заменяют ручную оценку качества RU/KK и измерение доставки результата браузеру.

Чтобы сохранить отдельный прогон, добавьте `--tag=20261023`. Аудит фактов и подготовка независимой оценки выполняются без сети и БД:

```powershell
& C:/php/php.exe scripts/ai/audit-journey.php --results=output/assistant-evaluation/live-complete-results.json
# После заполнения экспортированной HTML-формы:
& C:/php/php.exe scripts/ai/audit-journey.php --results=output/assistant-evaluation/live-complete-results.json --reviews=path/to/journey-review.json
```

Аудитор проверяет полный набор и исходные сообщения, сохранённые факты, различие `false`/неизвестно, строки закупки, отсутствие текстовой покупки и лимиты задания. `--check` возвращает ненулевой код при провале этих проверок. Рядом создаются `*-audit.json`, пустой `*-review-template.json` и `*-review.html`. В HTML оцените смысл и язык каждого ответа, карточки и критические ошибки; экспортируйте JSON. Незавершённые оценки, другой состав сценариев и файл от другого SHA-256 прогона отклоняются. Автоматические проверки не выставляют балл качества текста и не разрешают выпуск. Артефакты содержат данные диалогов и остаются приватными в игнорируемом `output/`.

Scheduler обслуживает минутное освобождение резерва, ежедневные `assistant:prune` / `pdn:anonymize` и часовую очистку idempotency payload. Для предварительного просмотра удаления: `php artisan assistant:prune --dry-run`.
