@props(['product'])

@php
    $покупатель = текущийПокупатель();
    $опт = $покупатель?->seesWholesalePrices() === true;
    $ценаОт = $product->sellingPriceMin($покупатель);
    $ценаДо = $product->sellingPriceMax($покупатель);
@endphp

<article class="product-card card card-hover group relative flex flex-col overflow-hidden">
    <a href="{{ route('catalog.product', $product) }}" class="flex flex-1 flex-col">
        <div class="relative aspect-4/3 overflow-hidden bg-sunken">
            @if ($product->image_path)
                <x-picture :path="$product->image_path" :alt="$product->name"
                           sizes="(min-width: 1024px) 20vw, 50vw"
                           class="h-full w-full object-cover transition duration-300 group-hover:scale-105" />
            @else
                <div class="h-full w-full transition duration-300 group-hover:scale-[1.02]">
                    <x-glass-blueprint :product="$product" :showDimensions="false" :showOptions="false" class="h-full w-full rounded-none" />
                </div>
            @endif

            <div class="absolute top-2.5 left-2.5 flex flex-wrap gap-1.5">
                @if ($product->isMadeInKz())
                    <span class="badge badge-ok">Сделано в Казахстане</span>
                @endif
                @if ($product->camera)
                    <span class="badge badge-warn">Камера</span>
                @endif
                @if (settings()->enabled('display_stock') && $product->in_stock)
                    <span class="badge badge-brand">В наличии</span>
                @endif
                @if ($опт)
                    <span class="badge badge-brand">Опт</span>
                @endif
            </div>

            @if (($product->offers_count ?? 0) > 1)
                <span class="badge badge-muted absolute right-2.5 bottom-2.5 bg-surface/90 backdrop-blur">
                    {{ $product->offers_count }} предложения
                </span>
            @endif
        </div>

        <div class="flex flex-1 flex-col p-3.5 sm:p-4">
            <h3 class="line-clamp-3 text-sm leading-snug font-bold text-ink transition group-hover:text-brand-ink">
                {{ $product->name }}
            </h3>

            @if ($product->eurocode)
                <p class="mt-1 font-mono text-xs text-muted">{{ $product->eurocode }}</p>
            @endif

            @if ($options = $product->activeOptions())
                <p class="mt-1.5 line-clamp-2 text-xs leading-relaxed text-faint">
                    {{ implode(' · ', $options) }}
                </p>
            @endif

            <div class="product-card-price mt-auto flex items-end justify-between gap-2 pt-3">
                @if ($ценаОт)
                    <div>
                        <div class="price price-brand text-lg sm:text-xl">
                            {{ formatPrice($ценаОт) }} ₸
                        </div>
                        @if ($ценаДо && $ценаДо > $ценаОт)
                            <div class="mt-0.5 text-xs text-faint">
                                до {{ formatPrice($ценаДо) }} ₸
                            </div>
                        @endif
                    </div>
                @else
                    <div class="text-sm font-semibold text-faint">Цена по запросу</div>
                @endif

                @unless ($опт && settings()->enabled('cart'))
                    <span class="product-card-link">
                        Выбрать →
                    </span>
                @endunless
            </div>
        </div>
    </a>

    @if ($опт && settings()->enabled('cart'))
        <form method="post" action="{{ route('cart.add', $product) }}" class="border-t border-line p-3">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm w-full !rounded-xl">
                В корзину
            </button>
        </form>
    @elseif (settings()->enabled('cart'))
        <div class="border-t border-line p-3">
            <button type="button"
                    data-quick-order="{{ route('order.quick', $product) }}"
                    data-quick-order-name="{{ $product->name }}"
                    class="btn btn-outline btn-sm w-full !rounded-xl">
                Купить в 1 клик
            </button>
        </div>
    @endif
</article>
