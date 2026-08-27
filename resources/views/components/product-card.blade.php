@props(['product'])

<a href="{{ route('catalog.product', $product) }}"
   class="group relative flex flex-col overflow-hidden rounded-card border border-line bg-surface
          transition duration-200 hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-lift">

    <div class="relative aspect-4/3 overflow-hidden bg-sunken">
        {{-- Фото есть далеко не у всех: стёкла приходят из прайсов без картинок.
             Где его нет, рисуем схематичный значок типа стекла — он всё-таки
             говорит, лобовое это или боковое, в отличие от пустого места. --}}
        @if ($product->imageUrl())
            <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}"
                 loading="lazy" decoding="async"
                 class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
        @else
            <div class="grid h-full w-full place-items-center p-6 text-line-strong transition
                        duration-300 group-hover:scale-105 group-hover:text-brand/40">
                <x-glass-icon :type="$product->glass_type" class="h-full w-full max-h-24" />
            </div>
        @endif

        @if ($product->camera)
            {{-- ADAS помечаем прямо в списке: это меняет и цену работ, и срок --}}
            <span class="absolute top-2.5 left-2.5 rounded-pill bg-warn-soft px-2.5 py-1
                         text-[11px] leading-none font-semibold text-warn-ink">
                Камера ADAS
            </span>
        @endif

        @if ($product->in_stock)
            {{-- Наличие — первое, что решает: ждать неделю или забрать сегодня --}}
            <span class="absolute top-2.5 right-2.5 rounded-pill bg-brand px-2.5 py-1
                         text-[11px] leading-none font-semibold text-on-brand">
                В наличии
            </span>
        @endif

        @if (($product->offers_count ?? 0) > 1)
            <span class="absolute right-2.5 bottom-2.5 rounded-pill bg-surface/90 px-2.5 py-1
                         text-[11px] leading-none font-semibold text-muted backdrop-blur">
                {{ $product->offers_count }} предложения
            </span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-3.5 sm:p-4">
        <h3 class="line-clamp-3 text-sm leading-snug font-semibold text-ink transition group-hover:text-brand">
            {{ $product->name }}
        </h3>

        @if ($options = $product->activeOptions())
            <p class="mt-1.5 line-clamp-2 text-xs leading-relaxed text-faint">
                {{ implode(' · ', $options) }}
            </p>
        @endif

        <div class="mt-auto pt-3">
            @if ($product->price_min)
                <div class="font-display text-lg leading-none font-extrabold tracking-tight">
                    {{ number_format((float) $product->price_min, 0, ',', ' ') }} ₸
                </div>
                @if ($product->price_max && $product->price_max > $product->price_min)
                    <div class="mt-1 text-xs text-faint">
                        до {{ number_format((float) $product->price_max, 0, ',', ' ') }} ₸
                    </div>
                @endif
            @else
                <div class="text-sm font-medium text-faint">Цена по запросу</div>
            @endif
        </div>
    </div>
</a>
