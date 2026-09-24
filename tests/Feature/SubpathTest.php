<?php

namespace Tests\Feature;

use App\Models\CarBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сайт в подпути домена: http://localhost/avto-glass.
 *
 * Laravel строит ссылки от корня и о префиксе не знает — route() отдал бы
 * /catalog вместо /avto-glass/catalog, а @vite искал бы стили по адресу,
 * которого нет. Выглядит это как страница без оформления со ссылками
 * в никуда, и причину по внешнему виду не угадать.
 *
 * Проверяем то, что уходит в браузер: url() внутри теста показывает
 * состояние генератора после запроса, а не то, что увидел покупатель.
 */
class SubpathTest extends TestCase
{
    use RefreshDatabase;

    /** Ссылки в разметке идут с префиксом. */
    public function test_ссылки_получают_префикс(): void
    {
        $this->get('/', ['X-Forwarded-Prefix' => '/avto-glass'])
            ->assertOk()
            ->assertSee('/avto-glass/catalog', false);
    }

    /** Стили тоже: без префикса страница открывается голой. */
    public function test_адреса_стилей_получают_префикс(): void
    {
        $this->get('/', ['X-Forwarded-Prefix' => '/avto-glass'])
            ->assertOk()
            ->assertSee('/avto-glass/build/', false);
    }

    /** Слеши по краям заголовка не должны удваиваться в адресе. */
    public function test_лишние_слеши_в_заголовке_не_ломают_адрес(): void
    {
        $ответ = $this->get('/', ['X-Forwarded-Prefix' => '/avto-glass/'])->assertOk();

        $ответ->assertSee('/avto-glass/catalog', false);
        $this->assertStringNotContainsString('//avto-glass', $ответ->getContent());
    }

    /** Без заголовка сайт работает как обычно, от корня. */
    public function test_без_заголовка_ничего_не_меняется(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('/avto-glass', false);
    }

    /**
     * Заголовок с постороннего адреса игнорируем. Иначе любой запрос извне
     * переписывает корень сайта — и ссылка на сброс пароля уходит туда,
     * куда указал отправитель запроса.
     */
    public function test_заголовок_с_чужого_адреса_не_действует(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/', ['X-Forwarded-Prefix' => 'evil.example.com'])
            ->assertOk()
            ->assertDontSee('evil.example.com', false);
    }

    public function test_пустой_заголовок_игнорируется(): void
    {
        $this->get('/', ['X-Forwarded-Prefix' => ''])
            ->assertOk()
            ->assertSee('href="'.url('/catalog').'"', false);
    }

    /** Внутренние страницы тоже: префикс держится на всём сайте. */
    public function test_префикс_держится_на_внутренних_страницах(): void
    {
        $this->get('/catalog', ['X-Forwarded-Prefix' => '/avto-glass'])
            ->assertOk()
            ->assertSee('/avto-glass/', false);
    }

    /** С https-прокси ссылки не должны остаться http — иначе стили не грузятся. */
    public function test_https_прокси_отдаёт_https_ссылки(): void
    {
        $this->get('/', [
            'X-Forwarded-Prefix' => '/avto-glass',
            'X-Forwarded-Proto' => 'https',
        ])
            ->assertOk()
            ->assertSee('https://localhost:8014/avto-glass', false)
            ->assertDontSee('http://localhost:8014/avto-glass', false);
    }

    /**
     * Storage::url() смотрит в APP_URL (порт 8014), а не в текущий корень.
     * С витрины на itwin.kz браузер ходил бы на localhost покупателя.
     */
    public function test_логотипы_идут_через_префикс_а_не_внутренний_порт(): void
    {
        CarBrand::factory()->visible()->create([
            'name' => 'Toyota',
            'slug' => 'toyota',
            'logo_path' => 'brands/toyota.png',
        ]);

        $this->get('/', [
            'X-Forwarded-Prefix' => '/avto-glass',
            'X-Forwarded-Proto' => 'https',
        ])
            ->assertOk()
            ->assertSee('/avto-glass/storage/brands/toyota.png', false)
            ->assertDontSee('http://localhost:8014/storage', false);
    }

    /**
     * @fonts вставляет CSS с url("/build/assets/..."). Без подмены
     * браузер просит шрифт с корня домена, где витрины нет.
     */
    public function test_шрифты_в_css_идут_через_префикс(): void
    {
        $this->get('/', [
            'X-Forwarded-Prefix' => '/avto-glass',
            'X-Forwarded-Proto' => 'https',
        ])
            ->assertOk()
            ->assertSee('url("/avto-glass/build/assets/', false)
            ->assertDontSee('url("/build/assets/', false);
    }

    /** Livewire-чат ходит на /avto-glass/livewire-…, иначе JS не грузится и Enter делает GET на /?. */
    public function test_livewire_скрипт_идёт_через_префикс(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.keys' => ['test-key']]);

        $this->get('/chat', ['X-Forwarded-Prefix' => '/avto-glass'])
            ->assertOk()
            ->assertSee('/avto-glass/livewire-', false)
            ->assertDontSee('src="/livewire-', false);
    }
}
