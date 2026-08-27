<?php

namespace App\Services\PriceList;

use App\Enums\BodyType;
use App\Enums\GlassType;

/**
 * Разбор наименования из прайса в структуру.
 *
 * В файле смешаны пять диалектов именования — по одному на поставщика:
 *   КМК     Toyota "Highlander" III U50 5D Utility '2013- #8445 ЗП ТЗ ДД камера (обогрев щеток) КМК
 *   SL      Стекло ветровое Renault Megane II 2002-07 з/п ТЗ 7260AGNGYV SL
 *   СПЕКТР  Citroen C3 5D Hbk стекло ветровое #2726 2002-2006 г. с ДД п.ТЗ СПЕКТР
 *   AGC     4174521 4187AGRCMUV 7900207A HYUND PALISADE 21-СТ ВЕТР ЗЛ+АК+КАМ+HUD AGC
 *   XYG     TOYOTA CAMRY 70 SEDAN 2018- Solar-X RED ДД (полный обогрев) XYG
 *
 * Вместо пяти отдельных грамматик — послойное вычитание: снимаем со строки
 * самые узнаваемые куски (поставщик, коды, годы, кузов, опции), а то,
 * что осталось в начале, и есть марка с моделью. Так парсер не ломается
 * от шестого диалекта, который приедет от нового поставщика.
 */
class NameParser
{
    /** Суффикс поставщика => диалект. Порядок важен: длинные раньше коротких. */
    private const SUPPLIERS = [
        'NordGlass' => 'nordglass',
        'Pilkington' => 'pilkington',
        'ОРИГИНАЛ' => 'oem',
        'СПЕКТР' => 'spektr',
        'Benson' => 'benson',
        'Fuyao' => 'fuyao',
        'КИТАЙ' => 'china',
        'КМК' => 'kmk',
        'BGC' => 'bgc',
        'AGC' => 'agc',
        'XYG' => 'xyg',
        'BOR' => 'bor',
        'NG' => 'nordglass',
        'SL' => 'sl',
    ];

