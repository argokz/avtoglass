<?php

namespace App\Services\PriceList;

/**
 * Словарь марок: в прайсах пяти поставщиков одна и та же марка пишется по-разному
 * (VW / VOLKSWAGEN / ФОЛЬКСВАГЕН, HYUND / HYUNDAI, Dong Feng / DONGFENG).
 * Сводим всё к одному каноническому написанию.
 */
class BrandDictionary
{
    /** алиас (в нижнем регистре) => каноническое имя */
    private const ALIASES = [
        // --- Япония ---
        'toyota' => 'Toyota', 'тойота' => 'Toyota', 'toy' => 'Toyota',
        'lexus' => 'Lexus', 'лексус' => 'Lexus', 'lex' => 'Lexus',
        'nissan' => 'Nissan', 'ниссан' => 'Nissan', 'nis' => 'Nissan',
        'infiniti' => 'Infiniti', 'инфинити' => 'Infiniti',
        'honda' => 'Honda', 'хонда' => 'Honda',
        'acura' => 'Acura',
        'mazda' => 'Mazda', 'мазда' => 'Mazda',
        'mitsubishi' => 'Mitsubishi', 'мицубиси' => 'Mitsubishi', 'mit' => 'Mitsubishi',
        'subaru' => 'Subaru', 'субару' => 'Subaru',
        'suzuki' => 'Suzuki', 'сузуки' => 'Suzuki',
        'daihatsu' => 'Daihatsu',
        'isuzu' => 'Isuzu', 'исузу' => 'Isuzu',
        'hino' => 'Hino',
        'datsun' => 'Datsun',

        // --- Корея ---
        'hyundai' => 'Hyundai', 'hyund' => 'Hyundai', 'хендай' => 'Hyundai', 'хундай' => 'Hyundai',
        'kia' => 'Kia', 'киа' => 'Kia',
        'daewoo' => 'Daewoo', 'дэу' => 'Daewoo',
        'ssangyong' => 'SsangYong', 'ssang yong' => 'SsangYong',
        'ravon' => 'Ravon',

        // --- Германия ---
        'volkswagen' => 'Volkswagen', 'vw' => 'Volkswagen', 'фольксваген' => 'Volkswagen',
        // в прайсах встречается с заглавной "I" вместо строчной "l" и с опечатками
        'voikswagen' => 'Volkswagen', 'volkswagon' => 'Volkswagen',
        'фольцваген' => 'Volkswagen', 'фолькваген' => 'Volkswagen',
        'audi' => 'Audi', 'ауди' => 'Audi',
        'bmw' => 'BMW', 'бмв' => 'BMW',
        'mercedes' => 'Mercedes-Benz', 'mercedes-benz' => 'Mercedes-Benz', 'mb' => 'Mercedes-Benz',
        'мерседес' => 'Mercedes-Benz',
        'opel' => 'Opel', 'опель' => 'Opel',
        'porsche' => 'Porsche',
        'smart' => 'Smart',
        'man' => 'MAN',

        // --- Европа ---
        'skoda' => 'Skoda', 'шкода' => 'Skoda',
        'seat' => 'Seat',
        'renault' => 'Renault', 'рено' => 'Renault', 'renalut' => 'Renault',
        'peugeot' => 'Peugeot', 'пежо' => 'Peugeot',
        'citroen' => 'Citroen', 'ситроен' => 'Citroen',
        'fiat' => 'Fiat', 'фиат' => 'Fiat',
        'alfa romeo' => 'Alfa Romeo', 'alfa' => 'Alfa Romeo',
        'volvo' => 'Volvo', 'вольво' => 'Volvo',
        'saab' => 'Saab',
        'scania' => 'Scania', 'скания' => 'Scania',
        'daf' => 'DAF', 'даф' => 'DAF',
        'iveco' => 'Iveco',
        'mini' => 'Mini',
        'land rover' => 'Land Rover', 'landrover' => 'Land Rover', 'range rover' => 'Land Rover',
        'jaguar' => 'Jaguar',

        // --- США ---
        'ford' => 'Ford', 'форд' => 'Ford',
        'chevrolet' => 'Chevrolet', 'шевроле' => 'Chevrolet', 'chev' => 'Chevrolet',
        'cadillac' => 'Cadillac',
        'jeep' => 'Jeep',
        'chrysler' => 'Chrysler',
        'dodge' => 'Dodge',
        'tesla' => 'Tesla',

        // --- Китай ---
        'geely' => 'Geely', 'джили' => 'Geely',
        'chery' => 'Chery', 'чери' => 'Chery',
        'haval' => 'Haval', 'хавал' => 'Haval',
        'great wall' => 'Great Wall', 'greatwall' => 'Great Wall',
        'jac' => 'JAC',
        'byd' => 'BYD',
        'changan' => 'Changan',
        'exeed' => 'Exeed',
        'omoda' => 'Omoda',
        'jetour' => 'Jetour',
        'lifan' => 'Lifan',
        'faw' => 'FAW',
        'baw' => 'BAW',
        'foton' => 'Foton',
        'howo' => 'Howo',
        'shacman' => 'Shacman',
        'dong feng' => 'Dongfeng', 'dongfeng' => 'Dongfeng',
        'camc' => 'CAMC',
        'zotye' => 'Zotye',
        'xcmg' => 'XCMG',

        // --- СНГ ---
        'ваз' => 'ВАЗ', 'lada' => 'ВАЗ', 'лада' => 'ВАЗ',
        'газ' => 'ГАЗ', 'газель' => 'ГАЗ',
        'уаз' => 'УАЗ',
        'камаз' => 'КАМАЗ',
        'маз' => 'МАЗ',
        'краз' => 'КРАЗ',
        'паз' => 'ПАЗ',
        'лиаз' => 'ЛИАЗ',
        'москвич' => 'Москвич', 'moskvich' => 'Москвич',
        'зил' => 'ЗИЛ',
        'урал' => 'УРАЛ',
        'валдай' => 'ГАЗ',
        'мтз' => 'МТЗ', 'беларус' => 'МТЗ',
        'иж' => 'ИЖ',
        'заз' => 'ЗАЗ',
        'тагаз' => 'ТагАЗ',
        // в прайсах встречается слитно, как будто отдельная марка
        'евромаз' => 'МАЗ', 'евро маз' => 'МАЗ',
        'еврокамаз' => 'КАМАЗ', 'евро камаз' => 'КАМАЗ',

        // --- премиум и редкие, встречаются в прайсе единично ---
        'genesis' => 'Genesis',
        'tank' => 'Tank',
        'buick' => 'Buick',
        'lincoln' => 'Lincoln',
        'gmc' => 'GMC',
        'hummer' => 'Hummer',
        'iran khodro' => 'Iran Khodro', 'iran' => 'Iran Khodro',
        'freightliner' => 'Freightliner',
        'international' => 'International',
        'kenworth' => 'Kenworth',
        'neoplan' => 'Neoplan',
        'setra' => 'Setra',
        'temsa' => 'Temsa',
        'higer' => 'Higer',
        'yutong' => 'Yutong',
        'king long' => 'King Long', 'kinglong' => 'King Long',

        // --- спецтехника ---
        'john deere' => 'John Deere',
        'komatsu' => 'Komatsu', 'комацу' => 'Komatsu',
        'caterpillar' => 'Caterpillar',
        'jcb' => 'JCB',
        'bobcat' => 'Bobcat',
        'hitachi' => 'Hitachi',
        'xgma' => 'XGMA',
        'sdlg' => 'SDLG',
        'lovol' => 'Lovol',
        'белаз' => 'БЕЛАЗ',
    ];

