# Каталог и безопасное оформление: журнал субагента

Обновлено: 2026-09-27. Исполнитель: catalog_ordering. AGENTS.md прочитан; рабочий `.env` не менялся.

## Реализовано

- `AssistantTools` передаёт структурированные параметры автомобиля в общий SearchOffers: модель, марка, год, поколение, кузов, двери, известные опции. Ошибочные опции возвращают исправимую ошибку инструмента.
- Результаты сохраняют каждое предложение (`offer_id`), производителя, точные целые minor units и десятичную строку KZT, применимость, недостающие уточнения. Нет схлопывания по товару и запроса Product внутри цикла. Ссылки используют slug из общего поиска. Возвращаются доступные сервисные пункты и разрешённая ядром модель автомобиля.
- Инструмент `create_order` удалён. `prepare_order` создаёт общий черновик и QuoteDraft через PurchaseCommands. Подготовка не создаёт заказ, резерв или callback. `privacy_confirmed` из модели не считается согласием.
- `AssistantCheckout` хранит зашифрованные снимок/результат в новой `ai_checkouts`. На owner + operation_id приходится одна карточка. Подготовка, создание черновика и quote атомарны. Повтор аргументов возвращает ту же карточку; изменение требует нового сообщения. Неполная подготовка откатывается и может быть исправлена в том же сообщении.
- UI подтверждает именно показанный quote ID, сервер определяет action/version/quote. Общий SubmitDraft заново проверяет цену, остатки и применимость. Повтор подтверждения возвращает сохранённый результат без второго заказа. Source нового заказа атомарно меняется на Assistant.
- Отдельный `refresh` пересчитывает условия; новая цена никогда не подтверждается автоматически. Старая вкладка с предыдущим quote ID получает CHECKOUT_CHANGED.
- Поддержан подбор без найденного предложения через unresolved draft item. В общие CalculateDraft/SubmitDraft добавлено сохранение reference и комментария для менеджера; иначе заявка теряла описание искомого стекла.
- Учитывается runtime lifecycle: реальный generation ID преобразуется в стабильный operation_id под блокировкой беседы. Отменённый/устаревший воркер не может подготовить карточку. Подтверждать и обновлять можно только карточку завершённой последней попытки; карточки cancelled/failed скрыты даже в сервисе. Повтор генерации сохраняет исходную карточку.
- `prepare_order` объявляет и принимает `slot_start`/`slot_end` для установки и выезда, передаёт их в общий черновик в ISO 8601 с часовым поясом. Без слота доменный расчёт остаётся `incomplete`; выезд с указанным слотом всё равно требует проверки ресурса менеджером.

## Контракт с runtime

`AssistantTools::call(string $name, array $args, ?BuyerContext $buyer = null, ?string $turnId = null): array`

- `$turnId` при prepare — **реальный UUID поколения** из ai_turns, не operation_id. Сервис сам получает operation_id, сохраняемый в ai_checkouts.turn_id.
- `prepare_order` возвращает `{ok, checkout, note}`; ошибка — `{error, code, details}`.
- `AssistantCheckout::pendingForTurns(BuyerContext, array $operationIds): array` возвращает только карточки completed последней попытки; включает submitted для восстановления результата.
- `AssistantCheckout::confirm(BuyerContext, string $checkoutId, bool $privacyConsent, string $expectedQuoteId): array` возвращает доменный результат с order_number/path/status.
- `AssistantCheckout::refresh(BuyerContext, string $checkoutId): array` возвращает обновлённую карточку; необходим новый явный клик подтверждения.
- Карточка содержит id, turn_id (operation_id), status, quote_id, draft_id, draft_version, readiness/reasons/issues, items, contacts, vehicle, fulfillment, total/known_total, expires_at, action, result.
- При явном BuyerContext нет обращения к session/auth. Город берётся из args.city, иначе из settings()->defaultCity().

## Проверки

- PHP syntax: AssistantTools, AssistantCheckout, AssistantCheckoutTest, AssistantToolsTest — PASS.
- Pint на своих PHP-файлах, миграции, CalculateDraft/SubmitDraft и тестах — PASS после исправления форматирования.
- Подготовлены `tests/Feature/Ai/AssistantToolsTest.php` (3 сценария) и `AssistantCheckoutTest.php` (8 сценариев).
- Сценарии: разные предложения одного товара, точные деньги/N+1, неизвестная/несовместимая камера, запрет старого create_order, идемпотентность, чужой owner, обязательный UI consent, подбор без offer, изменение цены/соседняя вкладка, истечение quote, совместимость для auto checkout, исправление неполной подготовки, cancel+retry+запоздалый воркер, изменение аргументов и чужой turn.
- Целевой защищённый запуск `./scripts/test.ps1 --filter AssistantCheckoutTest`: 10/10 тестов, 63 assertions, PASS. Новые сценарии проверяют слот установки (включая исправление неполной подготовки) и выезда. Pint и PHP syntax для изменённых PHP-файлов — PASS.

## Передано основному агенту

- Изменения SearchOffers (глобальное ранжирование и разрешённый vehicle) / CheckFitment завершает root. Субагент в SearchOffers добавлял только product_slug, далее не редактирует.
- Миграция `2026_09_26_120100_create_ai_checkouts_table.php` нужна вместе с runtime migration.
- Ограничение: одна неизменяемая подготовленная карточка на пользовательскую операцию. Новый выбор/контакты требуют нового сообщения; refresh меняет только доменный расчёт существующего черновика.
- Требуется централизованная интеграционная проверка и browser QA карточки подтверждения.
