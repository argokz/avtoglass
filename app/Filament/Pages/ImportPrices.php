<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\OnlyAdmins;
use App\Models\PriceImportRun;
use App\Services\PriceList\PriceListImporter;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use UnitEnum;

/**
 * Загрузка прайса из CRM.
 *
 * Менеджеру не нужен SSH: файл с поставщика кладётся сюда, разбирается
 * в стейджинг, и уже отсюда можно собрать каталог. Случайные остатки
 * catalog:stock сюда не относятся.
 */
class ImportPrices extends Page implements HasForms
{
    use InteractsWithForms;
    use OnlyAdmins;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Импорт прайса';

    protected static ?string $title = 'Импорт прайса';

    protected static UnitEnum|string|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.import-prices';

    /** @var array<string,mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Файл поставщика')
                    ->description('Excel как в 1С: наименование, единица, цена, артикул. Каталог сам не перезапишется, пока не нажмёте «Собрать каталог».')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Прайс (.xlsx)')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->disk('local')
                            ->directory('prices/uploads')
                            ->visibility('private')
                            ->required(),
                        TextInput::make('source')
                            ->label('Откуда файл')
                            ->placeholder('1С, КМК, 28.08.2026')
                            ->maxLength(120),
                    ]),
            ])
            ->statePath('data');
    }

    public function import(PriceListImporter $importer): void
    {
        $data = $this->form->getState();
        $relative = $this->storedPath($data['file'] ?? null);

        if (! $relative) {
            Notification::make()->title('Выберите файл прайса')->danger()->send();

            return;
        }

        $path = Storage::disk('local')->path($relative);

        try {
            $run = $importer->import(
                path: $path,
                source: filled($data['source'] ?? null) ? (string) $data['source'] : 'CRM',
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            Notification::make()->title('Импорт не удался')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->form->fill();

        Notification::make()
            ->title("Импорт #{$run->id}: {$run->rows_total} строк")
            ->body("Разобрано {$run->rows_parsed}, на проверку {$run->rows_review}")
            ->success()
            ->send();
    }

    public function build(int $runId): void
    {
        $run = PriceImportRun::find($runId);

        if (! $run || $run->status === 'failed') {
            Notification::make()->title('Этот импорт нельзя собрать в каталог')->danger()->send();

            return;
        }

        $code = Artisan::call('catalog:build', ['run' => $runId]);

        if ($code !== 0) {
            Notification::make()
                ->title('Сборка каталога не удалась')
                ->body(Artisan::output())
                ->danger()
                ->send();

            return;
        }

        $run->update(['status' => 'applied']);

        Notification::make()
            ->title("Каталог собран из импорта #{$runId}")
            ->success()
            ->send();
    }

    /** @return Collection<int, PriceImportRun> */
    public function runs(): Collection
    {
        return PriceImportRun::query()->latest('id')->limit(20)->get();
    }

    private function storedPath(mixed $file): ?string
    {
        if (is_array($file)) {
            $file = $file[0] ?? null;
        }

        $path = is_string($file) ? $file : null;

        return filled($path) ? $path : null;
    }
}