    /** Марки из нескольких слов проверяем первыми, иначе "Land Rover" распадётся на "Land". */
    private static ?array $sorted = null;

    /**
     * Ищет марку в начале строки.
     *
     * @return array{0:string,1:string}|null [каноническое имя, остаток строки]
     */
    public static function matchAtStart(string $text): ?array
    {
        $text = ltrim($text);

        foreach (self::sortedAliases() as $alias => $canonical) {
            $len = mb_strlen($alias);
            $head = mb_strtolower(mb_substr($text, 0, $len));

            if ($head !== $alias) {
                continue;
            }

            // следующий символ должен быть границей, чтобы "man" не съел "Mansory"
            $next = mb_substr($text, $len, 1);
            if ($next !== '' && preg_match('/[\p{L}\p{N}]/u', $next)) {
                continue;
            }

            return [$canonical, ltrim(mb_substr($text, $len))];
        }

        return null;
    }

    /**
     * Ищет марку в любом месте строки — запасной вариант, когда в начале мусор
     * ("Молдинг лобового стекла KIA CEED 12-18").
     *
     * @return array{0:string,1:string}|null [каноническое имя, остаток строки ПОСЛЕ марки]
     */
    public static function matchAnywhere(string $text): ?array
    {
        $lower = mb_strtolower($text);

        foreach (self::sortedAliases() as $alias => $canonical) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $lower, $m, PREG_OFFSET_CAPTURE)) {
                $rest = substr($text, $m[0][1] + strlen($m[0][0]));

                return [$canonical, ltrim($rest)];
            }
        }

        return null;
    }

    public static function canonical(string $alias): ?string
    {
        return self::ALIASES[mb_strtolower(trim($alias))] ?? null;
    }

    private static function sortedAliases(): array
    {
        if (self::$sorted === null) {
            self::$sorted = self::ALIASES;
            uksort(self::$sorted, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        }

        return self::$sorted;
    }
}
