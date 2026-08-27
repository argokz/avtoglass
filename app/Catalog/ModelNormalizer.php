<?php

namespace App\Catalog;

/**
 * Приводит имя модели из прайса к виду, пригодному для дерева каталога.
 *
 * Решает две разные задачи, и их важно не путать:
 *
 *  1. Чистка. В имя модели протекают куски, которые парсер не отнёс ни к одной
 *     категории: «SONATA RED», «Focus II перед», «PASSAT B5 ЗЛСР». Это не модели,
 *     а хвосты тонировки, типа стекла и опций.
 *
 *  2. Вынос поколения. «Golf II», «Camry 40», «5-SERIES E39» — это одна модель
 *     и разные поколения, а не разные модели. Без этого у Volkswagen в меню
 *     семь «Гольфов», и покупатель не понимает, какой его.
 *
 * Чего он намеренно НЕ делает — не угадывает там, где данных не хватает.
 * «W140, S-Class» кодом не развернуть, для таких случаев есть таблица
 * псевдонимов, которую заполняет контент-менеджер.
 */
class ModelNormalizer
{
    /**
     * Токены, которых в имени модели быть не может.
     * Порядок не важен, все убираются за один проход.
     */
    private const JUNK = [
        // тип стекла и его сокращения
        // «зад» без окончания тоже встречается: «Vesta зад», «T4 зад.кузовная»
        '/(?<![\p{L}])(?:СТ|ВЕТР\p{L}*|ЛОБ\p{L}*|перед(?:н\p{L}*)?|зад(?:н\p{L}*)?|ПЕР|опускн\p{L}*|дв)\.?(?![\p{L}])/iu',
        // опции
        '/(?<![\p{L}])(?:датчик\p{L}*(?:\s+запотевания)?|мал\.?\s*камер\p{L}*|камер\p{L}*|обогрев\p{L}*|антенн\p{L}*|VIN)(?![\p{L}])/iu',
        // тонировка и серии: RED/BLUE — маркировка серии XYG, ЗЛСР и подобные — цвет
        '/(?<![\p{L}])(?:RED|BLUE|GREEN|Solar\s*-?\s*X|ЗЛ\p{Lu}{0,2}|ТТЗ|ТЗ|БЕСЦВ\p{L}*|OVERTIN\p{L}*|TINTED?)(?![\p{L}])/u',
        // годы: «06-», «-1998», «2004», «21-»
        '/(?<![\p{L}\p{N}])(?:(?:19|20)\d{2}|\d{2})\s*-\s*(?![\p{N}])/u',
        '/(?<![\p{L}\p{N}])-\s*(?:19|20)\d{2}(?![\p{N}])/u',
        '/(?<![\p{L}\p{N}])(?:19|20)\d{2}(?![\p{N}])/u',
        // кузов: и по-русски через точку («A8 .седан»), и сокращениями поставщиков
        '/(?<![\p{L}])\.?\s*(?:седан|хэтчбек|хетчбек|универсал|купе|кабриолет|внедорожник|фургон|минивэн)(?![\p{L}])/iu',
        '/(?<![\p{L}])(?:WGN|WAGON|HARDTOP|HBK|SEDAN|COUPE|CPE|LBK|MPV|SUV|LIM|лимузин\p{L}*)(?![\p{L}])/iu',
        // «3 series», «5-SERIE», «5 SER», «6 серия» — слово ничего не уточняет,
        // дефис перед ним тоже. Кириллица здесь встречается наравне с латиницей.
        '/[\s-]*(?<![\p{L}])(?: ser (?:ie s?)? | сери[яийю] )(?![\p{L}])/iux',
        // хвост еврокода: из «8394AGNGYV» до имени модели долетает «Agngy»
        '/(?<![\p{L}])AGNG\p{L}*(?![\p{L}])/iu',
        // состояние товара и плёнка — не признаки модели
        '/(?<![\p{L}])(?:Б\s*[\/.]\s*У|хамелеон)(?![\p{L}])/iu',

        /*
         * Скобочный хвост: «100 (44к)», «Vesta (совмещ. коннектор». Внутри
         * скобок поставщики пишут что угодно — индекс кузова, примечание
         * кладовщика, обрезанную на полуслове фразу. Ни в одном случае это
         * не часть имени модели. Закрывающая скобка необязательна: строку
         * в прайсе обрезают по длине колонки.
         */
        '/\s*\([^)]*\)?/u',

        /*
         * Форма фар. У поставщиков это способ различить поколения одной
         * машины: «5 кругл» и «5 квадр» — это BMW пятой серии E39 и E60.
         * Для справочника моделей они одна модель, а разницу несут годы
         * применимости, поэтому метку убираем.
         */
        '/(?<![\p{L}])(?:кругл\p{L}*|квадр\p{L}*|грушевид\p{L}*|овальн\p{L}*)(?![\p{L}])/iu',

        /*
         * Индекс кузова словом: «100 44 кузов». Число забираем вместе
         * со словом — само по себе оно неотличимо от имени модели.
         */
        '/(?<![\p{L}])\d{1,3}\s*кузов\p{L}*(?![\p{L}])/iu',
        '/(?<![\p{L}])кузов\p{L}*(?![\p{L}])/iu',

        /*
         * Характеристики самого стекла, доехавшие до колонки модели:
         * «Vesta .непод» (неподвижное), «Transporter T4 зад.кузовная»,
         * «удлинённая», «совмещ. коннектор».
         */
        '/(?<![\p{L}])\.?\s*(?:непод\p{L}*|неподв\p{L}*)(?![\p{L}])/iu',
        '/(?<![\p{L}])(?:удлин[её]нн\p{L}*|средн\p{L}*|коротк\p{L}*)(?![\p{L}])/iu',
        '/(?<![\p{L}])(?:совмещ\p{L}*|коннектор\p{L}*)\.?(?![\p{L}])/iu',
        '/(?<![\p{L}])(?:изм|СП)\.?(?![\p{L}])/u',

        /*
         * Марка самого стекла, а не машины: «Ceed SAT», «E39 Flexline».
         * SL сюда не входит намеренно — это модель Mercedes.
         */
        '/(?<![\p{L}])(?:SAT|XYG|Flexline|KMK|FUYAO|AGC|NordGlass|Pilkington|Benson|BOR|КМК|СПЕКТР)(?![\p{L}])/iu',
    ];