    public function parse(string $rawName): ParsedName
    {
        $result = new ParsedName(raw: $rawName);

        $text = $this->normalize($rawName);

        $text = $this->extractSupplier($text, $result);
        // Модель в кавычках снимаем до разбора годов, иначе ВАЗ "2101-2107"
        // и ИЖ "2126" читаются как диапазон лет выпуска.
        $text = $this->extractQuotedModel($text, $result);
        $text = $this->extractCodes($text, $result);
        $text = $this->extractDimensions($text, $result);
        $text = $this->extractYears($text, $result);
        $text = $this->extractBodyAndDoors($text, $result);
        $text = $this->extractGlassType($text, $result);
        $text = $this->extractOptions($text, $result);
        // co-модели снимаем последними: разделители "//" и "|" стоят в середине строки,
        // а годы и опции — после последней модели. Разрежь раньше — потеряешь их.
        $text = $this->extractCoModels($text, $result);
        $text = $this->extractBrandAndModel($text, $result);

        $result->leftover = trim(preg_replace('/\s+/u', ' ', $text));

        $this->validate($result);

        return $result;
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\u{00B6}", "\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    // ------------------------------------------------------------------
    // поставщик
    // ------------------------------------------------------------------

    private function extractSupplier(string $text, ParsedName $r): string
    {
        foreach (self::SUPPLIERS as $name => $dialect) {
            $pattern = '/\s*(?<![\p{L}\p{N}])'.preg_quote($name, '/').'\s*$/iu';

            if (preg_match($pattern, $text)) {
                $r->supplier = $name;
                $r->dialect = $dialect;

                return trim(preg_replace($pattern, '', $text));
            }
        }

        $r->dialect = 'unknown';
        $r->addIssue('Поставщик не распознан');

        return $text;
    }

    // ------------------------------------------------------------------
    // "Citroen Berlingo // Peugeot Partner" — одно стекло на несколько машин
    // ------------------------------------------------------------------

    private function extractCoModels(string $text, ParsedName $r): string
    {
        // "//" разделяет разные марки, "|" — чаще разные модели одной марки
        $parts = preg_split('~\s*//\s*|\s*\|\s*~u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];

        if (count($parts) <= 1) {
            return $text;
        }

        $primary = array_shift($parts);

        foreach ($parts as $part) {
            $brand = null;
            $rest = $part;

            if ($match = BrandDictionary::matchAtStart($part)) {
                [$brand, $rest] = $match;
            }

            $model = null;
            if (preg_match('/"([^"]+)"/u', $rest, $m)) {
                $model = trim($m[1]);

                // BMW "3 E30" ... | "5D Touring" — в кавычках не вторая модель,
                // а второй кузов той же. Отдельной машины из него делать нельзя.
                if ($this->isBodyDescriptor($model)) {
                    $model = null;
                }
            }

            if ($brand || $model) {
                $r->coModels[] = ['brand' => $brand, 'model' => $model];
            }
        }

        return $primary;
    }

    /**
     * Строка описывает только кузов, без имени машины: "5D Touring", "4D Sed".
     * "Elantra Touring" под правило не подходит — там есть собственное имя.
     */
    private function isBodyDescriptor(string $name): bool
    {
        $name = trim($name);
        $name = preg_replace('/^\d(?:\s*[\/-]\s*\d)?\s*D(?![\p{L}])/iu', '', $name) ?? $name;
        $name = trim($name, " \t/-.,");

        if ($name === '') {
            return true;
        }

        return (bool) preg_match(
            '/^(?:Touring|Wagon|Wag|Sedan|Sed|Saloon|Coupe|Cpe|Cou|Cabrio|Cabriolet|Hatchback|Hbk|Lbk|Van|Minivan|Mpv|Suv|Pick-?Up|Truck|Bus|Break|Utility|Estate)$/iu',
            $name
        );
    }

    // ------------------------------------------------------------------
    // модель в кавычках — диалект КМК: Toyota "Highlander" III U50
    // ------------------------------------------------------------------

    private function extractQuotedModel(string $text, ParsedName $r): string
    {
        if (! preg_match('/"([^"]+)"/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        $r->model = trim($m[1][0]);

        $start = $m[0][1];
        $length = strlen($m[0][0]);
        $tail = substr($text, $start + $length);

        // сразу за моделью часто идёт поколение: III, U50, W212, XR10
        if (preg_match('/^\s*((?:[IVX]{1,4})|(?:[A-Z]{1,3}\d{2,3}))(?![\p{L}\p{N}])/u', $tail, $g)) {
            $r->generation = $g[1];
            $length += strlen($g[0]);
        }

        return substr($text, 0, $start).' '.substr($text, $start + $length);
    }

    // ------------------------------------------------------------------
    // еврокод, внутренний код, OEM
    // ------------------------------------------------------------------

    private function extractCodes(string $text, ParsedName $r): string
    {
        // внутренний код прайса: #8445
        if (preg_match('/#\s*(\d{3,5})/u', $text, $m)) {
            $r->internalCode = $m[1];
            $text = str_replace($m[0], ' ', $text);
        }

        // еврокод — маркировка на самом стекле: 7260AGNGYV, EJ02AGSCHMUVW,
        // 5364AGNGYCPV6T (у длинных вариантов хвост из цифр и букв вперемешку)
        $euroPatterns = [
            '/(?<![\p{L}\p{N}])(\d{4}[A-Z]{2,12}[A-Z\d]{0,4})(?![\p{L}\p{N}])/u',
            '/(?<![\p{L}\p{N}])([A-Z]{2}\d{2}[A-Z]{4,12})(?![\p{L}\p{N}])/u',
        ];

        foreach ($euroPatterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $r->eurocode = $m[1];
                $text = str_replace($m[0], ' ', $text);
                break;
            }
        }

        // Артикул поставщика через дефисы: LEM-TIL-MS0685. Требуем минимум две
        // группы после первой и только заглавные — иначе под правило попадут
        // сами модели: "CR-V", "E-Class", "X-Trail".
        $vendorArticle = '/(?<![\p{L}\p{N}])[A-Z]{2,4}(?:-[A-Z0-9]{2,6}){2,3}(?![\p{L}\p{N}])/u';

        if (preg_match($vendorArticle, $text, $m)) {
            $r->oemCode ??= $m[0];
            $text = str_replace($m[0], ' ', $text);
        }

        // диалект AGC: "4174521 4187AGRCMUV 7900207A HYUND PALISADE"
        // ведущий 6-8-значный артикул и OEM-код автопроизводителя
        if (preg_match('/^\s*(\d{6,8})(?![\p{L}\p{N}])/u', $text, $m)) {
            $text = trim(mb_substr($text, mb_strlen($m[0])));
        }

        if (preg_match('/(?<![\p{L}\p{N}])(\d{6,9}[A-Z]?)(?![\p{L}\p{N}])/u', $text, $m)) {
            $r->oemCode = $m[1];
            $text = str_replace($m[0], ' ', $text);
        }

        return $text;
    }

    private function extractDimensions(string $text, ParsedName $r): string
    {
        if (preg_match('/\(\s*(\d{3,4})\s*[*xх×]\s*(\d{3,4})\s*\)/u', $text, $m)) {
            $r->widthMm = (int) $m[1];
            $r->heightMm = (int) $m[2];
            $text = str_replace($m[0], ' ', $text);
        }

        // AGC пишет габариты без скобок и с толщиной третьим числом:
        // "2110 OVERTINTED 1428x862x5,00". Без этого правила размер уезжает в модель.
        if ($r->widthMm === null
            && preg_match('/(?<![\d])(\d{3,4})\s*[*xх×]\s*(\d{3,4})(?:\s*[*xх×]\s*\d{1,2}(?:[.,]\d{1,2})?)?(?![\d])/u', $text, $m)) {
            $r->widthMm = (int) $m[1];
            $r->heightMm = (int) $m[2];
            $text = str_replace($m[0], ' ', $text);
        }

        if (preg_match('/\(\s*(\d{1,2})\s*отв[^)]*\)/u', $text, $m)) {
            $r->holesCount = (int) $m[1];
            $text = str_replace($m[0], ' ', $text);
        }

        return $text;
    }

    // ------------------------------------------------------------------
    // годы выпуска
    // ------------------------------------------------------------------

    private function extractYears(string $text, ParsedName $r): string
    {
        $patterns = [
            // '2004-2015  /  '2013-
            "/'(\\d{4})\\s*[-–]\\s*(\\d{4})/u",
            "/'(\\d{4})\\s*[-–]\\s*/u",
            "/'(\\d{4})(?![\\d])/u",
            // 2002-2006 г.
            '/(?<![\d])(\d{4})\s*[-–]\s*(\d{4})\s*г/u',
            // (00-06)
            '/\(\s*(\d{2})\s*[-–]\s*(\d{2})\s*\)/u',
            // 2002-06
            '/(?<![\d])(\d{4})\s*[-–]\s*(\d{2})(?![\d])/u',
            // 1996-2012
            '/(?<![\d])(\d{4})\s*[-–]\s*(\d{4})(?![\d])/u',
            // 21-СТ ВЕТР (диалект AGC: две цифры года перед типом стекла)
            '/(?<![\d])(\d{2})\s*[-–]\s*(?=СТ\s)/u',
            // 95-02
            '/(?<![\d])(\d{2})\s*[-–]\s*(\d{2})(?![\d])/u',
            // 2008-  (открытый диапазон)
            '/(?<![\d])(\d{4})\s*[-–]/u',
            // просто год
            '/(?<![\d])(19|20)(\d{2})\s*г/u',
        ];

        foreach ($patterns as $i => $pattern) {
            if (! preg_match($pattern, $text, $m)) {
                continue;
            }

            // последний паттерн отдаёт год двумя группами
            if ($i === count($patterns) - 1) {
                $from = (int) ($m[1].$m[2]);
                $to = null;
            } else {
                $from = $this->expandYear($m[1]);
                $to = isset($m[2]) && $m[2] !== '' ? $this->expandYear($m[2]) : null;
            }

            // Номера моделей и артикулы тоже выглядят как "2101-2107" и "5116-1".
            // Неправдоподобный год — не год: пробуем следующий паттерн.
            if (! $this->isPlausibleYear($from)) {
                continue;
            }

            if ($to !== null && ! $this->isPlausibleYear($to)) {
                $to = null;
            }

            if ($to && $to < $from) {
                // "2002-06" -> 2002-2006, а не 2002-1906
                $to = $from + (($to + 100 - $from % 100) % 100);
            }

            $r->yearFrom = $from;
            $r->yearTo = $to;

            return str_replace($m[0], ' ', $text);
        }

        return $text;
    }

    private function isPlausibleYear(int $year): bool
    {
        return $year >= 1950 && $year <= (int) date('Y') + 2;
    }

    private function expandYear(string $value): int
    {
        $year = (int) $value;

        if ($year >= 1900) {
            return $year;
        }

        // 95 -> 1995, 21 -> 2021
        return $year >= 50 ? 1900 + $year : 2000 + $year;
    }

    // ------------------------------------------------------------------
    // кузов и число дверей
    // ------------------------------------------------------------------

    private function extractBodyAndDoors(string $text, ParsedName $r): string
    {
        // 5D / 3/5D / 2-4D. Вариантов кузова в строке бывает несколько
        // ("5D Hbk / 5D Wagon") — значение берём из первого, а вычищаем все,
        // иначе хвост "/5D" уезжает в название модели.
        $doors = '/(?<![\p{L}\p{N}])(\d(?:\s*[\/-]\s*\d)?)\s*D(?![\p{L}])/iu';

        if (preg_match($doors, $text, $m)) {
            $r->doors = preg_replace('/\s+/u', '', $m[1]);
            $text = preg_replace($doors, ' ', $text) ?? $text;
        }

        $tokens = [
            'Pick-Up', 'Pickup', 'Cabover', 'Utility', 'Cabrio', 'Minivan',
            'Break', 'Truck', 'Sedan', 'Wagon', 'Hbk', 'Suv', 'Sed', 'Van',
            'Mpv', 'Lbk', 'Wag', 'Cou', 'Tour', 'Bus', 'Hatchback',
        ];

        // Тип кузова — из первого совпадения, но убираем все слова-кузова:
        // "Sed/5D Hbk" оставил бы "Hbk" в модели.
        foreach ($tokens as $token) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($token, '/').'(?![\p{L}\p{N}])/iu';

            if (! preg_match($pattern, $text)) {
                continue;
            }

            if (! $r->bodyType && $body = BodyType::fromPriceList($token)) {
                $r->bodyType = $body->value;
            }

            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }

        return $text;
    }

    // ------------------------------------------------------------------
    // тип стекла
    // ------------------------------------------------------------------

    private function extractGlassType(string $text, ParsedName $r): string
    {
        // Порядок критичен, каждое правило переставлено по живым данным:
        //  - услуги и расходники первыми: "Клипса молдинга лобового стекла" — это клипса;
        //  - лобовое раньше крыши: "ветровое (панорамная крыша)" — это лобовое
        //    для машины с панорамной крышей, а не стекло крыши;
        //  - боковое раньше заднего: "задн. дв. опускное правое" — это дверное,
        //    то есть боковое.
        // Хвосты слов съедаем целиком (\p{L}*), иначе от "ветрового" остаётся "ого"
        // и потом уезжает в название модели.
        $map = [
            [GlassType::Service,    '/установк\p{L}*|ремонт\p{L}*|калибровк\p{L}*|вклейк\p{L}*|поклейк\p{L}*|тонировк\p{L}*/iu'],
            [GlassType::Consumable, '/молдинг\p{L}*|подложк\p{L}*|прайм\p{L}*|primer|апликатор\p{L}*|аппликатор\p{L}*|кле[йяю]|герметик\p{L}*|активатор\p{L}*|полимер\p{L}*|запечатк\p{L}*|струн\p{L}*|лезви\p{L}*|пистолет\p{L}*|салфет\p{L}*|скотч|присоск\p{L}*|шило|скребок|стеклодомкрат\p{L}*|клипс\p{L}*|набор|ручк\p{L}*\s+для|пластина\s+под|триплекс/iu'],
            // "веровое" — опечатка поставщика, встречается в прайсе как есть
            [GlassType::Windshield, '/ветров\p{L}*|веров\p{L}*|лобов\p{L}*|ст\.?\s*ветр\p{L}*|(?<![\p{L}])ВЕТР\p{L}*|(?<![\p{L}])(?:WS|FW)(?![\p{L}])/iu'],
            [GlassType::Side,       '/опускн\p{L}*|(?<![\p{L}])оп\.?\s*(?:прав|лев)\p{L}*|(?<![\p{L}])бок(?![\p{L}])|боков\p{L}*|двер[иья]|пер\.?\s*дв|задн?\.?\s*дв/iu'],
            [GlassType::Rear,       '/задне\p{L}*|задок|ст\.?\s*задн\p{L}*|(?<![\p{L}])RW(?![\p{L}])|(?<![\p{L}])задн?\.?(?![\p{L}])/iu'],
            [GlassType::Quarter,    '/форточ\p{L}*|глух\p{L}*|неподвиж\p{L}*/iu'],
            [GlassType::Roof,       '/крыш\p{L}*|(?<![\p{L}])люк(?![\p{L}])|панорам\p{L}*/iu'],
        ];

        foreach ($map as [$type, $pattern]) {
            if (preg_match($pattern, $text, $m)) {
                $r->glassType = $type;
                $text = preg_replace($pattern, ' ', $text, 1);
                break;
            }
        }

        // Явного слова нет, но есть признаки, которые бывают только у лобового:
        // ЗП/полоса (солнцезащитная полоса), ДД (датчик дождя),
        // ТЗ (тонированное зелёное), Solar-X (атермальная серия XYG).
        $windshieldOnly = '/(?<![\p{L}])(ТЗ|ДД|ЗП)(?![\p{L}])|п\.?\s*ТЗ|з\/п|(?<![\p{L}])полоса(?![\p{L}])|Solar\s*-?\s*X/iu';

        if (! $r->glassType && preg_match($windshieldOnly, $text)) {
            $r->glassType = GlassType::Windshield;
        }

        if (! $r->glassType) {
            $r->addIssue('Тип стекла не распознан');
        }

        // Сторона. "правый руль" сюда не относится — это опция RHD, её разбирает
        // extractOptions, поэтому исключаем такие вхождения лукахедом.
        $sides = [
            'left' => '/(?<![\p{L}])(?:лев\p{L}*(?!\s*руль)|ЛВ)(?![\p{L}])|слева/iu',
            'right' => '/(?<![\p{L}])(?:прав\p{L}*(?!\s*руль)|ПР)(?![\p{L}])|справа/iu',
        ];

        foreach ($sides as $side => $pattern) {
            if (preg_match($pattern, $text)) {
                $r->position = $side;
                $text = preg_replace($pattern, ' ', $text) ?? $text;
                break;
            }
        }

        // Тип уже определён — все слова, которые на него указывают, дальше только
        // мешают: они попадают в название модели. Вычищаем их целиком, включая
        // повторы ("опускное двери правое" — Side сработал на "опускное",
        // а "двери" осталось бы в модели).
        $noise = [
            '/(?<![\p{L}])стекл\p{L}*(?![\p{L}])/iu',
            '/(?<![\p{L}])(?:ветров|веров|лобов|задне|боков|опускн|двер|форточ|глух)\p{L}*(?![\p{L}])/iu',
            '/(?<![\p{L}])(?:RW|WS|FW)(?![\p{L}])/u',
        ];

        foreach ($noise as $pattern) {
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }

        return $text;
    }

    // ------------------------------------------------------------------
    // опции
    // ------------------------------------------------------------------

    private function extractOptions(string $text, ParsedName $r): string
    {
        $strip = [];

        // солнцезащитная полоса: "без ЗП" важнее, чем "ЗП"
        if (preg_match('/без\s*ЗП/iu', $text, $m)) {
            $r->setOption('sun_band', false);
            $strip[] = $m[0];
        } elseif (preg_match('/(?<![\p{L}])ЗП(?![\p{L}])|з\/п|ЗЛЗЛ|(?<![\p{L}])полоса(?![\p{L}])/iu', $text, $m)) {
            $r->setOption('sun_band');
            $strip[] = $m[0];
        }

        // Держатель зеркала — сначала отрицание: "Б/ДЕРЖ.ЗЕР" это стекло БЕЗ кронштейна,
        // и по общему правилу "ДЕРЖ.ЗЕР" оно бы прочиталось наоборот.
        if (preg_match('~(?:б/|без\s*)держ\.?\s*зерк?\p{L}*~iu', $text, $m)) {
            $r->setOption('mirror_bracket', false);
            $strip[] = $m[0];
        } elseif (preg_match('/держ\.?\s*зерк?\p{L}*|кроншт\p{L}*\s*зерк\p{L}*/iu', $text, $m)) {
            $r->setOption('mirror_bracket');
            $strip[] = $m[0];
        }

        $flags = [
            'rain_sensor' => '/(?<![\p{L}])ДД(?![\p{L}])|датчик\s*дожд|\/ДД/iu',
            'camera' => '/камер|(?<![\p{L}])КАМ(?![\p{L}])/iu',
            // в прайсах и полностью, и сокращённо: "(об щ,кам + об кам)"
            // "ЭОдв" и "ЭОполн" — те же обогревы в записи AGC, слитно и без пробела
            'heated_wipers' => '/обогрев\s*щ[её]ток|(?<![\p{L}])об\.?\s*щ(?![\p{L}])|(?<![\p{L}])ЭО\s*дв\p{L}*(?![\p{L}])/iu',
            'heated_full' => '/полн[а-я]*\s*(?:электро)?обогрев|коннектор|(?<![\p{L}])ЭО\s*полн\p{L}*(?![\p{L}])/iu',
            'heated_rear' => '/(?<![\p{L}])ЭО(?![\p{L}])|Э\.?\s*О\.|электрообогрев|с\s*обогревом/iu',
            'antenna' => '/антенн\p{L}*|(?<![\p{L}])АНТ(?![\p{L}])/iu',
            'hud' => '/(?<![\p{L}])HUD(?![\p{L}])|(?<![\p{L}])ПЭП(?![\p{L}])|проекц/iu',
            'acoustic' => '/(?<![\p{L}])АК(?![\p{L}])|акустич/iu',
            'atermal' => '/Solar\s*-?\s*X|атерм|ATERM/iu',
            // «кант» — чёрная окантовка по краю, тот же шелкографический слой
            'silkscreen' => '/шелк|(?<![\p{L}])ШЕЛ(?![\p{L}])|(?<![\p{L}])КАНТ\p{L}*(?![\p{L}])/iu',
            'rhd' => '/правый\s*руль|(?<![\p{L}])RHD(?![\p{L}])/iu',
            'vin_window' => '/(?<![\p{L}])VIN(?![\p{L}])|окно\s*под\s*VIN/iu',
        ];

        foreach ($flags as $flag => $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $r->setOption($flag);
                $strip[] = $m[0];
            }
        }

        // Цвет стекла. ТТЗ/ЗЛГЛ/БЕСЦВ — сокращения AGC, в прайсе пишутся без пробелов
        // и слипаются с соседями через "+": "ЗЛГЛ+VIN", "БЕСЦВ+КАНТ".
        $tints = [
            'green' => '/(?<![\p{L}])(?:ТТЗ|ТЗ|ЗЛГЛ|ЗЛ)(?![\p{L}])|п\.?\s*ТЗ|зел[её]н\p{L}*/iu',
            'blue' => '/(?<![\p{L}])BLUE(?![\p{L}])|голуб\p{L}*|син\p{L}*/iu',
            'grey' => '/(?<![\p{L}])СР(?![\p{L}])|сер(?:ое|ый|ая)/iu',
            'bronze' => '/бронз\p{L}*/iu',
            // "белое" у поставщиков означает не белое, а неокрашенное стекло
            'clear' => '/бел(?:ое|ый|ая)|бесцветн\p{L}*|(?<![\p{L}])БЕСЦВ\p{L}*(?![\p{L}])|прозрачн\p{L}*/iu',
            // тонированное без указания цвета
            'tinted' => '/(?<![\p{L}])OVERTIN\p{L}*(?![\p{L}])|(?<![\p{L}])TINTED?(?![\p{L}])|тонирован\p{L}*/iu',
        ];

        foreach ($tints as $tint => $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $r->tint = $tint;
                break;
            }
        }

        foreach ($strip as $chunk) {
            $text = preg_replace('/'.preg_quote($chunk, '/').'/iu', ' ', $text, 1) ?? $text;
        }

        // Цвет вычищаем всеми написаниями сразу, а не только победившим: строка
        // "ЗЛ OVERTIN+ЗП" содержит два маркера, и невычищенный уедет в модель.
        foreach ($tints as $pattern) {
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }

        // Сокращения AGC, значение которых мы пока не раскладываем в опции.
        // Молча выбросить нельзя — это атрибут товара; но и в название модели
        // им нельзя. Убираем из строки и помечаем на ручную проверку.
        $unmapped = '/(?<![\p{L}])(?:ИНК|ДТ|ИЗМ|АК2|БЛ)(?![\p{L}])/u';

        if (preg_match_all($unmapped, $text, $found)) {
            $r->addIssue('Нераспознанные сокращения: '.implode(', ', array_unique($found[0])));
            $text = preg_replace($unmapped, ' ', $text) ?? $text;
        }

        // остатки скобок с пояснениями вида "(для базовой комплектации)"
        $text = preg_replace('/\([^)]*\)/u', ' ', $text) ?? $text;

        return $text;
    }

