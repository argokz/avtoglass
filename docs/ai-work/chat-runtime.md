# Chat runtime — контрольная запись

Обновлено: 2026-09-27. Исполнитель: chat_runtime. Статус: реализация и тесты подготовлены, ожидают централизованного запуска root.

## Реализовано

- Постоянные ai_conversations / ai_messages / ai_turns; owner_key для actor либо guest hash; guest token зашифрован Eloquent cast, не попадает в UI.
- Livewire send сохраняет сообщение и ставит GenerateReply в отдельную durable очередь database/ai независимо default sync; sync/deferred/background/null отвергаются.
- Job повторно создаёт BuyerContext из actor_id или encrypted guest token, перечитывает account privileges, выставляет локаль и восстанавливает её; не читает auth/session.
- Chunk callback сохраняет настоящие дельты с ограничением частоты 200 ms, UI читает их polling. Нет имитации стрима.
- Conversation row lock сериализует enqueue/claim/cancel/write; active_turn_id запрещает параллельную генерацию и запоздалые записи.
- Retry сохраняет единственное пользовательское сообщение и стабильный operation_id для идемпотентности checkout, получает новый generation UUID для stale guard, attempt для порядка повторов.
- Queued deadline 120 s и running deadline 45 s; reconnect/poll переводит просроченную генерацию в failed даже при отсутствии worker. Job tries=1; PHP worker timeout=60.
- Карточки расчёта, согласие отдельно на карточку, явные confirm/refresh; сравнение показанного quote_id и серверного; submitted результат переживает reconnect.
- Результаты `search_products` и `prepare_order` сохраняются отдельно от видимого текста в `ai_tool_results`: до 9 записей на generation, до 8 предложений на поиск, ограниченные поля и 10 КБ на запись. Контакты и полный checkout не копируются. История следующего хода восстанавливает порядок `offer_id` и точную цену в minor units; подтверждённый номер заказа берётся из зашифрованного `ai_checkouts.result`.
- Контекст инструмента доступен модели только от завершённого хода. При retry записи старой failed/cancelled попытки удаляются; callback отменённого или опоздавшего worker запись не создаёт. Подготовка/подтверждение заказа по-прежнему повторно проверяются доменом, сохранённая цена поиска не служит подтверждением цены.
- Формат денег на карточке сохраняет ненулевые тиыны: 3335 minor → `33,35 ₸`, 4500 minor → `45 ₸`.
- RU/KK тексты, embedded/floating разметка; отключённый ИИ блокируется на каждом серверном действии, смена owner очищает предыдущую историю; неподходящая учётка получает безопасное уведомление.

## Проверки

- Новые AiChatTest покрывают async queue, отсутствие HTTP из send, reconnect, реальный chunk до завершения, cancel/retry/stale worker, отсутствие worker/worker timeout, безопасную ошибку, rate limit retry, sync misconfiguration, owner/account switch, encrypted token, fresh privileges, explicit locale, duplicate delivery.
- Добавлены проверки persistent tool context, удаления фактов при retry, блокировки stale callback, восстановления номера подтверждённого заказа и форматирования minor units.
- PHP lint, целевой Pint и `git diff --check` — PASS. БД-тесты ожидают централизованного guarded runner.
- БД-тесты не запускались исполнителем: root централизует guarded runner.

## Запуск

После миграций нужен отдельный supervised worker: `php artisan queue:work database --queue=ai --timeout=60 --tries=1`. При смене кода worker следует перезапустить. Нужен retry_after больше worker timeout (database default 90 s).

## Интеграция

- AssistantDialog callback contract согласован с providers.
- AssistantCheckout receives stable operation_id as turnId. pendingForTurns receives completed operation IDs. Catalog agent добавляет lifecycle validation через последний attempt.
- Карточки не создают реальные заказы автоматически; только пользовательская кнопка confirm может вызвать SubmitDraft.
- До центральных тестов остаётся проверить Blade-компиляцию, checkout UI regression и возможные пересечения owner scope.
