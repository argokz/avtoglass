# Этап 2, часть 4 — единый поиск, SubmitDraft и полный резерв

Дата: 22 сентября 2026 года. Основной план — [MODERNIZATION_SPEC.md](../../MODERNIZATION_SPEC.md). Предыдущая часть — [идемпотентность и API](12-idempotency-api.md).

## 1. Результат и границы

Добавлены:

- общий поиск предложений `SearchOffers` (уже существовал) и его подключение к витрине, оптовой таблице и `search_products` помощника;
- `PurchaseCommands::submitDraft` / `POST /api/v1/drafts/{draft}/submit` с операцией `draft.submit.v1`;
- полный атомарный резерв для автоматической розницы;
- таблица `outbox_events` и доставка `AnnounceNewOrder` после COMMIT.

API по-прежнему выключено по умолчанию (`ORDERING_API_ENABLED=false`). Автоподтверждение розницы отдельно выключено (`ORDERING_AUTO_CHECKOUT_ENABLED=false` / `ordering.auto_checkout_enabled`). Старый `CreateOrder` и частичный `StockLedger::reserve` не используются новым путём оформления.

## 2. SearchOffers

Вход: `BuyerContext` + фильтры (`q`, страница, тип стекла, поставщик, наличие, машина/опции).

Порядок матчинга:

1. точный нормализованный код (артикул / еврокод / OEM / internal);
2. иначе нечёткий `ilike` / `similarity` (после длины > 2);
3. иначе отбор по машине, если модель известна.

Цена — только `CustomerPriceCalculator::unitPrice` в виде `{amount_minor,currency}`. Закуп и скидочные поля наружу не отдаются. Применимость — через `CheckFitment`; попадание по артикулу само по себе не считается подтверждённым fitment.

Адаптеры:

| Поверхность | Файл | Как подключено |
|---|---|---|
| API | `OfferController` | прямой вызов |
| Витрина | `StorefrontSearch` | SearchOffers → уникальные `product_id` → пагинатор Product |
| Опт | `WholesaleTable` | SearchOffers → Offer модели в исходном порядке |
| Чат | `AssistantTools::searchProducts` | SearchOffers → прежняя форма ответа для модели |

`SessionBuyer` собирает `BuyerContext` из сессии/аккаунта для не-API адаптеров.

## 3. SubmitDraft

Вход: `quote_id`, `expected_version`, `action` ∈ {`confirm_retail`, `submit_for_review`}, заголовок `Idempotency-Key`.

Порядок блокировок: запись идемпотентности → черновик → предложения по возрастанию `id` → пересекающиеся наряды поста.

| Действие | Условие | Результат |
|---|---|---|
| `submit_for_review` | readiness ≠ `incomplete` | заказ `new`, без резерва, черновик `submitted`, quote `accepted`, outbox |
| `confirm_retail` | readiness ≠ `ready` или опт | `409 REVIEW_REQUIRED` — заказ не создаётся |
| `confirm_retail` | ready, но `auto_checkout_enabled=false` | `409 AUTO_CHECKOUT_DISABLED` |
| `confirm_retail` | ready + флаг включён | полный резерв всех строк или откат; заказ `confirmed` (или `scheduled` при слоте) |

Молчаливого превращения `confirm_retail` в заявку на согласование нет. Опт никогда не становится розницей.

При нехватке склада: `409 INSUFFICIENT_STOCK`, откат всей транзакции включая новый ключ идемпотентности, черновик остаётся `active`. Конкуренция за последнюю единицу под блокировкой предложений обычно даёт победителю заказ, а второму — `409 QUOTE_CHANGED` (доступное количество вошло в fingerprint расчёта) либо `INSUFFICIENT_STOCK`; черновик проигравшего остаётся `active`.

## 4. Outbox

Миграция `outbox_events`: uuid, тип события, агрегат, уникальный `event_id`, зашифрованный payload, статус `pending|processing|done|failed`, попытки, `available_at`.

После COMMIT ставится `ProcessOutboxEvent`. Команда `ordering:process-outbox` подхватывает застрявшие строки. Ошибка оповещения не откатывает заказ.

## 5. Как проверить

Только тестовая БД `avtoglasskz_test`, без параллельного прогона:

```powershell
scripts/test.ps1 -PhpPath C:\php\php.exe tests/Feature/Domain/SearchOffersTest.php tests/Feature/Domain/SubmitDraftTest.php tests/Feature/Domain/SubmitDraftConcurrencyTest.php
```

или:

```powershell
C:\php\php.exe -d memory_limit=512M vendor/phpunit/phpunit/phpunit tests/Feature/Domain/SearchOffersTest.php tests/Feature/Domain/SubmitDraftTest.php tests/Feature/Domain/SubmitDraftConcurrencyTest.php
```

## 6. EXPLAIN точного кода

На маленькой тестовой выборке PostgreSQL может выбрать Seq Scan — это допустимо. Индексы на нормализованный `offers.article` и коды товара считаются готовыми, когда план на заметном объёме использует Index Scan / Bitmap Index Scan по выражению:

```sql
upper(regexp_replace(coalesce(article, ''), '[\s.\-]+', '', 'g'))
```

Нагрузка порядка 50 000 предложений — этап 6 спецификации. Тест `test_exact_code_explain_uses_normalized_expression` фиксирует совпадение выражения с доменом.

## 7. Что ещё не сделано

- Включение `ORDERING_API_ENABLED` / `ORDERING_AUTO_CHECKOUT_ENABLED` в рабочей среде.
- Оплата и переход «зарезервировано → оплачено» в том же ядре.
- Перенос legacy-форм (`CreateOrder`, корзина) на `SubmitDraft`.
- Модель ресурсов/зон для автоматического выезда.