    /**
     * Индекс поколения: римская цифра или заводской код кузова. В таком хвосте
     * всегда есть буква, поэтому спутать его с именем модели невозможно.
     */
    private const GENERATION_CODE = '/^(?:
          [IVX]{1,5}                 # II, III, VIII
        | [A-Z]{1,3}\d{1,3}[A-Z]?    # E39, W140, XV30, U50, T6, B5, F20
        | \d[A-Z]{1,2}               # 2K
    )$/xiu';   // без учёта регистра: после замены кириллицы код бывает строчным

    /**
     * Числовая серия: «Camry 40», «Land Cruiser 200». Голое число опаснее —
     * им называются и сами модели, поэтому его выносят только при условии
     * из splitGeneration().
     */
    private const GENERATION_NUMBER = '/^\d{1,3}$/u';

    /** @var array<string,NormalizedModel> псевдонимы: "марка|сырое имя" => готовый результат */
    private array $aliases = [];

    /**
     * @param  array<string,array{name:string,generation:?string}>  $aliases
     */
    public function __construct(array $aliases = [])
    {
        foreach ($aliases as $key => $value) {
            $this->aliases[mb_strtolower($key)] = new NormalizedModel(
                name: $value['name'],
                generation: $value['generation'] ?? null,
                fromAlias: true,
            );
        }
    }

    public function normalize(string $brand, string $rawModel): NormalizedModel
    {
        $alias = $this->aliases[mb_strtolower(trim($brand).'|'.trim($rawModel))] ?? null;

        if ($alias !== null) {
            return $alias;
        }

        $clean = $this->stripJunk($rawModel);

        if ($clean === '') {
            // Вычистили всё — значит имени модели там и не было.
            // Возвращаем исходное, пусть лучше будет некрасиво, чем пусто.
            return new NormalizedModel(name: trim($rawModel));
        }

        [$name, $generation] = $this->splitGeneration($clean);

        return new NormalizedModel(
            name: $this->prettify($name),
            generation: $generation,
        );
    }

    private function stripJunk(string $value): string
    {
        // обратные кавычки и прочий шум из выгрузки: «5-SERIE E34` RED»
        $value = str_replace(['`', '"', '«', '»'], ' ', $value);

        $value = $this->unifyHomoglyphs($value);
        $value = $this->cutSecondCar($value);

        foreach (self::JUNK as $pattern) {
            $value = preg_replace($pattern, ' ', $value) ?? $value;
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value, " \t.,;:-/+");
    }

    /**
     * «ESTIMA/PREVIA», «Avensis/ Caldina» — через слэш приписана вторая машина.
     * «Corolla KE140/KE150» — тот же слэш, но это два индекса кузова одной модели.
     *
     * Отличаем по цифрам: в индексе кузова они есть всегда, в имени модели —
     * почти никогда. Ошибиться в эту сторону безопаснее: лишняя модель в меню
     * хуже, чем неразрезанная пара.
     */
    private function cutSecondCar(string $value): string
    {
        if (! str_contains($value, '/')) {
            return $value;
        }

        [$first, $rest] = explode('/', $value, 2);
        $rest = trim($rest);

        $looksLikeModel = preg_match('/^\p{L}{3,}/u', $rest) && ! preg_match('/\d/u', $rest);

        return $looksLikeModel ? $first : $value;
    }

    /**
     * В прайсах кириллица и латиница перемешаны внутри одного кода: «Cresta х100»,
     * «A6 (С6)», «21-CТ». Глазами не отличить, а для базы это разные строки —
     * так одна модель и разъезжается на две.
     */
    private function unifyHomoglyphs(string $value): string
    {
        static $map = [
            'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
            'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X',
            'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'х' => 'x',
        ];

        // Меняем только в токенах, которые и так похожи на код: есть цифра
        // и нет длинного словесного куска. Иначе «Веста» превратится в «Becta».
        return implode(' ', array_map(
            fn (string $token) => preg_match('/\d/u', $token) && mb_strlen($token) <= 8
                ? strtr($token, $map)
                : $token,
            preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []
        ));
    }

    /**
     * Отрезает поколение с конца имени.
     *
     * Код кузова («E39», «II») снимается всегда — в нём есть буква, и на имя
     * модели он не похож. Голое число снимается только если в остатке есть
     * слово с буквой: у «Camry 40» оно есть и «40» уезжает в поколение,
     * а у «100» и «2110» — нет, там число и есть имя модели.
     *
     * @return array{0:string,1:?string}
     */
    private function splitGeneration(string $value): array
    {
        $tokens = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $generation = [];

        while (count($tokens) > 1) {
            $last = end($tokens);
            $head = array_slice($tokens, 0, -1);
            $headHasLetters = (bool) preg_match('/\p{L}/u', implode('', $head));

            /*
             * Номер артикула в хвосте: «Transporter T4 8537».
             *
             * Требуем, чтобы перед числом уже стоял индекс поколения. Номер
             * артикула приписывают в самый конец, после модели и кузова,
             * а число сразу за именем — это чаще всего часть самого имени:
             * «Foton Ollin 1033» и «Ollin 1041» — разные грузовики, и склеить
             * их значит подобрать не то стекло.
             */
            if ($headHasLetters
                && preg_match('/^\d{4,}$/u', $last)
                && $this->looksLikeGenerationCode((string) end($head))) {
                array_pop($tokens);

                continue;
            }

            /*
             * Одиночная кириллическая буква — обрывок примечания
             * («Elantra а»), а не индекс. Латинская в этой роли не бывает.
             */
            if (preg_match('/^\p{Cyrillic}$/u', $last)) {
                array_pop($tokens);

                continue;
            }

            $isCode = $this->looksLikeGenerationCode($last);

            /*
             * Одиночная латинская буква — индекс поколения: «Astra H»,
             * «Astra J». Требуем букв в голове: у «323 F» голова числовая,
             * и F там — вариант кузова, а не поколение; склеивать их нельзя.
             */
            $isLetter = $headHasLetters && preg_match('/^[A-Z]$/iu', $last);

            $isNumber = (bool) preg_match(self::GENERATION_NUMBER, $last) && $headHasLetters;

            if (! $isCode && ! $isLetter && ! $isNumber) {
                break;
            }

            array_unshift($generation, array_pop($tokens));
        }

        return [
            implode(' ', $tokens),
            $generation ? implode(' ', $generation) : null,
        ];
    }

    /**
     * Индекс поколения, в том числе перечислением.
     *
     * Поставщики пишут пары кузовов слитно: «W210,S210» у Mercedes,
     * «E60/E61» у BMW — это седан и универсал одного поколения. Считаем
     * такой хвост индексом, только если индексом является каждая часть:
     * иначе под правило попадёт «Avensis/Caldina», где через слэш стоит
     * вторая машина.
     */
    private function looksLikeGenerationCode(string $token): bool
    {
        $parts = preg_split('#[/,]+#u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            if (! preg_match(self::GENERATION_CODE, $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * «CAMRY» -> «Camry», но «CR-V», «X5», «RAV4» и «BMW» трогать нельзя —
     * это не крик, а официальное написание.
     */
    private function prettify(string $name): string
    {
        $tokens = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = array_map(function (string $token): string {
            $isShout = mb_strtoupper($token) === $token
                && mb_strlen($token) >= 4
                && ! preg_match('/[\d\-\/]/u', $token);

            return $isShout
                ? mb_convert_case(mb_strtolower($token), MB_CASE_TITLE, 'UTF-8')
                : $token;
        }, $tokens);

        return implode(' ', $tokens);
    }
}
