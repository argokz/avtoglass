@props(['code' => '7260AGNGYV'])

{{--
    Где на стекле искать еврокод.

    Место в герое занимала абстрактная схема стекла — красивая, но ничего
    не сообщавшая. Здесь та же схема отвечает на настоящий вопрос: человек
    не знает год выпуска и модель кузова, зато код у него перед глазами,
    в нижнем углу лобового. Подсказать, куда смотреть, — быстрее, чем
    заставлять вспоминать поколение машины.
--}}
<div {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-card border border-white/10 bg-white/[0.04] p-5']) }}>
    <p class="text-xs font-semibold text-on-night">Еврокод — на самом стекле</p>
    <p class="mt-1 text-xs leading-relaxed text-on-night-muted">
        В нижнем углу, рядом с маркировкой завода.
    </p>

    <svg viewBox="0 0 240 150" class="mt-3 w-full" aria-hidden="true">
        {{-- силуэт лобового: сверху уже, снизу шире --}}
        <path d="M52 26 Q120 16 188 26 L212 118 Q120 130 28 118 Z"
              fill="rgba(255,255,255,0.05)" stroke="currentColor"
              class="text-on-night-muted" stroke-width="1.5" stroke-linejoin="round"/>

        {{-- вырез под зеркало: по нему стекло и узнают --}}
        <path d="M110 22 h20 l-2 14 q-8 5 -16 0 Z"
              fill="currentColor" class="text-on-night-muted" opacity=".5"/>

        {{-- угол с наклейкой — единственное цветное пятно, взгляд идёт туда --}}
        <rect x="38" y="98" width="52" height="16" rx="3"
              fill="currentColor" class="text-brand" opacity=".9"/>
        <circle cx="64" cy="106" r="24" fill="none" stroke="currentColor"
                class="text-brand" stroke-width="1.5" opacity=".45"/>
    </svg>

    <p class="mt-3 rounded-lg bg-white/10 px-3 py-2 text-center font-mono text-sm font-semibold tracking-wide text-on-night">
        {{ $code }}
    </p>
</div>