    // ------------------------------------------------------------------
    // марка и модель — то, что осталось
    // ------------------------------------------------------------------

    private function extractBrandAndModel(string $text, ParsedName $r): string
    {
        $text = trim(preg_replace('/^[\s,.\-\/]+/u', '', $text) ?? $text);

        // диалекты SL и СПЕКТР начинаются со слов "Стекло ветровое ..." —
        // само слово уже снято, остались предлоги и мусор
        $text = preg_replace('/^(стекло|с)\s+/iu', '', $text) ?? $text;

        if ($match = BrandDictionary::matchAtStart($text)) {
            [$r->brand, $text] = $match;
        } elseif ($match = BrandDictionary::matchAnywhere($text)) {
            // Марка в середине строки — так пишут молдинги и клипсы
            // ("Молдинг лобового стекла KIA CEED 12-18"). Модель ищем ПОСЛЕ марки,
            // иначе в неё попадают русские слова из начала строки.
            [$r->brand, $text] = $match;
            $r->addIssue('Марка найдена не в начале строки');
        } else {
            $r->addIssue('Марка не распознана');
        }

        // Модель уже снята из кавычек на раннем шаге — но чистку она всё равно
        // должна пройти: внутри кавычек встречаются двойные пробелы и хвосты.
        if ($r->model !== null) {
            $r->model = $this->cleanModel($r->model, $r);

            return $text;
        }

        // модель без кавычек — берём слова до первого «стоп-слова»
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $modelParts = [];

        // если цикл дойдёт до конца, в модель ушло всё — хвоста не останется
        $consumed = count($tokens);

        foreach ($tokens as $i => $token) {
            if ($this->isStopToken($token)) {
                $consumed = $i;
                break;
            }

            // Снятые опции оставляют после себя связки "+", "/", "-".
            // До модели это просто мусор, а вот после — граница: в
            // "2190 ТТЗ+ +держ.зеркала" всё правее числа уже атрибуты.
            if (! preg_match('/[\p{L}\p{N}]/u', $token)) {
                if ($modelParts) {
                    $consumed = $i;
                    break;
                }

                continue;
            }

            $modelParts[] = $token;

            if (count($modelParts) >= 3) {
                $consumed = $i + 1;
                break;
            }
        }

        if ($modelParts) {
            $text = implode(' ', array_slice($tokens, $consumed));
            $r->model = $this->cleanModel(implode(' ', $modelParts), $r);
        }

        if ($r->model === null || $r->model === '') {
            $r->model = null;
            $r->addIssue('Модель не распознана');
        }

        return $text;
    }

