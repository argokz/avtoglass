<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Русский перевод Filament отстаёт от английского: часть ключей в пакете
 * просто отсутствует. Пока фоллбэк стоял на ru, такой ключ печатался сырым —
 * менеджер видел в интерфейсе filament::components/loading-section.label.
 * Эти проверки держат оба предохранителя: свои переводы и аварийный фоллбэк.
 */
class LocalizationTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function ключи(): array
    {
        $keys = [
            'filament::components/loading-section.label',
            'filament::components/badge.actions.delete.label',
            'filament::components/section.actions.collapse.label',
            'filament::components/tabs.label',
            'filament::components/breadcrumbs.label',
            'filament-panels::layout.skip_to_content.label',
            'filament-panels::layout.actions.theme_switcher.label',
            'filament-panels::layout.navigation.label',
            'filament-panels::layout.topbar.label',
            'filament-tables::table.loading',
            'filament-tables::table.columns.icon.boolean.true',
            'filament-tables::table.actions.reorder_record.label',
            'filament-forms::components.tags_input.tag_added',
            'filament-forms::components.file_upload.actions.download.label',
            'filament-schemas::components.callout.statuses.warning',
            'filament-schemas::components.wizard.header.step.statuses.completed',
            'filament-infolists::components.entries.icon.true',
            'filament-notifications::notification.actions.close.label',
            'filament-notifications::database.modal.unread_label',
            'filament-query-builder::query-builder.max_rules_reached_tooltip',
        ];

        return array_combine($keys, array_map(fn ($k) => [$k], $keys));
    }

    #[DataProvider('ключи')]
    public function test_ключ_перевода_не_печатается_сырым(string $key): void
    {
        $this->assertNotSame(
            $key,
            __($key),
            "Ключ $key показывается пользователю как есть — нужен перевод в lang/vendor."
        );
    }

    /**
     * Фоллбэк на ru означает «показывать ключ», потому что искать перевод
     * больше негде. Английский текст в редком углу интерфейса — неприятно,
     * сырой ключ — сломано.
     */
    public function test_аварийный_фоллбэк_не_русский(): void
    {
        $this->assertNotSame('ru', config('app.fallback_locale'));
    }

    /** Сам интерфейс при этом остаётся русским. */
    public function test_интерфейс_по_умолчанию_русский(): void
    {
        $this->assertSame('ru', config('app.locale'));
    }
}