    /**
     * Приводит имя модели к товарному виду и разбирает перечисления.
     * ВАЗ в прайсах пишут списком — "2108,09,099,13,14,15" это шесть машин
     * с одним стеклом, а не модель с таким именем.
     */
    private function cleanModel(string $model, ParsedName $r): string
    {
        $model = trim(preg_replace('/\s+/u', ' ', $model) ?? $model);
        $model = trim($model, " \t+-/.,;:");

        if (preg_match('/^(\d{3,4})((?:\s*,\s*\d{2,4})+)$/u', $model, $m)) {
            $primary = $m[1];
            $siblings = preg_split('/\s*,\s*/u', trim($m[2], ' ,'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($siblings as $sibling) {
                $r->coModels[] = ['brand' => $r->brand, 'model' => $this->expandSibling($primary, $sibling, $r)];
            }

            return $primary;
        }

        return $model;
    }

    /**
     * "2108,09,13" — второй и третий номер записаны хвостом от первого:
     * 09 -> 2109, 13 -> 2113. Достраиваем недостающие цифры слева.
     *
     * Огрызок длиной больше двух цифр так не разворачивается: "099" — это
     * 21099, а не 2099, и угадывать тут нельзя. Отдаём как есть и помечаем
     * строку — пусть номер допишет человек.
     */
    private function expandSibling(string $primary, string $sibling, ParsedName $r): string
    {
        $missing = mb_strlen($primary) - mb_strlen($sibling);

        // Условие именно на длине огрызка, а не на числе недостающих цифр:
        // "099" короче "2108" на единицу, но дописать одну цифру слева — это
        // как раз и получить неправильное "2099".
        if (mb_strlen($sibling) <= 2 && $missing > 0) {
            return mb_substr($primary, 0, $missing).$sibling;
        }

        $r->addIssue("Не удалось развернуть номер модели: {$sibling}");

        return $sibling;
    }

    /** Слова, которые точно не входят в название модели. */
    private function isStopToken(string $token): bool
    {
        $token = trim($token, " \t.,;:()[]");

        if ($token === '') {
            return true;
        }

        static $stop = [
            'с', 'без', 'для', 'под', 'на', 'и', 'или', 'от', 'до',
            'тз', 'дд', 'зп', 'эо', 'ак', 'кам', 'шел', 'hud', 'ст',
            'стекло', 'ветровое', 'заднее', 'боковое', 'опускное',
            'г', 'гг', 'год', 'года', 'рестайлинг', 'high', 'low',
        ];

        return in_array(mb_strtolower($token), $stop, true);
    }

    // ------------------------------------------------------------------

    private function validate(ParsedName $r): void
    {
        if ($r->yearFrom && ($r->yearFrom < 1950 || $r->yearFrom > (int) date('Y') + 2)) {
            $r->addIssue("Подозрительный год начала: {$r->yearFrom}");
            $r->yearFrom = null;
        }

        if ($r->yearTo && $r->yearFrom && $r->yearTo < $r->yearFrom) {
            $r->addIssue('Год окончания раньше года начала');
            $r->yearTo = null;
        }

        if ($r->model !== null && mb_strlen($r->model) > 60) {
            $r->addIssue('Слишком длинное название модели');
        }
    }
}
