<?php
/**
 * ai_generate_descriptions.php
 *
 * Массовая генерация уникальных описаний товаров (DETAIL_TEXT) через
 * router.cheap (OpenAI-совместимый шлюз, даёт доступ к моделям Claude
 * без региональных ограничений Anthropic API).
 *
 * Работает ПО РАЗДЕЛАМ каталога: один запуск обрабатывает все товары
 * одного раздела (IBLOCK_SECTION_ID), используя промпт, настроенный
 * именно для этой категории в $SECTION_PROMPTS.
 *
 * Запуск:
 *   php ai_generate_descriptions.php review <SECTION_ID>
 *   php ai_generate_descriptions.php apply  <SECTION_ID>
 *
 * ЛОГИКА:
 *   1. review — забирает все товары раздела (без DETAIL_TEXT, если
 *      SKIP_IF_HAS_DETAIL_TEXT = true), собирает их свойства,
 *      отправляет промпт в Claude API, сохраняет результат в JSON-отчёт
 *      ai_desc_report_section_<ID>.json для ручной проверки.
 *      НИЧЕГО не пишет в Битрикс.
 *   2. apply  — читает уже проверенный (и, если нужно, отредактированный)
 *      JSON-отчёт этого раздела и записывает DETAIL_TEXT через
 *      CIBlockElement::Update().
 *
 * Такое разделение сделано специально: перед массовой записью в БД
 * всегда должен быть шаг ручной проверки текста.
 */

define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
$_SERVER["DOCUMENT_ROOT"] = "/home/web/1stall.ru/www";
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
fwrite(STDERR, "[debug] Ядро Битрикса загружено\n");

CModule::IncludeModule("iblock");
fwrite(STDERR, "[debug] Модуль iblock подключён\n");

// ==================== КОНФИГУРАЦИЯ ====================

// ID инфоблока с товарами (Каталог). Уточни точный ID через админку:
// Контент -> Инфоблоки -> в списке будет виден ID нужного каталога.
define("PRODUCT_IBLOCK_ID", 38);

// Настройки по разделам каталога. Ключ — ID раздела (IBLOCK_SECTION_ID),
// значение — доп. инструкция для промпта, специфичная для этой категории.
// ID раздела смотри в админке: Каталог -> открыть раздел -> в адресе
// строки будет что-то вроде ...&SECTION_ID=123.
$SECTION_PROMPTS = [
    // 123 => "Это профильные трубы (полые, с толщиной стенки). Обязательно
    //         упомяни толщину стенки и укажи, что это именно труба, а не
    //         сплошной пруток. Стандарт: ГОСТ 8639-82 / ГОСТ 13727-96.",
    //
    // 124 => "Это сплошной квадратный профиль (пруток), НЕ труба. Не упоминай
    //         толщину стенки и полость сечения. Стандарт: ГОСТ 21488-97.",
];

// Папка с текстовыми файлами промптов — редактируются напрямую (в т.ч.
// через кнопку "Редактировать промпт" в GUI-приложении), без правки кода.
define("PROMPTS_DIR", __DIR__ . "/prompts");
define("SORTAMENT_DVUTAVR_FILE", __DIR__ . "/dvutavr_sortament.php");
define("SORTAMENT_SHVELLER_FILE", __DIR__ . "/shveller_sortament.php");

// Отдельная папка для промптов SEO-страниц умного фильтра — физически
// отделена от товарных промптов, чтобы не путаться в одном общем списке.
define("SMARTFILTER_PROMPTS_DIR", PROMPTS_DIR . "/smartfilter");

// Полностью кастомные промпты на раздел — используются ВМЕСТО общего
// шаблона (не поверх него), когда для категории нужен детальный контроль
// формулировок, структуры и запрета на выдумывание характеристик.
// Привязки (ID раздела -> имя файла в prompts/) хранятся в отдельном
// JSON-файле prompts/section_mapping.json — так их можно безопасно менять
// через GUI-приложение (команды bindprompt/unbindprompt), не трогая код.
$SECTION_PROMPT_FILES = loadSectionPromptFiles();

// Раздел, который обрабатываем в этом запуске review (ID из массива выше).
// Можно переопределить аргументом командной строки:
//   php ai_generate_descriptions.php review 123
$DEFAULT_SECTION_ID = null; // <-- либо впиши ID сюда, либо передавай аргументом

// Раньше использовалось, чтобы пропускать товары с уже заполненным
// DETAIL_TEXT. Сейчас задача — уникализировать существующие тексты,
// поэтому обрабатываем все товары раздела независимо от наличия текста.
define("SKIP_IF_HAS_DETAIL_TEXT", false);

function getReportFile($sectionId)
{
    return __DIR__ . "/ai_desc_report_section_{$sectionId}.json";
}

// Ключ router.cheap. Взять в личном кабинете на router.cheap.
// Ключ router.cheap — прописан прямо здесь по договорённости.
// ВАЖНО: раз ключ хранится в файле, не выкладывай этот файл никуда
// в открытый доступ (публичный git, форум, скриншоты и т.п.).
// Ключ можно передать через переменную окружения ROUTER_CHEAP_API_KEY
// (например, из GUI-приложения при запуске команды) — она имеет приоритет.
// Если не передана, используется значение по умолчанию ниже.
// Ключ ТОЛЬКО из переменной окружения — передаётся GUI-приложением при
// каждом запуске. Никакого запасного значения в коде больше нет: если
// ключ не передан, ниже в runReview()/callClaudeAPI() будет явная ошибка,
// вместо тихой попытки сходить в API с недействительным значением.
define("ROUTER_CHEAP_API_KEY", getenv("ROUTER_CHEAP_API_KEY") ?: "");

// Модель тоже можно переопределить через переменную окружения (например,
// из GUI-приложения) — по умолчанию используется claude-sonnet-5.
define("ROUTER_CHEAP_MODEL", getenv("ROUTER_CHEAP_MODEL") ?: "claude-sonnet-5");

// ==================== IndexNow ====================
// Постоянный ключ для протокола IndexNow (Яндекс и Bing забирают
// изменённые страницы через общий эндпоинт api.indexnow.org).
// Ключ сгенерирован один раз — не меняй его, иначе файл-подтверждение
// перестанет совпадать с тем, что уже отправлялось раньше.
define("INDEXNOW_KEY", "f04e68d0e38cc17ebafe6ea66377e523");

// Инфоблок "Ewp: CEO Чпу" (модуль ewp_URLTOSEF) — превращает сырые URL с
// фильтром в чистые ЧПУ-адреса с редиректом и собственными SEO-полями.
// Найдено вручную через админку/БД, коды свойств: OLD_URL, REDIRECT,
// TITLE, KEYWORDS, DESCRIPTION, H1, SEO_TEXT.
define("EWP_URLTOSEF_IBLOCK_ID", 51);

// Фиксированный коммерческий блок, приклеивается КОДОМ (не ИИ) в конец
// каждого сгенерированного товарного описания. Плейсхолдеры #REGION_...#
// уже поддерживаются самим сайтом и подставляются автоматически по
// домену/региону — так же, как в основном тексте описания.
define("COMMERCIAL_FOOTER_HTML", <<<'FOOTER'
<p>Стоимость доставки рассчитывается индивидуально и зависит от объема и пункта назначения. Наши специалисты подберут оптимальный вариант перевозки для вашего заказа и помогут с организацией логистики.</p>

<p>Оформить заказ на металлопрокат можно несколькими способами:</p>

<ul>
<li><strong>Онлайн-каталог</strong>: добавьте нужные позиции в корзину на сайте и отправьте заявку;</li>
<li><strong>Телефон</strong>: свяжитесь с менеджером по номеру <a href="tel:#REGION_PHONE#">#REGION_PHONE#</a> и продиктуйте список необходимого товара;</li>
<li><strong>Электронная почта</strong>: пришлите перечень изделий на email <a href="mailto:#REGION_EMAIL#">#REGION_EMAIL#</a>;</li>
<li><strong>Обратный звонок</strong>: <a href="#" data-event="jqm" data-param-id="48">закажите звонок</a>, и мы перезвоним в удобное для вас время.</li>
</ul>

<p>Сообщите менеджеру характеристики проката, объем продукции и адрес доставки. Специалист рассчитает итоговую стоимость и подтвердит возможность выполнения поставки.</p>

<p>Скидки предоставляются в зависимости от объема закупки. Гибкие условия оплаты, включая отсрочку до 30 календарных дней для постоянных клиентов.</p>

<p>Собственный отдел логистики контролирует передвижение автомобилей и сохранность грузов.</p>

<p><strong>Доставка по #AREA_NAME# включая города:</strong> #AREA_CITIES#</p>
FOOTER
);

// Свойство REDIRECT — тип "список" (не простая строка Y/N), с одной
// опцией. Её конкретный ID в b_iblock_property_enum нужен для правильной
// записи через API (найдено вручную через SQL-запрос к базе).
define("EWP_REDIRECT_ENUM_ID", 396);

// ==================== РОТАЦИЯ ФОРМУЛ ДЛЯ META_DESCRIPTION ====================
// Синонимичные варианты одной и той же "триплетной" формулы (товар + действие
// + регион/цена), чтобы description не был одним фиксированным шаблоном на
// тысячи товаров. Регион/телефон — через существующие макросы EWP-модуля
// (#REGION_...#), они подставляются самим Битриксом при отображении, как и
// в основном тексте описания.
$META_DESCRIPTION_TEMPLATES = [
    "Купить {{NAME}} по выгодной цене в #REGION_NAME_DECLINE_PP#. В наличии на складе, доставка по России. Тел: #REGION_PHONE#.",
    "{{NAME}} - заказать со склада в #REGION_NAME_DECLINE_PP# по низкой цене. Консультация и расчёт: #REGION_PHONE#.",
    "Заказать {{NAME}} в #REGION_NAME_DECLINE_PP# от производителя. Наличие, доставка по РФ. Звоните: #REGION_PHONE#.",
    "{{NAME}} по низкой цене в #REGION_NAME_DECLINE_PP#. Отгрузка со склада, доставка в регионы. #REGION_PHONE#.",
    "Приобрести {{NAME}} в #REGION_NAME_DECLINE_PP# по выгодной цене от производителя. Подробнее: #REGION_PHONE#.",
    "{{NAME}} - купить недорого в #REGION_NAME_DECLINE_PP#. Собственное производство, доставка по России. #REGION_PHONE#.",
    "Купить {{NAME}} от производителя в #REGION_NAME_DECLINE_PP#. Наличие на складе. Уточнить цену: #REGION_PHONE#.",
    "{{NAME}} со склада в #REGION_NAME_DECLINE_PP# - выгодная цена и быстрая отгрузка. Звоните: #REGION_PHONE#.",
    "Заказать {{NAME}} по низкой цене в #REGION_NAME_DECLINE_PP#. Доставка по всей России. Телефон: #REGION_PHONE#.",
    "{{NAME}} в наличии в #REGION_NAME_DECLINE_PP# - выгодные условия поставки от производителя. #REGION_PHONE#.",
];

function pickMetaDescriptionTemplate($productId)
{
    global $META_DESCRIPTION_TEMPLATES;
    $index = ((int)$productId) % count($META_DESCRIPTION_TEMPLATES);
    return $META_DESCRIPTION_TEMPLATES[$index];
}

function buildMetaDescription($product)
{
    $template = pickMetaDescriptionTemplate($product["id"]);
    // #REGION_...# макросы НЕ трогаем — их подставляет сам Битрикс (EWP-модуль)
    // при отображении страницы, в зависимости от поддомена/региона.
    return str_replace("{{NAME}}", $product["name"], $template);
}


define("SITE_HOST", "1stall.ru");
define("SITE_PROTOCOL", "https");

// ==================== СБОР ДАННЫХ О ТОВАРЕ ====================

function reconnectDatabase()
{
    global $DB;
    try {
        if (is_object($DB) && method_exists($DB, "Disconnect")) {
            $DB->Disconnect();
        }
    } catch (\Throwable $ignore) {
        // игнорируем — соединение и так уже нерабочее
    }
    // Явный Connect() не вызываем: в этой версии Битрикса он требует
    // 4 обязательных параметра (хост/логин/пароль/БД) и падает без них.
    // После Disconnect() Битрикс сам устанавливает новое соединение
    // автоматически при следующем запросе к базе.
}

function getElementIdsBySection($sectionId)
{
    fwrite(STDERR, "[debug] Начинаю GetList по разделу {$sectionId}, IBLOCK_ID=" . PRODUCT_IBLOCK_ID . "\n");
    $ids = [];
    $filter = [
        "IBLOCK_ID" => PRODUCT_IBLOCK_ID,
        "SECTION_ID" => $sectionId,
        "ACTIVE" => "Y",
    ];

    $res = CIBlockElement::GetList([], $filter, false, false, ["ID", "DETAIL_TEXT"]);
    fwrite(STDERR, "[debug] GetList вызван, начинаю Fetch()\n");
    $count = 0;
    while ($row = $res->Fetch()) {
        $count++;
        if ($count % 50 === 0) {
            fwrite(STDERR, "[debug] обработано строк: {$count}\n");
        }
        if (SKIP_IF_HAS_DETAIL_TEXT && trim($row["DETAIL_TEXT"]) !== "") {
            continue;
        }
        $ids[] = $row["ID"];
    }
    fwrite(STDERR, "[debug] Fetch завершён, всего строк: {$count}, отобрано ID: " . count($ids) . "\n");

    return $ids;
}

function getProductContext($elementId)
{
    $res = CIBlockElement::GetList(
        [],
        ["ID" => $elementId, "IBLOCK_ID" => PRODUCT_IBLOCK_ID],
        false,
        false,
        // ВАЖНО: IBLOCK_ID должен быть в SELECT, а не только в фильтре —
        // без него GetNextElement()/GetProperties() у части элементов
        // (замечено на catalogaudit) не может определить набор свойств и
        // молча возвращает пустоту, хотя в Битриксе свойства реально
        // заполнены (проверено debugproduct на ID 128517).
        ["ID", "IBLOCK_ID", "NAME", "IBLOCK_SECTION_ID", "PREVIEW_TEXT", "DETAIL_TEXT"]
    );

    if (!$el = $res->GetNextElement()) {
        return null;
    }

    $fields = $el->GetFields();
    $properties = $el->GetProperties();

    $propList = [];
    foreach ($properties as $code => $prop) {
        if (empty($prop["~VALUE"]) && empty($prop["VALUE"])) {
            continue;
        }
        $value = is_array($prop["VALUE"]) ? implode(", ", $prop["VALUE"]) : $prop["VALUE"];
        // Если свойство — справочник (список), берём читаемое значение
        if (!empty($prop["VALUE_ENUM"])) {
            $value = is_array($prop["VALUE_ENUM"]) ? implode(", ", $prop["VALUE_ENUM"]) : $prop["VALUE_ENUM"];
        }
        if ($value === "" || $value === null) {
            continue;
        }
        $propList[$prop["NAME"]] = $value;
    }

    // Название раздела — пригодится для контекста (категория товара)
    $sectionName = "";
    if ($fields["IBLOCK_SECTION_ID"]) {
        $sec = CIBlockSection::GetByID($fields["IBLOCK_SECTION_ID"])->Fetch();
        $sectionName = $sec["NAME"] ?? "";
    }

    return [
        "id" => $fields["ID"],
        "name" => $fields["NAME"],
        "section" => $sectionName,
        "properties" => $propList,
        "existing_text" => trim(strip_tags($fields["DETAIL_TEXT"] ?? "")),
        "existing_text_html" => $fields["DETAIL_TEXT"] ?? "",
    ];
}

function renderRewriteBlock($product)
{
    if (empty($product["existing_text"])) {
        return "";
    }
    $path = PROMPTS_DIR . "/rewrite_block.txt";
    if (!file_exists($path)) {
        return ""; // если файл случайно удалён — просто пропускаем блок, не падаем
    }
    $template = file_get_contents($path);
    return str_replace("{{EXISTING_TEXT}}", $product["existing_text"], $template) . "\n";
}

function getMappingFile()
{
    return PROMPTS_DIR . "/section_mapping.json";
}

function loadSectionPromptFiles()
{
    $file = getMappingFile();
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveSectionPromptFiles($mapping)
{
    if (!is_dir(PROMPTS_DIR)) {
        if (!@mkdir(PROMPTS_DIR, 0755, true)) {
            throw new \RuntimeException("Не удалось создать папку " . PROMPTS_DIR . " (проверь права доступа)");
        }
    }
    $result = @file_put_contents(getMappingFile(), json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($result === false) {
        throw new \RuntimeException("Не удалось записать " . getMappingFile() . " (проверь права доступа на папку prompts/)");
    }
}

function getPromptFilePath($sectionId, $sectionPromptFiles)
{
    if (isset($sectionPromptFiles[$sectionId])) {
        $path = PROMPTS_DIR . "/" . $sectionPromptFiles[$sectionId];
        if (file_exists($path)) {
            return $path;
        }
    }
    return PROMPTS_DIR . "/default.txt";
}

// ---- Отдельное пространство промптов для страниц умного фильтра ----
// Полностью независимо от товарных промптов: один и тот же раздел может
// иметь свой промпт для описания ОДНОГО товара и отдельный, другой промпт
// для описания СТРАНИЦЫ-ЛИСТИНГА по комбинации параметров фильтра.

function getSmartFilterMappingFile()
{
    return SMARTFILTER_PROMPTS_DIR . "/section_mapping.json";
}

function loadSmartFilterPromptFiles()
{
    $file = getSmartFilterMappingFile();
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveSmartFilterPromptFiles($mapping)
{
    if (!is_dir(SMARTFILTER_PROMPTS_DIR)) {
        if (!@mkdir(SMARTFILTER_PROMPTS_DIR, 0755, true)) {
            throw new \RuntimeException("Не удалось создать папку " . SMARTFILTER_PROMPTS_DIR . " (проверь права доступа)");
        }
    }
    $result = @file_put_contents(getSmartFilterMappingFile(), json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($result === false) {
        throw new \RuntimeException("Не удалось записать " . getSmartFilterMappingFile() . " (проверь права доступа на папку prompts/smartfilter/)");
    }
}

function getSmartFilterPromptFilePath($sectionId, $mapping)
{
    if (isset($mapping[$sectionId])) {
        $path = SMARTFILTER_PROMPTS_DIR . "/" . $mapping[$sectionId];
        if (file_exists($path)) {
            return $path;
        }
    }
    return SMARTFILTER_PROMPTS_DIR . "/default.txt";
}

// ==================== СОРТАМЕНТ ДВУТАВРОВЫХ БАЛОК ====================
// Справочные геометрические данные (h/b/s/t/масса 1 метра) по СТО АСЧМ 20-93
// (сортамент идентичен ГОСТ 26020-83) и ГОСТ 19425-74 (серия М). Числа берём
// ТОЛЬКО из этой проверенной таблицы — модели их сочинять запрещено (см.
// buildDvutavrSpecsExtraBlock/renderDvutavrSpecsBlock). Момент инерции и
// момент сопротивления сюда намеренно не включены — надёжного источника нет,
// а неверная цифра в прочностной характеристике опаснее неверной массы.

function loadDvutavrSortament()
{
    static $table = null;
    if ($table === null) {
        $table = file_exists(SORTAMENT_DVUTAVR_FILE) ? require SORTAMENT_DVUTAVR_FILE : [];
    }
    return $table;
}

// Приводит значение свойства "Типоразмер" (например "60Б", "20 Ш1", "36м")
// к ключу таблицы сортамента ("60Б", "20Ш1", "36М").
function normalizeDvutavrDesignation($raw)
{
    $s = mb_strtoupper(trim((string)$raw), "UTF-8");
    $s = str_replace([" ", "\xC2\xA0", "-"], "", $s);
    return $s;
}

// Ищет профиль ТОЛЬКО по точному совпадению с ключом таблицы. Если в
// свойстве "Типоразмер" нет цифры варианта (например "60Б" вместо "60Б1"/
// "60Б2" — а у них разная масса, разница может быть больше 10%), мы НЕ
// угадываем вариант — возвращаем null и это попадает в лог как
// "неоднозначный типоразмер", чтобы ты вручную сверил с факту наличия и
// donec поправил свойство в Битриксе. Публиковать на сайт возможно неверную
// массу балки нельзя — это может исказить расчёт стоимости/логистики у
// покупателя.
function findDvutavrSpecs($typorazmerRaw)
{
    $table = loadDvutavrSortament();
    $designation = normalizeDvutavrDesignation($typorazmerRaw);
    if ($designation === "" || !isset($table[$designation])) {
        return null;
    }
    return ["specs" => $table[$designation], "designation" => $designation];
}

// Достаёт число метров из свойства "Длина" (например "12 м" -> 12.0).
function parseLengthMeters($lengthRaw)
{
    if (preg_match('/(\d+(?:[.,]\d+)?)/u', (string)$lengthRaw, $m)) {
        return (float)str_replace(",", ".", $m[1]);
    }
    return null;
}

// Формирует ФАКТЫ для подстановки во входные данные промпта (модель может
// их упомянуть своими словами, но не имеет права придумывать другие числа).
function buildDvutavrSpecsFactsText($specsInfo, $lengthMeters)
{
    $specs = $specsInfo["specs"];
    $area = round($specs["mass"] / 0.785, 2);
    $lines = [
        "Высота профиля (h): {$specs['h']} мм",
        "Ширина полки (b): {$specs['b']} мм",
        "Толщина стенки (s): {$specs['s']} мм",
        "Толщина полки (t): {$specs['t']} мм",
        "Площадь сечения: ~{$area} см2 (расчётная величина, не табличная)",
        "Масса 1 метра: {$specs['mass']} кг",
    ];
    if ($lengthMeters) {
        $totalMass = round($specs["mass"] * $lengthMeters, 1);
        $lines[] = "Масса балки длиной {$lengthMeters} м: ~{$totalMass} кг";
    }
    return implode("\n", $lines);
}

// Формирует ГОТОВЫЙ HTML-блок с теми же цифрами — его подставляем в текст
// КОДОМ, после ответа модели, а не полагаемся на то, что модель перепишет
// цифры без ошибок (см. runReview).
function renderDvutavrSpecsBlock($specsInfo, $lengthMeters)
{
    $specs = $specsInfo["specs"];
    $area = round($specs["mass"] / 0.785, 2);
    $rows = "<li>Высота профиля (h): {$specs['h']} мм</li>"
        . "<li>Ширина полки (b): {$specs['b']} мм</li>"
        . "<li>Толщина стенки (s): {$specs['s']} мм</li>"
        . "<li>Толщина полки (t): {$specs['t']} мм</li>"
        . "<li>Площадь сечения: ~{$area} см²</li>"
        . "<li>Масса 1 метра: {$specs['mass']} кг</li>";
    if ($lengthMeters) {
        $totalMass = round($specs["mass"] * $lengthMeters, 1);
        $rows .= "<li>Масса балки длиной {$lengthMeters} м: ~{$totalMass} кг</li>";
    }
    return "<p><strong>Геометрия профиля {$specsInfo['designation']}</strong> (сортамент СТО АСЧМ 20-93 / ГОСТ 19425-74):</p>"
        . "<ul>{$rows}</ul>";
}

// Общая точка входа для poиска сортамента по товару — используется и в
// buildPrompt (чтобы дать модели факты), и в runReview (чтобы код сам
// дописал точный HTML-блок после ответа модели). Возвращает null, если
// свойства "Типоразмер" нет или он не найден в таблице сортамента точным
// совпадением.
function getProductDvutavrSpecsInfo($product)
{
    if (empty($product["properties"]["Типоразмер"])) {
        return null;
    }
    $specsInfo = findDvutavrSpecs($product["properties"]["Типоразмер"]);
    if (!$specsInfo) {
        return null;
    }
    $lengthMeters = parseLengthMeters($product["properties"]["Длина"] ?? "");
    return ["specsInfo" => $specsInfo, "lengthMeters" => $lengthMeters];
}

// ==================== СОРТАМЕНТ ШВЕЛЛЕРА ГОРЯЧЕКАТАНОГО (ГОСТ 8240-97) ====================
// В отличие от трубы, у швеллера горячекатаного точную геометрию по формуле
// не посчитать (сложное сечение с уклоном граней), нужен настоящий сортамент
// - как и у балки. Но в отличие от балки, у швеллера в Битриксе НЕТ отдельного
// заполненного свойства "Типоразмер" - номер профиля есть только в НАЗВАНИИ
// товара (например: Швеллер стальной горячекатаный "10П"). Поэтому номер
// достаём из названия регулярным выражением, а не из свойства.

function loadShvellerSortament()
{
    static $table = null;
    if ($table === null) {
        $table = file_exists(SORTAMENT_SHVELLER_FILE) ? require SORTAMENT_SHVELLER_FILE : [];
    }
    return $table;
}

// Извлекает обозначение профиля швеллера ("10П", "16аУ", "6,5У" и т.п.) из
// названия товара и приводит его к ключу таблицы сортамента ("10P", "16AU",
// "6.5U"). Если в названии нет чёткого совпадения с этим форматом (например,
// это гнутый швеллер с размерами в мм вида "270x80x60") - возвращает null,
// и это НЕ ошибка, просто эта функция для него не применяется.
function extractShvellerDesignationFromName($name)
{
    $upper = mb_strtoupper((string)$name, "UTF-8");
    if (!preg_match('/(\d+(?:[.,]\d+)?)(А)?(У|П)\b/u', $upper, $m)) {
        return null;
    }
    $num = str_replace(",", ".", $m[1]);
    $variant = ($m[2] === "А") ? $num . "A" : $num;
    $seriesLetter = ($m[3] === "У") ? "U" : "P";
    return $variant . $seriesLetter;
}

// Точное совпадение с ключом таблицы, без угадывания - тот же принцип, что и
// у findDvutavrSpecs.
function findShvellerSpecs($designationKey)
{
    if ($designationKey === null) {
        return null;
    }
    $table = loadShvellerSortament();
    if (!isset($table[$designationKey])) {
        return null;
    }
    return ["specs" => $table[$designationKey], "designation" => $designationKey];
}

function buildShvellerSpecsFactsText($specsInfo, $lengthMeters)
{
    $specs = $specsInfo["specs"];
    $lines = [
        "Высота профиля (h): {$specs['h']} мм",
        "Ширина полки (b): {$specs['b']} мм",
        "Толщина стенки (s): {$specs['s']} мм",
        "Толщина полки (t): {$specs['t']} мм",
        "Масса 1 метра: {$specs['mass']} кг",
    ];
    if ($lengthMeters) {
        $totalMass = round($specs["mass"] * $lengthMeters, 1);
        $lines[] = "Масса швеллера длиной {$lengthMeters} м: ~{$totalMass} кг";
    }
    return implode("\n", $lines);
}

function renderShvellerSpecsBlock($specsInfo, $lengthMeters)
{
    $specs = $specsInfo["specs"];
    $rows = "<li>Высота профиля (h): {$specs['h']} мм</li>"
        . "<li>Ширина полки (b): {$specs['b']} мм</li>"
        . "<li>Толщина стенки (s): {$specs['s']} мм</li>"
        . "<li>Толщина полки (t): {$specs['t']} мм</li>"
        . "<li>Масса 1 метра: {$specs['mass']} кг</li>";
    if ($lengthMeters) {
        $totalMass = round($specs["mass"] * $lengthMeters, 1);
        $rows .= "<li>Масса швеллера длиной {$lengthMeters} м: ~{$totalMass} кг</li>";
    }
    return "<p><strong>Геометрия профиля {$specsInfo['designation']}</strong> (сортамент ГОСТ 8240-97):</p>"
        . "<ul>{$rows}</ul>";
}

// Общая точка входа - как getProductDvutavrSpecsInfo, но номер профиля берём
// из названия товара, а не из свойства.
function getProductShvellerSpecsInfo($product)
{
    $designation = extractShvellerDesignationFromName($product["name"]);
    if ($designation === null) {
        return null;
    }
    $specsInfo = findShvellerSpecs($designation);
    if (!$specsInfo) {
        return null;
    }
    $lengthMeters = parseLengthMeters($product["properties"]["Длина"] ?? "");
    return ["specsInfo" => $specsInfo, "lengthMeters" => $lengthMeters];
}

// ==================== ТРУБЫ (КРУГЛЫЕ И ПРОФИЛЬНЫЕ) — РАСЧЁТ ПО ФОРМУЛЕ ====================
// В отличие от балки/швеллера, у трубы (круглой и квадратной/прямоугольной)
// нет "типоразмера" со скрытой геометрией — диаметр/высота/ширина/стенка это
// отдельные заполненные числовые поля Битрикса у любого материала. Поэтому
// массу метра можно посчитать точной инженерной формулой, а не искать в
// справочнике. Формула проверена на стандартном примере: труба 108x4 мм
// (сталь) даёт по формуле ~10,26 кг/м — это совпадает с табличными
// значениями сортамента по ГОСТ 10704. Момент инерции/сопротивления здесь
// намеренно не считаем и не публикуем — это не тривиальная формула для
// трубы с учётом допусков, риск того же рода, что и с балкой.

// Плотность материала по ключевым словам в НАЗВАНИИ РАЗДЕЛА (section из
// getProductContext). Значения — стандартные табличные плотности материалов,
// не марки конкретного сплава. Для латуни/бронзы плотность заметно скачет
// от марки к марке (сплавы на основе меди с разным легированием), поэтому
// осознанно НЕ считаем массу для этих двух материалов, пока нет точного
// сплава с проверенным значением — возвращаем null, блок просто не добавится.
function resolvePipeMaterialDensity($sectionName)
{
    $s = mb_strtolower((string)$sectionName, "UTF-8");
    if ($s === "") {
        return null;
    }
    if (mb_strpos($s, "латун") !== false || mb_strpos($s, "бронз") !== false) {
        return null; // плотность сильно зависит от марки сплава — не угадываем
    }
    if (mb_strpos($s, "нержав") !== false) {
        return ["value" => 7.9, "label" => "нержавеющая сталь"];
    }
    if (mb_strpos($s, "алюмин") !== false || mb_strpos($s, "дюрал") !== false) {
        return ["value" => 2.70, "label" => "алюминиевый сплав"];
    }
    if (mb_strpos($s, "медн") !== false || mb_strpos($s, "медь") !== false) {
        return ["value" => 8.94, "label" => "медь"];
    }
    if (mb_strpos($s, "титан") !== false) {
        return ["value" => 4.50, "label" => "титан"];
    }
    // ВАЖНО: "оцинкованная" (труба/лист с цинковым ПОКРЫТИЕМ) содержит
    // подстроку "цинк", но материал изделия при этом остаётся сталью —
    // цинк это только тонкий защитный слой, он не меняет плотность трубы.
    // Поэтому "оцинков..." проверяем ДО чистого "цинк" и отдаём стали.
    if (mb_strpos($s, "оцинков") !== false) {
        return ["value" => 7.85, "label" => "оцинкованная сталь"];
    }
    if (mb_strpos($s, "цинк") !== false) {
        return ["value" => 7.14, "label" => "цинк"];
    }
    if (mb_strpos($s, "свинц") !== false || mb_strpos($s, "свинец") !== false) {
        return ["value" => 11.34, "label" => "свинец"];
    }
    // По умолчанию — сталь (углеродистая/низколегированная). Плотность разных
    // марок стали отличается на доли процента, поэтому одно значение безопасно.
    return ["value" => 7.85, "label" => "сталь"];
}

// Достаёт первое число из строки свойства ("108", "108 мм", "1,5" -> 1.5).
function parseFirstNumber($raw)
{
    if (preg_match('/(\d+(?:[.,]\d+)?)/u', (string)$raw, $m)) {
        return (float)str_replace(",", ".", $m[1]);
    }
    return null;
}

// Масса 1 метра круглой трубы: площадь кольца стенки * плотность.
// Проверено на 108x4 мм сталь: (108-4)*4*pi*7.85/1000 = ~10.26 кг/м.
function computeRoundPipeMassPerMeter($diameterMm, $wallMm, $density)
{
    if ($diameterMm <= 0 || $wallMm <= 0 || $wallMm * 2 >= $diameterMm) {
        return null; // нефизичные значения (стенка больше радиуса) — не считаем
    }
    $areaMm2 = M_PI * ($diameterMm - $wallMm) * $wallMm;
    return round($areaMm2 * $density / 1000, 3);
}

// Масса 1 метра профильной (квадратной/прямоугольной) трубы — приближённая
// инженерная формула по периметру средней линии стенки (без учёта скругления
// углов, поэтому чуть завышает результат на небольшой процент — это обычная
// практика в отраслевых калькуляторах массы профильной трубы).
function computeProfilePipeMassPerMeter($heightMm, $widthMm, $wallMm, $density)
{
    if ($heightMm <= 0 || $widthMm <= 0 || $wallMm <= 0) {
        return null;
    }
    $areaMm2 = 2 * ($heightMm + $widthMm - 2 * $wallMm) * $wallMm;
    if ($areaMm2 <= 0) {
        return null;
    }
    return round($areaMm2 * $density / 1000, 3);
}

// Общая точка входа для трубы — как getProductDvutavrSpecsInfo, но вместо
// поиска в таблице считаем по формуле. Возвращает null, если данных не
// хватает (нет диаметра/размеров, нет стенки, материал не распознан, или
// заполнено "Типоразмер" — тогда это не труба, а профиль с сортаментом типа
// балки/швеллера, и мы не хотим случайно пересечься с этой логикой).
function getProductPipeSpecsInfo($product)
{
    $props = $product["properties"];
    if (!empty($props["Типоразмер"])) {
        return null;
    }
    $wall = parseFirstNumber($props["Стенка, мм"] ?? "");
    if ($wall === null || $wall <= 0) {
        return null;
    }
    $density = resolvePipeMaterialDensity($product["section"]);
    if ($density === null) {
        return null;
    }
    $diameter = parseFirstNumber($props["Диаметр, мм"] ?? "");
    $height = parseFirstNumber($props["Высота, мм"] ?? "");
    $width = parseFirstNumber($props["Ширина, мм"] ?? "");
    if ($diameter !== null && $diameter > 0) {
        $mass = computeRoundPipeMassPerMeter($diameter, $wall, $density["value"]);
        if ($mass === null) {
            return null;
        }
        $shape = "round";
    } elseif ($height !== null && $width !== null && $height > 0 && $width > 0) {
        $mass = computeProfilePipeMassPerMeter($height, $width, $wall, $density["value"]);
        if ($mass === null) {
            return null;
        }
        $shape = "profile";
    } else {
        return null;
    }
    $lengthMeters = parseLengthMeters($props["Длина"] ?? "");
    return [
        "shape" => $shape,
        "diameter" => $diameter,
        "height" => $height,
        "width" => $width,
        "wall" => $wall,
        "mass" => $mass,
        "density" => $density,
        "lengthMeters" => $lengthMeters,
    ];
}

function buildPipeSpecsFactsText($info)
{
    $lines = [];
    if ($info["shape"] === "round") {
        $lines[] = "Наружный диаметр: {$info['diameter']} мм";
    } else {
        $lines[] = "Высота профиля: {$info['height']} мм";
        $lines[] = "Ширина профиля: {$info['width']} мм";
    }
    $lines[] = "Толщина стенки: {$info['wall']} мм";
    $lines[] = "Материал (для расчёта массы): {$info['density']['label']}";
    $lines[] = "Масса 1 метра: ~{$info['mass']} кг (расчётная величина по геометрии, не табличная)";
    if ($info["lengthMeters"]) {
        $totalMass = round($info["mass"] * $info["lengthMeters"], 1);
        $lines[] = "Масса при длине {$info['lengthMeters']} м: ~{$totalMass} кг";
    }
    return implode("\n", $lines);
}

function renderPipeSpecsBlock($info)
{
    $rows = "";
    if ($info["shape"] === "round") {
        $rows .= "<li>Наружный диаметр: {$info['diameter']} мм</li>";
    } else {
        $rows .= "<li>Высота профиля: {$info['height']} мм</li>"
            . "<li>Ширина профиля: {$info['width']} мм</li>";
    }
    $rows .= "<li>Толщина стенки: {$info['wall']} мм</li>"
        . "<li>Расчётная масса 1 метра: ~{$info['mass']} кг</li>";
    if ($info["lengthMeters"]) {
        $totalMass = round($info["mass"] * $info["lengthMeters"], 1);
        $rows .= "<li>Расчётная масса при длине {$info['lengthMeters']} м: ~{$totalMass} кг</li>";
    }
    return "<p><strong>Расчётные параметры</strong> (масса вычислена по геометрии и плотности материала "
        . "{$info['density']['label']}, ~{$info['density']['value']} г/см³ — приближённое значение, "
        . "не заводской сортамент):</p><ul>{$rows}</ul>";
}

function buildPrompt($product, $sectionId, $extraInstructions, $sectionPromptFiles)
{
    $propsText = "";
    foreach ($product["properties"] as $name => $value) {
        $propsText .= "- {$name}: {$value}\n";
    }

    $promptFile = getPromptFilePath($sectionId, $sectionPromptFiles);

    if (!file_exists($promptFile)) {
        return "ОШИБКА КОНФИГУРАЦИИ: файл промпта не найден: {$promptFile}";
    }

    $template = file_get_contents($promptFile);

    $extraBlock = "";
    if (!empty($extraInstructions)) {
        $extraBlock = "\nОсобенности именно этой категории товара (обязательно учти):\n{$extraInstructions}\n";
    }

    // Для двутавровых балок с распознанным типоразмером (точное совпадение с
    // таблицей сортамента) добавляем к входным данным реальные геометрические
    // факты — вес метра, площадь сечения, вес всей балки при известной длине.
    // Эти же цифры код добавит в HTML ПОСЛЕ ответа модели (см. runReview) —
    // здесь они только для того, чтобы модель могла упомянуть их текстом.
    $dvutavrLookup = getProductDvutavrSpecsInfo($product);
    if ($dvutavrLookup) {
        $extraBlock .= "\nТочные геометрические данные профиля (из сортамента, используй как факты, "
            . "других числовых характеристик профиля не добавляй):\n"
            . buildDvutavrSpecsFactsText($dvutavrLookup["specsInfo"], $dvutavrLookup["lengthMeters"]) . "\n";
    }

    // Для труб (круглых и профильных) с распознанными диаметром/размерами и
    // стенкой считаем массу метра по формуле и даём модели как факты — тот
    // же готовый HTML-блок код добавит в текст ПОСЛЕ ответа модели (runReview).
    $pipeLookup = getProductPipeSpecsInfo($product);
    if ($pipeLookup) {
        $extraBlock .= "\nРасчётные геометрические данные трубы (посчитаны по формуле, используй как факты, "
            . "других числовых характеристик не добавляй):\n"
            . buildPipeSpecsFactsText($pipeLookup) . "\n";
    }

    // Для швеллера горячекатаного с номером профиля, распознанным в названии
    // и найденным точным совпадением в сортаменте ГОСТ 8240-97.
    $shvellerLookup = getProductShvellerSpecsInfo($product);
    if ($shvellerLookup) {
        $extraBlock .= "\nТочные геометрические данные профиля (из сортамента, используй как факты, "
            . "других числовых характеристик профиля не добавляй):\n"
            . buildShvellerSpecsFactsText($shvellerLookup["specsInfo"], $shvellerLookup["lengthMeters"]) . "\n";
    }

    $rewriteBlock = renderRewriteBlock($product);

    $replacements = [
        "{{ID}}" => $product["id"],
        "{{NAME}}" => $product["name"],
        "{{SECTION}}" => $product["section"],
        "{{PROPS}}" => $propsText,
        "{{EXTRA_BLOCK}}" => $extraBlock,
        "{{REWRITE_BLOCK}}" => $rewriteBlock,
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

function buildSmartFilterPrompt($virtualProduct, $sectionId, $mapping)
{
    $propsText = "";
    foreach ($virtualProduct["properties"] as $name => $value) {
        $propsText .= "- {$name}: {$value}\n";
    }

    $promptFile = getSmartFilterPromptFilePath($sectionId, $mapping);

    if (!file_exists($promptFile)) {
        return "ОШИБКА КОНФИГУРАЦИИ: файл промпта не найден: {$promptFile}";
    }

    $template = file_get_contents($promptFile);

    $replacements = [
        "{{ID}}" => $virtualProduct["id"],
        "{{NAME}}" => $virtualProduct["name"],
        "{{SECTION}}" => $virtualProduct["section"],
        "{{PROPS}}" => $propsText,
        "{{EXTRA_BLOCK}}" => "",
        "{{REWRITE_BLOCK}}" => "", // у виртуальных страниц фильтра никогда нет существующего текста
    ];

    $renderedPrompt = str_replace(array_keys($replacements), array_values($replacements), $template);

    // Требуем сгенерировать ПОЛНОСТЬЮ готовые Title/H1/Description —
    // не только описательную часть (как было раньше), а весь текст целиком,
    // включая регион/телефон/бренд — но эти макросы модель должна
    // СКОПИРОВАТЬ ДОСЛОВНО из примера ниже, не сочинять и не переводить.
    // Добавляем это программно, в КАЖДЫЙ запрос — а не полагаясь на то, что
    // инструкция есть в каждом файле промпта (их несколько, привязанных к
    // разным разделам). Блок ставим В КОНЕЦ ответа (после текста страницы),
    // а не в начало: cleanGeneratedText() обрезает всё, что стоит ДО
    // первого HTML-тега <p>/<h2>/<h3>/<ul> — если бы блок шёл первым, эта
    // же функция вырезала бы его до того, как мы успеем его прочитать.
    $renderedPrompt .= "\n\n---\n"
        . "ДОПОЛНИТЕЛЬНО (обязательно): в самом конце ответа, СРАЗУ ПОСЛЕ всего "
        . "текста страницы (после последнего закрывающего тега), выведи готовые "
        . "Title, H1 и Description для этой страницы фильтра.\n"
        . "Жёсткие требования (только они обязательны, остальное — на твоё "
        . "усмотрение):\n"
        . "1. КАЖДАЯ из трёх строк должна НАЧИНАТЬСЯ с названия страницы "
        . "(категория + характеристики выборки, например \"Арматура сталь "
        . "09Г2С\" или \"Труба нержавеющая 22х3 мм\") — это первое слово/слова "
        . "в каждой строке.\n"
        . "2. К названию страницы применяются ВСЕ те же правила и запреты, что "
        . "и к основному тексту выше, включая любые прямые указания из этого "
        . "промпта о том, что нельзя использовать.\n"
        . "3. Макросы #REGION_NAME_DECLINE_PP# и #REGION_PHONE# — это служебные "
        . "плейсхолдеры сайта (не переводи, не изменяй, не выдумывай других) — "
        . "скопируй их СИМВОЛ В СИМВОЛ там, где они уместны по смыслу (Title/H1 "
        . "— город, Description — город и телефон).\n"
        . "4. Фразу \"Первый Стальной Комбинат\" в Title не убирай и не меняй.\n"
        . "5. Без HTML-тегов, каждое поле — одной строкой.\n"
        . "6. Используй только обычный дефис \"-\". Символ длинного тире \"—\" в этих "
        . "трёх строках запрещён (в основном тексте страницы это правило тоже "
        . "действует отдельно, если оно есть в промпте выше).\n"
        . "ВАЖНО про уникальность: НЕ копируй формулировки из примера ниже "
        . "дословно и не собирай Title/H1/Description по одному и тому же "
        . "словесному шаблону для разных страниц фильтра — если сотни страниц "
        . "получат одинаковую структуру фразы с одним поменянным словом, это "
        . "будет выглядеть как спам и в поиске, и для покупателя. Меняй порядок "
        . "слов, формулировки, глаголы (купить/заказать/приобрести), можно "
        . "добавлять уместные детали (сорт, ГОСТ, назначение), — придумывай "
        . "текст заново под конкретные характеристики этой страницы, а не "
        . "переставляй слова в одном и том же каркасе. Пример ниже — только "
        . "чтобы показать формат маркеров и обязательные элементы (название "
        . "первым словом, макросы, бренд), а не готовая фраза для копирования:\n"
        . "[TITLE]Арматура сталь 09Г2С купить в #REGION_NAME_DECLINE_PP# | Первый Стальной Комбинат[/TITLE]\n"
        . "[H1]Арматура сталь 09Г2С в #REGION_NAME_DECLINE_PP#[/H1]\n"
        . "[DESCRIPTION]Арматура сталь 09Г2С купить в #REGION_NAME_DECLINE_PP# по цене оптом и в розницу. Доставка по России и СНГ со склада или под заказ. Звоните #REGION_PHONE# или оставьте заявку на сайте![/DESCRIPTION]";

    return $renderedPrompt;
}

// Короткий повторный запрос ТОЛЬКО за Title/H1/Description — используется,
// когда основной ответ модели содержит хороший текст страницы, но забыл
// добавить блоки [TITLE]/[H1]/[DESCRIPTION] в конце (или ошибся в макросах).
// Так вместо немедленного отката на шаблон даём модели второй шанс — заметно
// дешевле и быстрее полной регенерации страницы целиком.
function buildSmartFilterMetaRetryPrompt($virtualProduct)
{
    $propsText = "";
    foreach ($virtualProduct["properties"] as $name => $value) {
        $propsText .= "- {$name}: {$value}\n";
    }

    return "Для страницы каталога металлопроката \"{$virtualProduct['name']}\" "
        . "(раздел: {$virtualProduct['section']}) нужны Title, H1 и Description.\n\n"
        . "Характеристики выборки:\n{$propsText}\n"
        . "Жёсткие требования:\n"
        . "1. КАЖДАЯ из трёх строк должна НАЧИНАТЬСЯ с названия страницы (категория + "
        . "характеристики выборки) — это первое слово/слова в каждой строке.\n"
        . "2. Без рекламных штампов и канцелярита (\"широкий ассортимент\", \"выгодные "
        . "условия\" и т.п.).\n"
        . "3. Макросы #REGION_NAME_DECLINE_PP# и #REGION_PHONE# — служебные плейсхолдеры "
        . "сайта (не переводи, не изменяй, не выдумывай других) — скопируй их СИМВОЛ В "
        . "СИМВОЛ там, где уместны по смыслу (Title/H1 — город, Description — город и "
        . "телефон).\n"
        . "4. Фразу \"Первый Стальной Комбинат\" в Title не убирай и не меняй.\n"
        . "5. Без HTML-тегов, каждое поле — одной строкой.\n"
        . "6. Придумай формулировку заново под ЭТИ характеристики, не собирай по шаблону "
        . "\"название + купить в городе + по выгодной цене\" — меняй структуру фразы.\n"
        . "7. Используй только обычный дефис \"-\". Символ длинного тире \"—\" запрещён "
        . "везде в этих трёх строках.\n\n"
        . "Выведи ТОЛЬКО эти три строки, больше ничего:\n"
        . "[TITLE]...[/TITLE]\n[H1]...[/H1]\n[DESCRIPTION]...[/DESCRIPTION]";
}

// Достаёт готовые Title/H1/Description из блоков [TITLE]/[H1]/[DESCRIPTION]
// в ответе модели (после cleanGeneratedText) и отдельно возвращает
// оставшийся текст страницы без этих блоков. Блоки вырезаются из текста
// ВСЕГДА, как только найдены — даже если сами значения не прошли проверку
// ниже — чтобы сырые маркеры никогда не остались видны на живой странице.
// Если хотя бы одно поле не прошло проверку (пустое, подозрительно длинное,
// или модель ошиблась/забыла скопировать нужный макрос) — возвращает null
// вместо полей целиком (не публикуем частично битые метатеги), и вызывающий
// код должен откатиться на старый шаблонный вариант.
function extractSmartFilterMetaFields($cleanedText)
{
    $patterns = [
        "title" => '/\[TITLE\](.*?)\[\/TITLE\]/is',
        "h1" => '/\[H1\](.*?)\[\/H1\]/is',
        "description" => '/\[DESCRIPTION\](.*?)\[\/DESCRIPTION\]/is',
    ];

    $bodyWithoutMeta = $cleanedText;
    $fields = [];
    $foundAny = false;

    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $cleanedText, $m)) {
            $foundAny = true;
            $value = trim(strip_tags($m[1]));
            $value = preg_replace('/\s+/u', ' ', $value);
            // Страховка независимо от послушания модели: длинное тире и похожие
            // символы (— – ―) заменяем на обычный дефис "-" в meta-полях.
            $value = str_replace(["—", "–", "―"], "-", $value);
            $fields[$key] = $value;
            // Вырезаем найденный блок целиком (маркеры + содержимое) из
            // текста страницы — независимо от того, что мы решим сделать
            // с самим значением ниже.
            $bodyWithoutMeta = str_replace($m[0], '', $bodyWithoutMeta);
        }
    }

    $bodyWithoutMeta = preg_replace('/<p>\s*<\/p>/i', '', $bodyWithoutMeta);
    $bodyWithoutMeta = trim($bodyWithoutMeta);
    if ($bodyWithoutMeta === "") {
        // Подстраховка от пустой страницы (маловероятный сценарий, если
        // блоки были вообще единственным содержимым ответа).
        $bodyWithoutMeta = $cleanedText;
    }

    if (!$foundAny) {
        return [null, $bodyWithoutMeta];
    }

    // Проверка на живучесть макросов — самое опасное место: если модель
    // опечаталась в имени макроса или вообще забыла его вставить, Битрикс
    // не распознает плейсхолдер, и на живой странице вместо города/телефона
    // повиснет мусор или пустота у ВСЕХ 50+ поддоменов. Поэтому при малейшем
    // отклонении откатываемся на старый способ целиком, для всех трёх полей
    // сразу (не смешиваем "хорошие" и "битые" поля на одной странице).
    $regionMacro = "#REGION_NAME_DECLINE_PP#";
    $phoneMacro = "#REGION_PHONE#";

    $ok = isset($fields["title"], $fields["h1"], $fields["description"])
        && $fields["title"] !== "" && mb_strlen($fields["title"]) <= 300
        && $fields["h1"] !== "" && mb_strlen($fields["h1"]) <= 300
        && $fields["description"] !== "" && mb_strlen($fields["description"]) <= 400
        && strpos($fields["title"], $regionMacro) !== false
        && strpos($fields["h1"], $regionMacro) !== false
        && strpos($fields["description"], $regionMacro) !== false
        && strpos($fields["description"], $phoneMacro) !== false;

    if (!$ok) {
        return [null, $bodyWithoutMeta];
    }

    return [$fields, $bodyWithoutMeta];
}


// ==================== ВЫЗОВ CLAUDE API ====================

// Основной и резервный адрес шлюза router.cheap. Если один не отвечает
// (таймаут/обрыв соединения/5xx), пробуем другой - чередуем их по попыткам,
// а не бьёмся в одну и ту же точку 3 раза подряд.
define("ROUTER_CHEAP_URL_PRIMARY", "https://router.cheap/v1/messages");
define("ROUTER_CHEAP_URL_RESERVE", "https://direct.router-cheap.com/v1/messages");

function callClaudeAPI($prompt)
{
    // router.cheap ведёт себя нестабильно: один и тот же запрос может либо
    // отработать мгновенно, либо зависнуть без ответа, либо один из двух
    // адресов шлюза может быть временно недоступен. Поэтому делаем
    // несколько попыток и чередуем основной/резервный адрес между ними.
    $maxAttempts = 4;
    $lastError = "";
    $urls = [ROUTER_CHEAP_URL_PRIMARY, ROUTER_CHEAP_URL_RESERVE];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $url = $urls[($attempt - 1) % count($urls)];
        $urlLabel = ($url === ROUTER_CHEAP_URL_PRIMARY) ? "основной" : "резервный";

        if ($attempt > 1) {
            fwrite(STDERR, "[retry] Попытка {$attempt}/{$maxAttempts} ({$urlLabel} адрес)...\n");
            sleep(3);
        }

        $result = callClaudeAPIAttempt($prompt, $url);

        if (!isset($result["error"])) {
            return $result;
        }

        $lastError = "[{$urlLabel}] " . $result["error"];
    }

    return ["error" => "После {$maxAttempts} попыток (основной+резервный адрес): " . $lastError];
}

function callClaudeAPIAttempt($prompt, $url = ROUTER_CHEAP_URL_RESERVE)
{
    $payload = json_encode([
        "model" => ROUTER_CHEAP_MODEL,
        "max_tokens" => 2000,
        "stream" => true,
        "messages" => [
            ["role" => "user", "content" => $prompt],
        ],
    ], JSON_UNESCAPED_UNICODE);

    // Стриминг обязателен: без него шлюз router.cheap буферизует весь
    // ответ и виснет на генерациях, требующих заметного времени (короткие
    // "OK"-ответы проходят и без стриминга — проблема именно в ожидании).
    $accumulatedText = "";
    $sseBuffer = "";
    $streamError = "";
    // Сырое тело ответа копим отдельно от SSE-парсинга: при 401/403/429 и
    // подобных ошибках шлюз обычно отдаёт обычный JSON (не data:-события),
    // и без этой копии мы видим только код 403 без текста причины.
    $rawBody = "";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "x-api-key: " . ROUTER_CHEAP_API_KEY,
            "anthropic-version: 2023-06-01",
            "Accept: text/event-stream",
        ],
        CURLOPT_USERAGENT => "curl/7.88.1",
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        // Ключевая часть: обрабатываем ответ по мере поступления чанков,
        // а не ждём его целиком (CURLOPT_RETURNTRANSFER здесь не используем).
        CURLOPT_WRITEFUNCTION => function ($curlHandle, $chunk) use (&$accumulatedText, &$streamError, &$sseBuffer, &$rawBody) {
            // Сетевые чанки могут обрываться посреди строки SSE-события —
            // без буферизации между вызовами куски JSON теряются/ломаются.
            // Поэтому копим незавершённый хвост в $sseBuffer и обрабатываем
            // только полные строки, оканчивающиеся на \n.
            if (strlen($rawBody) < 2000) {
                $rawBody .= $chunk; // ограничиваем, тело ошибки короткое, стрим текста - нет
            }
            $sseBuffer .= $chunk;

            $lastNewlinePos = strrpos($sseBuffer, "\n");
            if ($lastNewlinePos === false) {
                // Ни одной полной строки ещё не накопилось — ждём следующий чанк.
                return strlen($chunk);
            }

            $completeLines = substr($sseBuffer, 0, $lastNewlinePos);
            $sseBuffer = substr($sseBuffer, $lastNewlinePos + 1); // остаток — в буфер

            foreach (explode("\n", $completeLines) as $line) {
                $line = trim($line);
                if (strpos($line, "data:") !== 0) {
                    continue;
                }
                $jsonStr = trim(substr($line, 5));
                if ($jsonStr === "" || $jsonStr === "[DONE]") {
                    continue;
                }
                $event = json_decode($jsonStr, true);
                if (!is_array($event)) {
                    continue;
                }
                if (($event["type"] ?? "") === "content_block_delta"
                    && ($event["delta"]["type"] ?? "") === "text_delta") {
                    $accumulatedText .= $event["delta"]["text"];
                }
                if (($event["type"] ?? "") === "error") {
                    $streamError = $event["error"]["message"] ?? "unknown stream error";
                }
            }
            return strlen($chunk);
        },
    ]);

    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $rawBodySnippet = trim(preg_replace('/\s+/', ' ', substr($rawBody, 0, 500)));

    if ($curlError) {
        return ["error" => "cURL error: " . $curlError];
    }

    if ($streamError) {
        return ["error" => "API stream error: " . $streamError . ($rawBodySnippet !== "" ? " | тело: " . $rawBodySnippet : "")];
    }

    if ($httpCode !== 200) {
        return ["error" => "API error ({$httpCode}), накоплено символов: " . strlen($accumulatedText) . ($rawBodySnippet !== "" ? " | тело: " . $rawBodySnippet : "")];
    }

    if (trim($accumulatedText) === "") {
        return ["error" => "Пустой ответ от API (стрим завершился без текста)"];
    }

    return ["text" => cleanGeneratedText($accumulatedText)];
}

function cleanGeneratedText($text)
{
    $text = trim($text);
    // Срезаем обёртку ```html ... ``` или ``` ... ```, если модель всё же
    // её добавила, несмотря на инструкцию.
    $text = preg_replace('/^```(?:html)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $text = trim($text);

    // Иногда в начало потока попадает мусорный обрывок (наблюдалось на
    // router.cheap — похоже на огрызок прерванной/повторной генерации на
    // их стороне). Форма 1: текст вообще не начинается с тега.
    if ($text !== "" && $text[0] !== "<") {
        if (preg_match('/<(p|h2|h3|ul)\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, $m[0][1]);
        }
    }

    // Форма 2: короткий обрывок внутри незакрытого тега перед следующим
    // заголовком, например "<p>Ал<h2>Реальный заголовок...</h2>" — верный
    // признак склейки огрызка одной генерации с началом другой.
    if (preg_match('/^<p>[^<]{1,20}(<h[23]\b)/iu', $text, $m2, PREG_OFFSET_CAPTURE)) {
        $text = substr($text, $m2[1][1]);
    }

    return trim($text);
}

// ==================== IndexNow ====================

function ensureIndexNowKeyFile()
{
    $keyFilePath = $_SERVER["DOCUMENT_ROOT"] . "/" . INDEXNOW_KEY . ".txt";
    if (!file_exists($keyFilePath)) {
        file_put_contents($keyFilePath, INDEXNOW_KEY);
    }
    return $keyFilePath;
}

function getProductUrl($elementId)
{
    $res = CIBlockElement::GetList(
        [],
        ["ID" => $elementId, "IBLOCK_ID" => PRODUCT_IBLOCK_ID],
        false,
        false,
        ["ID", "DETAIL_PAGE_URL"]
    );
    if ($row = $res->Fetch()) {
        $path = $row["DETAIL_PAGE_URL"];
        if (empty($path)) {
            return null;
        }
        return SITE_PROTOCOL . "://" . SITE_HOST . $path;
    }
    return null;
}

// ==================== УМНЫЙ ФИЛЬТР: SEO-СТРАНИЦЫ БЕЗ ЭЛЕМЕНТОВ ====================
// Страницы вида .../filter/thicness-is-3/diameter-is-22/ не существуют как
// элементы/разделы в админке — это результат работы компонента умного
// фильтра, собранный на лету по комбинации параметров. Здесь мы разбираем
// такую ссылку, определяем раздел и параметры, и генерируем под эту
// КОМБИНАЦИЮ отдельный текст (не привязанный ни к одному товару).

function getSmartFilterReportFile($sectionId)
{
    return __DIR__ . "/ai_desc_smartfilter_report_section_{$sectionId}.json";
}

function parseSmartFilterUrl($url)
{
    $path = parse_url(trim($url), PHP_URL_PATH);
    if (!$path) {
        return null;
    }
    $path = trim($path, "/");

    $parts = explode("/filter/", $path, 2);
    $beforeFilter = $parts[0];
    $filterPart = $parts[1] ?? "";

    $segments = explode("/", $beforeFilter);
    $sectionCode = end($segments); // последний сегмент пути перед /filter/ — код раздела

    $filterParams = [];
    if ($filterPart !== "") {
        $filterPart = trim($filterPart, "/");
        foreach (explode("/", $filterPart) as $pair) {
            if (preg_match('/^(.+)-is-(.+)$/', $pair, $m)) {
                $filterParams[$m[1]] = urldecode($m[2]);
            }
        }
    }

    if (!$sectionCode || empty($filterParams)) {
        return null;
    }

    return [
        "section_code" => $sectionCode,
        "filter_params" => $filterParams,
        "base_path" => "/" . $beforeFilter . "/", // путь раздела без фильтра — основа для чистого URL
        "raw_path" => "/" . $path . "/",           // полный исходный путь (сырой, с /filter/...) — это OLD_URL
    ];
}

function resolveSectionIdByCode($code)
{
    $res = CIBlockSection::GetList(
        [],
        ["IBLOCK_ID" => PRODUCT_IBLOCK_ID, "CODE" => $code, "ACTIVE" => "Y"],
        false,
        ["ID", "NAME"]
    );
    if ($row = $res->Fetch()) {
        return ["id" => (int)$row["ID"], "name" => $row["NAME"]];
    }
    return null;
}

// Принимает либо числовой ID товара, либо прямую ссылку на карточку
// (например https://1stall.ru/product/.../truba-.../) и возвращает ID.
// Ссылка разбирается по последнему непустому сегменту пути — это CODE
// элемента (та же логика, что уже используется в редиректе длинных URL
// в местном init.php сайта).
function resolveProductIdFromInput($input)
{
    $input = trim($input);

    if (ctype_digit($input)) {
        return (int)$input;
    }

    $path = parse_url($input, PHP_URL_PATH);
    if (!$path) {
        return null;
    }
    $segments = array_values(array_filter(explode("/", trim($path, "/"))));
    if (empty($segments)) {
        return null;
    }
    $code = end($segments);

    $res = CIBlockElement::GetList(
        [],
        ["IBLOCK_ID" => PRODUCT_IBLOCK_ID, "CODE" => $code, "ACTIVE" => "Y"],
        false,
        false,
        ["ID"]
    );
    if ($row = $res->Fetch()) {
        return (int)$row["ID"];
    }
    return null;
}

function getPropertyNameByCode($code)
{
    $res = CIBlockProperty::GetList([], ["IBLOCK_ID" => PRODUCT_IBLOCK_ID, "CODE" => $code]);
    if ($row = $res->Fetch()) {
        return $row["NAME"];
    }
    return $code; // если не нашли — используем сам код как запасной вариант
}

// Для свойств типа "список" (марка стали, поверхность и т.п.) Битрикс
// использует в URL умного фильтра транслитерированный слаг значения
// (например "09g2s" вместо реального "09Г2С"), а не само значение.
// Эта функция "разворачивает" слаг обратно в настоящее значение,
// перебирая варианты списка и сравнивая их транслитерацию со слагом.
function resolveListPropertyDisplayValue($code, $urlSlug)
{
    $prop = CIBlockProperty::GetList([], ["IBLOCK_ID" => PRODUCT_IBLOCK_ID, "CODE" => $code])->Fetch();
    if (!$prop || $prop["PROPERTY_TYPE"] !== "L") {
        return null; // не список (например, число) — резолвить нечего
    }

    $res = CIBlockPropertyEnum::GetList([], ["IBLOCK_ID" => PRODUCT_IBLOCK_ID, "PROPERTY_ID" => $prop["ID"]]);
    while ($enumRow = $res->Fetch()) {
        $translit = \CUtil::translit($enumRow["VALUE"], "ru", [
            "max_len" => 100,
            "change_case" => "L",
            "replace_space" => "-",
            "replace_other" => "-",
            "delete_repeat_replace" => true,
        ]);
        if ($translit === mb_strtolower($urlSlug)) {
            return $enumRow["VALUE"];
        }
    }
    return null; // совпадение не нашлось — оставим как есть (запасной вариант)
}

// Разворачивает ВСЕ параметры фильтра разом — для показа пользователю
// (Title/H1/Description/текст). Используется только для отображения,
// НЕ для построения URL/ключа дедупликации — там нужен именно слаг.
function resolveFilterParamsDisplayValues($filterParams)
{
    $resolved = [];
    foreach ($filterParams as $code => $urlValue) {
        $realValue = resolveListPropertyDisplayValue($code, $urlValue);
        $resolved[$code] = $realValue !== null ? $realValue : $urlValue;
    }
    return $resolved;
}

// Нормализованный ключ комбинации фильтра — стабильный независимо от
// порядка параметров в исходном URL (thicness=3&diameter=22 и
// diameter=22&thicness=3 дадут один и тот же ключ).
function buildFilterKey($filterParams)
{
    $pairs = [];
    foreach ($filterParams as $code => $value) {
        $pairs[] = "{$code}={$value}";
    }
    sort($pairs);
    return implode("&", $pairs);
}

// Строит "виртуальный товар" из параметров фильтра — тот же формат, что
// getProductContext() возвращает для реального товара, чтобы можно было
// использовать buildPrompt() без изменений.
function getMatchingProductCount($sectionId, $filterParams)
{
    $filter = [
        "IBLOCK_ID" => PRODUCT_IBLOCK_ID,
        "SECTION_ID" => $sectionId,
        "ACTIVE" => "Y",
    ];
    foreach ($filterParams as $code => $value) {
        $filter["PROPERTY_" . $code] = $value;
    }

    $res = CIBlockElement::GetList([], $filter, false, false, ["ID"]);
    $count = 0;
    while ($res->Fetch()) {
        $count++;
    }
    return $count;
}

function buildVirtualProductFromFilter($sectionInfo, $filterParams, $matchingCount = null)
{
    $propList = [];
    $nameParts = [$sectionInfo["name"]];

    foreach ($filterParams as $code => $value) {
        $rawName = getPropertyNameByCode($code);
        [$propName, $unit] = splitPropertyNameAndUnit($rawName);
        $propList[$propName] = $unit ? "{$value} {$unit}" : $value;
        $nameParts[] = "{$value}";
    }

    // Реальное количество товаров под эту комбинацию — честные данные из
    // базы, не выдумка, добавляют тексту практическую ценность.
    // Передаём в промпт, только если реально нашлось хоть что-то — ноль
    // не передаём вообще, чтобы модель не начинала гадать и придумывать
    // объяснения, почему товаров "нет" (что и произошло на практике).
    if ($matchingCount !== null && $matchingCount > 0) {
        $propList["Количество товаров с этими параметрами"] = $matchingCount;
    }

    return [
        "id" => 0, // виртуальный товар — реального ID нет, вариативность по ID здесь не применяется
        "name" => implode(" ", $nameParts),
        "section" => $sectionInfo["name"],
        "properties" => $propList,
        "existing_text" => "",
        "existing_text_html" => "",
    ];
}

// Слаг для чистого URL — ТОЛЬКО значения параметров (не названия, они
// бывают длинными), через дефис, в том же порядке, что в исходном URL.
// Транслитерация — через штатную функцию Битрикса, чтобы слаг получался
// в том же стиле, что и остальные ЧПУ-адреса на сайте.
function buildCleanUrlSlug($filterParams)
{
    $parts = [];
    foreach ($filterParams as $value) {
        $translit = \CUtil::translit($value, "ru", [
            "max_len" => 100,
            "change_case" => "L",
            "replace_space" => "-",
            "replace_other" => "-",
            "delete_repeat_replace" => true,
        ]);
        $parts[] = trim($translit, "-");
    }
    return implode("-", array_filter($parts));
}

function buildCleanUrl($basePath, $filterParams)
{
    $slug = buildCleanUrlSlug($filterParams);
    return rtrim($basePath, "/") . "/" . $slug . "/";
}

// Читаемая строка вида "толщина 3, диаметр 22" — по тому же принципу,
// что уже используется в родном result_modifier.php умного фильтра
// (result_modifier.php компонента stall:catalog.smart.filter), для
// единообразия с уже существующими Title/H1/Description на сайте.
// Разбирает "Толщина стенки, мм" на ["Толщина стенки", "мм"] — единица
// измерения после запятой в названии свойства, как принято в этом каталоге.
// Если запятой нет — единица считается отсутствующей.
function splitPropertyNameAndUnit($rawName)
{
    if (preg_match('/^(.*?),\s*(.+)$/u', $rawName, $m)) {
        return [trim($m[1]), trim($m[2])];
    }
    return [trim($rawName), ""];
}

function buildPropertyMeta($filterParams)
{
    $parts = [];
    foreach ($filterParams as $code => $value) {
        $rawName = getPropertyNameByCode($code);
        [$propName, $unit] = splitPropertyNameAndUnit($rawName);
        $propName = mb_strtolower($propName);
        $parts[] = $unit ? "{$propName} {$value} {$unit}" : "{$propName} {$value}";
    }
    return implode(", ", $parts);
}

function findEwpUrlToSefElementByOldUrl($oldUrl)
{
    $res = CIBlockElement::GetList(
        [],
        ["IBLOCK_ID" => EWP_URLTOSEF_IBLOCK_ID, "PROPERTY_OLD_URL" => $oldUrl],
        false,
        false,
        ["ID"]
    );
    if ($row = $res->Fetch()) {
        return (int)$row["ID"];
    }
    return null;
}

function upsertEwpUrlToSefElement($data)
{
    $fields = [
        "IBLOCK_ID" => EWP_URLTOSEF_IBLOCK_ID,
        "NAME" => $data["clean_url"],
        "ACTIVE" => "Y",
        "PROPERTY_VALUES" => [
            "OLD_URL" => $data["old_url"],
            "REDIRECT" => [EWP_REDIRECT_ENUM_ID],
            "TITLE" => $data["title"],
            "KEYWORDS" => $data["keywords"] ?? "",
            "DESCRIPTION" => $data["description"],
            "H1" => $data["h1"],
            "SEO_TEXT" => $data["seo_text"],
        ],
    ];

    $existingId = findEwpUrlToSefElementByOldUrl($data["old_url"]);
    $el = new CIBlockElement();

    if ($existingId) {
        $ok = $el->Update($existingId, $fields);
        return $ok ? $existingId : null;
    }

    $newId = $el->Add($fields);
    return $newId ?: null;
}

function runGenSmartFilter($urlsFile)
{
    if (empty(ROUTER_CHEAP_API_KEY)) {
        echo "Ошибка: не задан ROUTER_CHEAP_API_KEY.\n";
        exit(1);
    }
    if (!file_exists($urlsFile)) {
        echo "Файл со списком ссылок не найден: {$urlsFile}\n";
        exit(1);
    }

    $urls = file($urlsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $urls = array_filter(array_map("trim", $urls));

    if (empty($urls)) {
        echo "Список ссылок пуст.\n";
        exit(0);
    }

    echo "Всего ссылок: " . count($urls) . "\n\n";

    $smartFilterMapping = loadSmartFilterPromptFiles();

    // Отчёты по разделам — комбинации могут относиться к разным разделам,
    // поэтому храним/сохраняем отдельно на каждый затронутый раздел.
    $reportsBySection = [];
    $saveSectionReport = function ($sectionId) use (&$reportsBySection) {
        $file = getSmartFilterReportFile($sectionId);
        file_put_contents($file, json_encode(array_values($reportsBySection[$sectionId]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    };

    foreach ($urls as $url) {
        echo "Обрабатываю: {$url}\n";

        $parsed = parseSmartFilterUrl($url);
        if (!$parsed) {
            echo "  не удалось разобрать ссылку (нет /filter/ или параметров), пропуск.\n";
            continue;
        }

        try {
            $sectionInfo = resolveSectionIdByCode($parsed["section_code"]);
        } catch (\Throwable $e) {
            echo "  обрыв БД (" . $e->getMessage() . "), переподключаюсь... ";
            reconnectDatabase();
            try {
                $sectionInfo = resolveSectionIdByCode($parsed["section_code"]);
            } catch (\Throwable $e2) {
                echo "не удалось после переподключения, пропуск.\n";
                continue;
            }
        }
        if (!$sectionInfo) {
            echo "  раздел с кодом '{$parsed['section_code']}' не найден, пропуск.\n";
            continue;
        }

        $sectionId = $sectionInfo["id"];
        if (!isset($reportsBySection[$sectionId])) {
            $reportsBySection[$sectionId] = [];
            $existingFile = getSmartFilterReportFile($sectionId);
            if (file_exists($existingFile)) {
                $existing = json_decode(file_get_contents($existingFile), true);
                if (is_array($existing)) {
                    foreach ($existing as $item) {
                        $reportsBySection[$sectionId][$item["filter_key"]] = $item;
                    }
                }
            }
        }

        $filterKey = buildFilterKey($parsed["filter_params"]);

        if (isset($reportsBySection[$sectionId][$filterKey])
            && in_array($reportsBySection[$sectionId][$filterKey]["status"] ?? "", ["approved", "applied"], true)) {
            echo "  уже сгенерировано ранее, пропуск.\n";
            continue;
        }

        // Реальные значения (не URL-слаги) — нужны и для подсчёта товаров
        // по базе, и для показа пользователю. Слаг из URL оставляем только
        // для ключа дедупликации и самого чистого URL (buildFilterKey/buildCleanUrl
        // ниже используют $parsed["filter_params"] в исходном виде намеренно).
        $displayFilterParams = resolveFilterParamsDisplayValues($parsed["filter_params"]);

        try {
            $matchingCount = getMatchingProductCount($sectionId, $displayFilterParams);
        } catch (\Throwable $e) {
            reconnectDatabase();
            try {
                $matchingCount = getMatchingProductCount($sectionId, $displayFilterParams);
            } catch (\Throwable $e2) {
                $matchingCount = null; // не критично — просто не покажем цифру в тексте
            }
        }

        $virtualProduct = buildVirtualProductFromFilter($sectionInfo, $displayFilterParams, $matchingCount);
        $prompt = buildSmartFilterPrompt($virtualProduct, $sectionId, $smartFilterMapping);

        if (strpos($prompt, "ОШИБКА КОНФИГУРАЦИИ:") === 0) {
            echo "  {$prompt}\n";
            $reportsBySection[$sectionId][$filterKey] = [
                "filter_key" => $filterKey,
                "url" => $url,
                "section_id" => $sectionId,
                "filter_params" => $parsed["filter_params"],
                "status" => "error",
                "error" => $prompt,
            ];
            $saveSectionReport($sectionId);
            continue;
        }

        $result = callClaudeAPI($prompt);

        if (isset($result["error"])) {
            echo "  ОШИБКА: {$result['error']}\n";
            $reportsBySection[$sectionId][$filterKey] = [
                "filter_key" => $filterKey,
                "url" => $url,
                "section_id" => $sectionId,
                "filter_params" => $parsed["filter_params"],
                "status" => "error",
                "error" => $result["error"],
            ];
            $saveSectionReport($sectionId);
            continue;
        }

        echo "  готово (" . mb_strlen($result["text"]) . " симв.)\n";

        // Title/H1/Description строим по тому же принципу, что уже
        // используется на сайте в result_modifier.php умного фильтра —
        // с региональными макросами #REGION_...#, которые Битрикс сам
        // подставит при отображении. Долгое ожидание API выше — самое
        // частое место обрыва соединения с MySQL, поэтому здесь тоже
        // ловим и переподключаемся, чтобы не терять уже готовый текст.
        try {
            $propertyMeta = buildPropertyMeta($displayFilterParams);
        } catch (\Throwable $e) {
            echo "  обрыв БД при построении meta-тегов, переподключаюсь... ";
            reconnectDatabase();
            try {
                $propertyMeta = buildPropertyMeta($displayFilterParams);
            } catch (\Throwable $e2) {
                echo "не удалось после переподключения.\n";
                $reportsBySection[$sectionId][$filterKey] = [
                    "filter_key" => $filterKey,
                    "url" => $url,
                    "section_id" => $sectionId,
                    "filter_params" => $parsed["filter_params"],
                    "status" => "error",
                    "error" => "DB error при построении meta-тегов: " . $e2->getMessage(),
                ];
                $saveSectionReport($sectionId);
                continue;
            }
        }

        $cleanUrl = buildCleanUrl($parsed["base_path"], $parsed["filter_params"]);

        // Title/H1/Description теперь пробуем взять ЦЕЛИКОМ из ответа модели
        // (блоки [TITLE]/[H1]/[DESCRIPTION] — см. buildSmartFilterPrompt) —
        // так они подчиняются всем запретам из промпта ("не используй Х") и
        // сами формулируют фразу, начиная с названия страницы, как просили.
        // extractSmartFilterMetaFields() уже проверила, что все три поля на
        // месте и макросы #REGION_NAME_DECLINE_PP#/#REGION_PHONE# скопированы
        // без опечаток — если что-то не так, она вернула null, и здесь
        // откатываемся на старый способ (составляем шаблоном в коде,
        // макросы гарантированно верные). $seoBodyText в любом случае уже
        // очищен от самих маркеров [TITLE]/[H1]/[DESCRIPTION] — НЕ
        // перезаписываем его обратно на "сырой" $result["text"].
        [$aiMetaFields, $seoBodyText] = extractSmartFilterMetaFields($result["text"]);
        $metaPhraseSource = "ai";

        if ($aiMetaFields === null) {
            echo "  [meta] модель не вернула валидные Title/H1/Description с первого раза — "
                . "пробую коротким повторным запросом...\n";
            $retryPrompt = buildSmartFilterMetaRetryPrompt($virtualProduct);
            $retryResult = callClaudeAPI($retryPrompt);
            if (isset($retryResult["error"])) {
                echo "  [meta] повторный запрос не удался: {$retryResult['error']}\n";
            } else {
                [$retryMetaFields, ] = extractSmartFilterMetaFields($retryResult["text"]);
                if ($retryMetaFields !== null) {
                    $aiMetaFields = $retryMetaFields;
                    $metaPhraseSource = "ai_retry";
                    echo "  [meta] повторный запрос успешен.\n";
                } else {
                    echo "  [meta] повторный запрос тоже не дал валидных полей.\n";
                }
            }
        }

        if ($aiMetaFields !== null) {
            $title = $aiMetaFields["title"];
            $h1 = $aiMetaFields["h1"];
            $description = $aiMetaFields["description"];
        } else {
            $metaPhraseSource = "fallback";
            echo "  [meta] модель не вернула валидные Title/H1/Description (нет блоков или "
                . "не скопирован макрос региона/телефона) — использую старый шаблон.\n";
            // Старый способ — макросы и бренд гарантированно на месте, т.к.
            // собраны кодом, а не текстом от модели.
            $fallbackPhrase = "{$sectionInfo['name']} {$propertyMeta}";
            $h1 = "{$fallbackPhrase} в #REGION_NAME_DECLINE_PP#";
            $title = "{$fallbackPhrase} купить в #REGION_NAME_DECLINE_PP# | Первый Стальной Комбинат";
            $description = "{$fallbackPhrase} купить в #REGION_NAME_DECLINE_PP# по цене оптом и в розницу. Доставка по России и СНГ со склада или под заказ. Звоните #REGION_PHONE# или оставьте заявку на сайте!";
        }

        // Приклеиваем фиксированный коммерческий блок кодом (не ИИ) — так
        // гарантированно сохраняются правильные плейсхолдеры #REGION_...#.
        // Именно здесь (SEO-текст умного фильтра), а НЕ в товарных описаниях —
        // у товаров уже есть отдельная вкладка "Доставка".
        $finalSeoText = trim($seoBodyText) . "\n\n" . COMMERCIAL_FOOTER_HTML;

        $reportsBySection[$sectionId][$filterKey] = [
            "filter_key" => $filterKey,
            "url" => $url,
            "old_url" => $parsed["raw_path"],
            "clean_url" => $cleanUrl,
            "section_id" => $sectionId,
            "filter_params" => $parsed["filter_params"],
            "status" => "approved",
            "title" => $title,
            "h1" => $h1,
            "description" => $description,
            "seo_text" => $finalSeoText,
            "meta_phrase_source" => $metaPhraseSource, // "ai" или "fallback" — для отладки
        ];
        $saveSectionReport($sectionId);

        usleep(500000);
    }

    echo "\nГотово. Отчёты сохранены по разделам: ai_desc_smartfilter_report_section_<ID>.json\n";
    echo "Проверь тексты, затем запусти: php ai_generate_descriptions.php applysmartfilter <SECTION_ID>\n";
}

function submitIndexNow($urls)
{
    if (empty($urls)) {
        return ["error" => "Список URL пуст — нечего отправлять."];
    }

    // IndexNow принимает до 10 000 URL за один запрос — режем на всякий
    // случай батчами по 1000, если вдруг понадобится отправить весь каталог.
    $batches = array_chunk($urls, 1000);
    $results = [];

    foreach ($batches as $batch) {
        $payload = json_encode([
            "host" => SITE_HOST,
            "key" => INDEXNOW_KEY,
            "keyLocation" => SITE_PROTOCOL . "://" . SITE_HOST . "/" . INDEXNOW_KEY . ".txt",
            "urlList" => $batch,
        ]);

        $ch = curl_init("https://api.indexnow.org/indexnow");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json; charset=utf-8"],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $results[] = [
            "batch_size" => count($batch),
            "http_code" => $httpCode,
            "error" => $error ?: null,
            "response" => $response,
        ];
    }

    return ["results" => $results];
}

// ==================== РЕЖИМ: REVIEW ====================

function runReview($sectionId, $extraInstructions, $limit = 0)
{
    if (empty(ROUTER_CHEAP_API_KEY)) {
        echo "Ошибка: не задан ROUTER_CHEAP_API_KEY (переменная окружения / define в файле).\n";
        exit(1);
    }
    if (empty($sectionId)) {
        echo "Ошибка: не указан раздел. Запусти как: php ai_generate_descriptions.php review <SECTION_ID> [LIMIT]\n";
        exit(1);
    }

    $elementIds = getElementIdsBySection($sectionId);

    if (empty($elementIds)) {
        echo "В разделе {$sectionId} нет активных товаров.\n";
        exit(0);
    }

    $totalInSection = count($elementIds);

    if ($limit > 0 && $limit < count($elementIds)) {
        $elementIds = array_slice($elementIds, 0, $limit);
    }

    echo "Всего товаров в разделе: {$totalInSection}\n";
    echo "Будет обработано в этом запуске: " . count($elementIds) . "\n";
    $usedPromptFile = basename(getPromptFilePath($sectionId, $GLOBALS['SECTION_PROMPT_FILES']));
    echo "Используется файл промпта: prompts/{$usedPromptFile}\n";
    if (!empty($extraInstructions)) {
        echo "(плюс доп. инструкции для раздела {$sectionId})\n";
    }
    echo "\n";

    $reportFile = getReportFile($sectionId);

    // Подгружаем уже существующий отчёт, если есть (например, от прерванного
    // предыдущего запуска) — чтобы не терять уже успешно сгенерированные
    // тексты и не тратить на них API повторно.
    $report = [];
    $doneIds = [];
    if (file_exists($reportFile)) {
        $existing = json_decode(file_get_contents($reportFile), true);
        if (is_array($existing)) {
            foreach ($existing as $item) {
                if (in_array($item["status"] ?? "", ["approved", "applied"], true)) {
                    $report[$item["id"]] = $item;
                    $doneIds[$item["id"]] = true;
                }
            }
            if (!empty($doneIds)) {
                echo "Найден предыдущий отчёт: " . count($doneIds) . " товаров уже успешно сгенерированы, пропускаю их.\n\n";
            }
        }
    }

    // Сохраняет текущее состояние отчёта на диск немедленно — вызывается
    // после КАЖДОГО товара, чтобы при сбое/аварийном завершении скрипта
    // не терять уже потраченные на API деньги и сгенерированные тексты.
    $saveReport = function () use (&$report, $reportFile) {
        file_put_contents($reportFile, json_encode(array_values($report), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    };

    foreach ($elementIds as $id) {
        if (isset($doneIds[$id])) {
            continue; // уже успешно сгенерирован в прошлый раз
        }

        echo "Обрабатываю товар ID {$id}... ";

        try {
            $product = getProductContext($id);
        } catch (\Throwable $e) {
            // Частая причина: "MySQL server has gone away" — соединение с
            // базой протухло, пока скрипт долго ждал ответа от API (таймауты
            // + повторные попытки). Переподключаемся и повторяем один раз.
            echo "обрыв БД (" . $e->getMessage() . "), переподключаюсь... ";
            reconnectDatabase();
            try {
                $product = getProductContext($id);
            } catch (\Throwable $e2) {
                echo "ОШИБКА БД после переподключения: {$e2->getMessage()}\n";
                $report[$id] = [
                    "id" => $id,
                    "name" => "",
                    "status" => "error",
                    "error" => "DB error: " . $e2->getMessage(),
                ];
                $saveReport();
                continue;
            }
        }

        if (!$product) {
            echo "не найден, пропуск.\n";
            continue;
        }

        $prompt = buildPrompt($product, $sectionId, $extraInstructions, $GLOBALS['SECTION_PROMPT_FILES']);

        if (strpos($prompt, "ОШИБКА КОНФИГУРАЦИИ:") === 0) {
            echo "{$prompt}\n";
            $report[$id] = [
                "id" => $id,
                "name" => $product["name"],
                "status" => "error",
                "error" => $prompt,
            ];
            $saveReport();
            continue;
        }

        $result = callClaudeAPI($prompt);

        if (isset($result["error"])) {
            echo "ОШИБКА: {$result['error']}\n";
            $report[$id] = [
                "id" => $id,
                "name" => $product["name"],
                "status" => "error",
                "error" => $result["error"],
            ];
            $saveReport();
            continue;
        }

        // Для двутавровых балок дописываем в конец текста код-сгенерированный
        // блок с точными данными сортамента (вес, площадь сечения) — эти
        // цифры модель не трогает, они подставляются после её ответа.
        $detailText = $result["text"];
        $dvutavrLookup = getProductDvutavrSpecsInfo($product);
        if ($dvutavrLookup) {
            $detailText .= "\n" . renderDvutavrSpecsBlock($dvutavrLookup["specsInfo"], $dvutavrLookup["lengthMeters"]);
        } elseif (!empty($product["properties"]["Типоразмер"])) {
            // "Типоразмер" есть, но точного совпадения в таблице сортамента
            // нет (скорее всего не хватает цифры варианта — "60Б" вместо
            // "60Б1"/"60Б2", у которых разная масса). Не угадываем — просто
            // предупреждаем, чтобы поправить свойство в Битриксе или таблицу.
            echo "  [сортамент] типоразмер \"{$product['properties']['Типоразмер']}\" не найден точным "
                . "совпадением в таблице сортамента — блок с массой/сечением не добавлен.\n";
        } else {
            // Швеллер горячекатаный — номер профиля берём из названия (нет
            // отдельного свойства "Типоразмер" у этой категории).
            $shvellerLookup = getProductShvellerSpecsInfo($product);
            $shvellerDesignationInName = extractShvellerDesignationFromName($product["name"]);
            if ($shvellerLookup) {
                $detailText .= "\n" . renderShvellerSpecsBlock($shvellerLookup["specsInfo"], $shvellerLookup["lengthMeters"]);
            } elseif ($shvellerDesignationInName !== null) {
                // В названии похоже на обозначение швеллера, но точного
                // совпадения в таблице сортамента нет — не угадываем.
                echo "  [сортамент] обозначение швеллера \"{$shvellerDesignationInName}\" (из названия) не найдено "
                    . "точным совпадением в таблице сортамента — блок с массой/сечением не добавлен.\n";
            } else {
                // Труба (круглая/профильная) — считаем массу по формуле, если
                // распознаны диаметр/размеры, стенка и материал.
                $pipeLookup = getProductPipeSpecsInfo($product);
                if ($pipeLookup) {
                    $detailText .= "\n" . renderPipeSpecsBlock($pipeLookup);
                }
            }
        }

        echo "готово (" . mb_strlen($detailText) . " симв.)\n";

        $report[$id] = [
            "id" => $id,
            "name" => $product["name"],
            "status" => "approved",   // сразу approved; смени на "error" вручную, если текст не устроил
            "detail_text" => $detailText,
        ];
        $saveReport(); // сохраняем сразу же, не дожидаясь конца всего цикла

        // Небольшая пауза, чтобы не упереться в rate limit
        usleep(500000);
    }

    echo "\nГотово. Отчёт сохранён: " . $reportFile . "\n";
    echo "Тексты сразу помечены как \"approved\". Проверь их (preview или сам файл) перед apply.\n";
    echo "Если какой-то текст не устроил — смени его \"status\" на \"error\", чтобы apply его пропустил.\n";
    echo "Затем запусти: php ai_generate_descriptions.php apply {$sectionId}\n";
}

// ==================== РЕЖИМ: APPLY ====================

function runApply($sectionId)
{
    if (empty($sectionId)) {
        echo "Ошибка: не указан раздел. Запусти как: php ai_generate_descriptions.php apply <SECTION_ID>\n";
        exit(1);
    }

    $reportFile = getReportFile($sectionId);

    if (!file_exists($reportFile)) {
        echo "Файл отчёта не найден: " . $reportFile . ". Сначала запусти review для этого раздела.\n";
        exit(1);
    }

    $report = json_decode(file_get_contents($reportFile), true);
    $el = new CIBlockElement();
    $changed = false;

    foreach ($report as $idx => $item) {
        if (($item["status"] ?? "") !== "approved") {
            echo "ID {$item['id']}: пропуск (статус: {$item['status']}, не 'approved')\n";
            continue;
        }

        $fields = [
            "DETAIL_TEXT" => $item["detail_text"],
            "DETAIL_TEXT_TYPE" => "html",
        ];

        if ($el->Update($item["id"], $fields)) {
            echo "ID {$item['id']}: описание обновлено.\n";
            $report[$idx]["status"] = "applied"; // помечаем как реально записанное в Битрикс
            $changed = true;
        } else {
            echo "ID {$item['id']}: ОШИБКА при обновлении — " . $el->LAST_ERROR . "\n";
        }
    }

    if ($changed) {
        file_put_contents($reportFile, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    echo "\nГотово. Не забудь очистить кэш:\n";
    echo "  rm -rf /home/web/1stall.ru/www/bitrix/cache/*\n";
    echo "  rm -rf /home/web/1stall.ru/www/bitrix/managed_cache/*\n";
}

// ==================== ЗАПУСК ====================

$mode = $argv[1] ?? "";
$sectionArg = isset($argv[2]) ? (int)$argv[2] : $DEFAULT_SECTION_ID;
$limitArg = isset($argv[3]) ? (int)$argv[3] : 0;
$extraInstructions = $sectionArg && isset($SECTION_PROMPTS[$sectionArg]) ? $SECTION_PROMPTS[$sectionArg] : "";

switch ($mode) {
    case "review":
        runReview($sectionArg, $extraInstructions, $limitArg);
        break;
    case "apply":
        runApply($sectionArg);
        break;
    case "preview":
        // Диагностика/удобство: сохранить ПРАВИЛЬНО декодированный detail_text
        // одной записи из отчёта в отдельный .html файл для просмотра в браузере.
        // Так не нужно вручную копировать сырую строку из JSON (там текст в
        // экранированном виде — \n, \/ — это нормально для JSON, но выглядит
        // "битым", если скопировать as-is в другой редактор).
        $previewProductId = $limitArg ?: null;
        if (!$previewProductId) {
            echo "Использование: php ai_generate_descriptions.php preview <SECTION_ID> <PRODUCT_ID>\n";
            exit(1);
        }
        $reportFile = getReportFile($sectionArg);
        if (!file_exists($reportFile)) {
            echo "Файл отчёта не найден: {$reportFile}\n";
            exit(1);
        }
        $report = json_decode(file_get_contents($reportFile), true);
        $found = null;
        foreach ($report as $item) {
            if ((string)$item["id"] === (string)$previewProductId) {
                $found = $item;
                break;
            }
        }
        if (!$found) {
            echo "Товар {$previewProductId} не найден в отчёте {$reportFile}\n";
            exit(1);
        }
        $previewFile = __DIR__ . "/preview_{$previewProductId}.html";
        $previewHtml = "<!DOCTYPE html><html><head><meta charset='utf-8'>"
            . "<title>Превью: {$found['name']}</title>"
            . "<style>body{font-family:sans-serif;max-width:800px;margin:40px auto;padding:0 20px;}</style>"
            . "</head><body><h1>{$found['name']}</h1>"
            . ($found["detail_text"] ?? "(пусто)")
            . "</body></html>";
        file_put_contents($previewFile, $previewHtml);
        echo "Сохранено: {$previewFile}\n";
        echo "Скачай этот файл и открой в браузере — там будет ПРАВИЛЬНО отформатированный текст.\n";
        break;

    case "previewmetadescription":
        // Показывает, какая формула и какой итоговый текст META_DESCRIPTION
        // получатся для товаров раздела — без записи в Битрикс.
        $elementIdsForMeta = getElementIdsBySection($sectionArg);
        if ($limitArg > 0 && $limitArg < count($elementIdsForMeta)) {
            $elementIdsForMeta = array_slice($elementIdsForMeta, 0, $limitArg);
        }
        echo "Показываю " . count($elementIdsForMeta) . " товаров:\n\n";
        foreach ($elementIdsForMeta as $id) {
            $product = getProductContext($id);
            if (!$product) {
                continue;
            }
            $meta = buildMetaDescription($product);
            echo "ID {$id} ({$product['name']}):\n{$meta}\n\n";
        }
        break;

    case "applymetadescription":
        // Записывает ротируемое META_DESCRIPTION для всех товаров раздела.
        // Пишет напрямую (без промежуточного JSON-отчёта) — это детерминированная
        // подстановка по шаблону, риска "выдумывания" фактов ИИ-моделью здесь нет.
        if (empty($sectionArg)) {
            echo "Использование: php ai_generate_descriptions.php applymetadescription <SECTION_ID> [LIMIT]\n";
            exit(1);
        }
        $elementIdsForMeta = getElementIdsBySection($sectionArg);
        if ($limitArg > 0 && $limitArg < count($elementIdsForMeta)) {
            $elementIdsForMeta = array_slice($elementIdsForMeta, 0, $limitArg);
        }
        echo "Всего товаров: " . count($elementIdsForMeta) . "\n";
        $elMeta = new CIBlockElement();
        $doneMeta = 0;
        foreach ($elementIdsForMeta as $id) {
            $product = getProductContext($id);
            if (!$product) {
                echo "ID {$id}: не найден, пропуск.\n";
                continue;
            }
            $meta = buildMetaDescription($product);
            $ok = $elMeta->Update($id, [
                "IPROPERTY_TEMPLATES" => [
                    "ELEMENT_META_DESCRIPTION" => $meta,
                ],
            ]);
            if ($ok) {
                $doneMeta++;
            } else {
                echo "ID {$id}: ОШИБКА — " . $elMeta->LAST_ERROR . "\n";
            }
        }
        echo "\nГотово. Обновлено META_DESCRIPTION у {$doneMeta} товаров.\n";
        break;

    case "bindsmartfilterprompt":
        // Привязать раздел к файлу промпта именно для страниц умного
        // фильтра — отдельно от товарного промпта этого же раздела.
        $filenameToBindSF = $argv[3] ?? null;
        if (!$sectionArg || !$filenameToBindSF) {
            echo "Использование: php ai_generate_descriptions.php bindsmartfilterprompt <SECTION_ID> <FILENAME>\n";
            exit(1);
        }
        $promptPathToBindSF = SMARTFILTER_PROMPTS_DIR . "/" . $filenameToBindSF;
        if (!file_exists($promptPathToBindSF)) {
            echo "Файл prompts/smartfilter/{$filenameToBindSF} не найден. Сначала создай его.\n";
            exit(1);
        }
        $sfMapping = loadSmartFilterPromptFiles();
        $sfMapping[(string)$sectionArg] = $filenameToBindSF;
        try {
            saveSmartFilterPromptFiles($sfMapping);
            echo "Привязка (умный фильтр) обновлена: раздел {$sectionArg} -> prompts/smartfilter/{$filenameToBindSF}\n";
        } catch (\Throwable $e) {
            echo "ОШИБКА: {$e->getMessage()}\n";
            exit(1);
        }
        break;

    case "unbindsmartfilterprompt":
        if (!$sectionArg) {
            echo "Использование: php ai_generate_descriptions.php unbindsmartfilterprompt <SECTION_ID>\n";
            exit(1);
        }
        $sfMapping = loadSmartFilterPromptFiles();
        if (isset($sfMapping[(string)$sectionArg])) {
            unset($sfMapping[(string)$sectionArg]);
            try {
                saveSmartFilterPromptFiles($sfMapping);
                echo "Привязка (умный фильтр) для раздела {$sectionArg} удалена — теперь используется prompts/smartfilter/default.txt.\n";
            } catch (\Throwable $e) {
                echo "ОШИБКА: {$e->getMessage()}\n";
                exit(1);
            }
        } else {
            echo "У раздела {$sectionArg} и так не было отдельной привязки (умный фильтр).\n";
        }
        break;

    case "listsmartfiltermapping":
        $sfMapping = loadSmartFilterPromptFiles();
        if (empty($sfMapping)) {
            echo "Привязок (умный фильтр) нет — все разделы используют default.txt.\n";
            break;
        }
        foreach ($sfMapping as $secId => $file) {
            echo "Раздел {$secId} -> prompts/smartfilter/{$file}\n";
        }
        break;

    case "listsmartfilterprompts":
        // Список .txt файлов именно в папке prompts/smartfilter/ — для
        // отдельной вкладки редактора промптов SEO-страниц в GUI.
        if (!is_dir(SMARTFILTER_PROMPTS_DIR)) {
            echo "Папка промптов умного фильтра не найдена: " . SMARTFILTER_PROMPTS_DIR . "\n";
            exit(1);
        }
        $sfFiles = glob(SMARTFILTER_PROMPTS_DIR . "/*.txt");
        foreach ($sfFiles as $f) {
            echo basename($f) . "\n";
        }
        break;

    case "whichsmartfilterprompt":
        $sfMapping = loadSmartFilterPromptFiles();
        $path = getSmartFilterPromptFilePath($sectionArg, $sfMapping);
        echo basename($path) . "\n";
        break;

    case "applysmartfilter":
        // Создаёт/обновляет элементы в инфоблоке "Ewp: CEO Чпу" (ID 51) —
        // это и есть публикация: чистый URL с редиректом со старого адреса,
        // плюс Title/H1/Description/СЕО текст. Модуль EWP URLTOSEF сам
        // подхватывает эти элементы и настраивает редирект + отдаёт
        // указанные SEO-поля на новом адресе.
        if (empty($sectionArg)) {
            echo "Использование: php ai_generate_descriptions.php applysmartfilter <SECTION_ID>\n";
            exit(1);
        }
        $sfReportFile = getSmartFilterReportFile($sectionArg);
        if (!file_exists($sfReportFile)) {
            echo "Файл отчёта не найден: {$sfReportFile}\n";
            exit(1);
        }
        $sfReport = json_decode(file_get_contents($sfReportFile), true);
        $sfApplied = 0;
        $appliedCleanUrls = [];

        foreach ($sfReport as $idx => $item) {
            if (($item["status"] ?? "") !== "approved") {
                continue;
            }
            if (empty($item["clean_url"]) || empty($item["old_url"])) {
                echo "{$item['url']}: пропуск — нет clean_url/old_url (сгенерировано старой версией скрипта, перегенерируй).\n";
                continue;
            }

            $elementId = upsertEwpUrlToSefElement($item);
            if ($elementId) {
                echo "{$item['url']} -> {$item['clean_url']} (элемент ID {$elementId}): применено.\n";
                $sfReport[$idx]["status"] = "applied";
                $sfApplied++;
                $appliedCleanUrls[] = SITE_PROTOCOL . "://" . SITE_HOST . $item["clean_url"];
            } else {
                echo "{$item['url']}: ОШИБКА при создании/обновлении элемента EWP.\n";
            }
        }

        file_put_contents($sfReportFile, json_encode($sfReport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "\nГотово. Применено записей: {$sfApplied}\n";

        if (!empty($appliedCleanUrls)) {
            echo "\nОтправляю новые чистые URL в IndexNow для ускоренной индексации...\n";
            $indexNowResult = submitIndexNow($appliedCleanUrls);
            if (isset($indexNowResult["error"])) {
                echo "ОШИБКА IndexNow: {$indexNowResult['error']}\n";
            } else {
                foreach ($indexNowResult["results"] as $batchResult) {
                    echo "IndexNow: пакет из {$batchResult['batch_size']} URL — HTTP {$batchResult['http_code']}\n";
                }
            }
        }
        break;

    case "debugsmartfiltercount":
        // Диагностика: разбирает ссылку, показывает точный SQL-фильтр,
        // который строится для подсчёта, итоговое число, и для сравнения —
        // реальные значения свойств у одного случайного товара этого
        // раздела (чтобы увидеть, совпадает ли формат).
        $debugUrl = $argv[2] ?? null;
        if (!$debugUrl) {
            echo "Использование: php ai_generate_descriptions.php debugsmartfiltercount <ССЫЛКА>\n";
            exit(1);
        }
        $debugParsed = parseSmartFilterUrl($debugUrl);
        if (!$debugParsed) {
            echo "Не удалось разобрать ссылку.\n";
            exit(1);
        }
        $debugSectionInfo = resolveSectionIdByCode($debugParsed["section_code"]);
        if (!$debugSectionInfo) {
            echo "Раздел с кодом '{$debugParsed['section_code']}' не найден.\n";
            exit(1);
        }
        echo "Раздел: {$debugSectionInfo['name']} (ID {$debugSectionInfo['id']})\n";
        echo "Параметры фильтра из URL (сырые слаги):\n";
        print_r($debugParsed["filter_params"]);

        $debugDisplayParams = resolveFilterParamsDisplayValues($debugParsed["filter_params"]);
        echo "Параметры после разворота слагов (реальные значения):\n";
        print_r($debugDisplayParams);

        $debugFilter = [
            "IBLOCK_ID" => PRODUCT_IBLOCK_ID,
            "SECTION_ID" => $debugSectionInfo["id"],
            "ACTIVE" => "Y",
        ];
        foreach ($debugDisplayParams as $code => $value) {
            $debugFilter["PROPERTY_" . $code] = $value;
        }
        echo "\nФильтр, который используется для подсчёта (с реальными значениями):\n";
        print_r($debugFilter);

        $debugCount = getMatchingProductCount($debugSectionInfo["id"], $debugDisplayParams);
        echo "\nРезультат подсчёта: {$debugCount}\n";

        echo "\n--- Для сравнения: первые 3 товара раздела (реальные значения свойств) ---\n";
        $debugRes = CIBlockElement::GetList(
            [],
            ["IBLOCK_ID" => PRODUCT_IBLOCK_ID, "SECTION_ID" => $debugSectionInfo["id"], "ACTIVE" => "Y"],
            false,
            ["nTopCount" => 3],
            ["ID", "NAME"]
        );
        while ($debugRow = $debugRes->GetNextElement()) {
            $debugFields = $debugRow->GetFields();
            $debugProps = $debugRow->GetProperties();
            echo "\nТовар ID {$debugFields['ID']}: {$debugFields['NAME']}\n";
            foreach ($debugProps as $debugPropCode => $debugProp) {
                if (empty($debugProp["VALUE"])) {
                    continue;
                }
                $debugVal = is_array($debugProp["VALUE"]) ? implode(", ", $debugProp["VALUE"]) : $debugProp["VALUE"];
                echo "  CODE={$debugPropCode} (\"{$debugProp['NAME']}\"): {$debugVal}\n";
            }
        }
        break;

    case "gensmartfilter":
        // Принимает путь к текстовому файлу со списком ссылок (по одной
        // на строку) — генерирует тексты под каждую комбинацию параметров
        // умного фильтра. $sectionArg здесь не ID раздела (он определяется
        // автоматически из каждой ссылки), а путь к файлу со списком.
        $urlsFilePath = $argv[2] ?? null;
        if (!$urlsFilePath) {
            echo "Использование: php ai_generate_descriptions.php gensmartfilter <ПУТЬ_К_ФАЙЛУ_СО_ССЫЛКАМИ>\n";
            exit(1);
        }
        runGenSmartFilter($urlsFilePath);
        break;

    case "indexnow":
        $keyFilePath = ensureIndexNowKeyFile();
        echo "Файл-подтверждение IndexNow: {$keyFilePath}\n";

        $reportFile = getReportFile($sectionArg);
        if (!file_exists($reportFile)) {
            echo "Файл отчёта не найден: {$reportFile}\n";
            exit(1);
        }
        $report = json_decode(file_get_contents($reportFile), true);
        $appliedIds = [];
        foreach ($report as $item) {
            if (($item["status"] ?? "") === "applied") {
                $appliedIds[] = $item["id"];
            }
        }

        if (empty($appliedIds)) {
            echo "В разделе {$sectionArg} нет товаров со статусом 'applied' — нечего отправлять.\n";
            exit(0);
        }

        echo "Собираю URL для " . count($appliedIds) . " товаров...\n";
        $urls = [];
        foreach ($appliedIds as $id) {
            $url = getProductUrl($id);
            if ($url) {
                $urls[] = $url;
            } else {
                echo "  ID {$id}: не удалось получить URL, пропуск.\n";
            }
        }

        echo "Отправляю " . count($urls) . " URL в IndexNow...\n";
        $result = submitIndexNow($urls);

        if (isset($result["error"])) {
            echo "ОШИБКА: {$result['error']}\n";
            exit(1);
        }

        foreach ($result["results"] as $batchResult) {
            echo "Пакет из {$batchResult['batch_size']} URL — HTTP {$batchResult['http_code']}";
            if ($batchResult["error"]) {
                echo " (cURL ошибка: {$batchResult['error']})";
            }
            echo "\n";
        }
        echo "\nГотово. Код 200 или 202 — успешно принято сервисом IndexNow.\n";
        break;

    case "whichprompt":
        // Выводит ИМЯ ФАЙЛА промпта (без пути), который реально
        // используется для указанного раздела — с учётом того, что если
        // файл для раздела не найден на диске, используется default.txt.
        $path = getPromptFilePath($sectionArg, $SECTION_PROMPT_FILES);
        echo basename($path) . "\n";
        break;

    case "bindprompt":
        // Привязать раздел к конкретному файлу промпта.
        // Используем $argv[3] напрямую (не $limitArg), т.к. это имя файла,
        // а не число.
        $filenameToBind = $argv[3] ?? null;
        if (!$sectionArg || !$filenameToBind) {
            echo "Использование: php ai_generate_descriptions.php bindprompt <SECTION_ID> <FILENAME>\n";
            exit(1);
        }
        $promptPathToBind = PROMPTS_DIR . "/" . $filenameToBind;
        if (!file_exists($promptPathToBind)) {
            echo "Файл prompts/{$filenameToBind} не найден. Сначала создай его.\n";
            exit(1);
        }
        $mapping = loadSectionPromptFiles();
        $mapping[(string)$sectionArg] = $filenameToBind;
        try {
            saveSectionPromptFiles($mapping);
            echo "Привязка обновлена: раздел {$sectionArg} -> prompts/{$filenameToBind}\n";
        } catch (\Throwable $e) {
            echo "ОШИБКА: {$e->getMessage()}\n";
            exit(1);
        }
        break;

    case "unbindprompt":
        if (!$sectionArg) {
            echo "Использование: php ai_generate_descriptions.php unbindprompt <SECTION_ID>\n";
            exit(1);
        }
        $mapping = loadSectionPromptFiles();
        if (isset($mapping[(string)$sectionArg])) {
            unset($mapping[(string)$sectionArg]);
            try {
                saveSectionPromptFiles($mapping);
                echo "Привязка для раздела {$sectionArg} удалена — теперь будет использоваться default.txt.\n";
            } catch (\Throwable $e) {
                echo "ОШИБКА: {$e->getMessage()}\n";
                exit(1);
            }
        } else {
            echo "У раздела {$sectionArg} и так не было отдельной привязки.\n";
        }
        break;

    case "listmapping":
        $mapping = loadSectionPromptFiles();
        if (empty($mapping)) {
            echo "Привязок нет — все разделы используют default.txt.\n";
            break;
        }
        foreach ($mapping as $secId => $file) {
            echo "Раздел {$secId} -> prompts/{$file}\n";
        }
        break;

    case "listprompts":
        // Список всех .txt файлов в папке prompts/ — для выпадающего
        // списка в редакторе промптов в GUI.
        if (!is_dir(PROMPTS_DIR)) {
            echo "Папка промптов не найдена: " . PROMPTS_DIR . "\n";
            exit(1);
        }
        $files = glob(PROMPTS_DIR . "/*.txt");
        foreach ($files as $f) {
            echo basename($f) . "\n";
        }
        break;

    case "count":
        $elementIds = getElementIdsBySection($sectionArg);
        $totalInSection = count($elementIds);

        $reportFile = getReportFile($sectionArg);
        $generated = 0;
        $applied = 0;
        if (file_exists($reportFile)) {
            $report = json_decode(file_get_contents($reportFile), true);
            if (is_array($report)) {
                foreach ($report as $item) {
                    $status = $item["status"] ?? "";
                    if (in_array($status, ["approved", "applied"], true)) {
                        $generated++;
                    }
                    if ($status === "applied") {
                        $applied++;
                    }
                }
            }
        }
        echo "\nРаздел {$sectionArg}: сгенерировано и одобрено — {$generated} товаров из {$totalInSection}\n";
        echo "Из них применено (записано в Битрикс) — {$applied} из {$totalInSection}\n";
        break;

    case "listapplied":
        $elementIds = getElementIdsBySection($sectionArg);
        $totalInSection = count($elementIds);

        $reportFile = getReportFile($sectionArg);
        if (!file_exists($reportFile)) {
            echo "Файл отчёта не найден: {$reportFile}\n";
            exit(1);
        }
        $report = json_decode(file_get_contents($reportFile), true);
        $applied = array_filter($report, fn($item) => ($item["status"] ?? "") === "applied");
        echo "\n";
        foreach ($applied as $item) {
            echo "{$item['id']}: {$item['name']}\n";
        }
        echo "\nРаздел {$sectionArg}: применено (записано в Битрикс) — " . count($applied) . " товаров из {$totalInSection}.\n";
        break;

    case "debugproductctx":
        // Проверяет именно getProductContext() (ту же функцию, что и
        // реальная генерация review/apply) на одном ID — без нагрузки на
        // весь каталог, как в catalogaudit. Используется, чтобы убедиться,
        // что добавление IBLOCK_ID в SELECT (см. getProductContext) чинит
        // пропажу свойств.
        $ctxId = (int)($argv[2] ?? 0);
        if (!$ctxId) {
            echo "Использование: php ai_generate_descriptions.php debugproductctx <ID>\n";
            exit(1);
        }
        $ctxProduct = getProductContext($ctxId);
        if (!$ctxProduct) {
            echo "getProductContext({$ctxId}) вернул null.\n";
            break;
        }
        echo "ID: {$ctxProduct['id']}\n";
        echo "NAME: {$ctxProduct['name']}\n";
        echo "SECTION: {$ctxProduct['section']}\n";
        echo "Свойств найдено: " . count($ctxProduct["properties"]) . "\n";
        foreach ($ctxProduct["properties"] as $propName => $propValue) {
            $propValueStr = is_array($propValue) ? implode(", ", $propValue) : $propValue;
            echo "  - {$propName}: {$propValueStr}\n";
        }
        break;

    case "debugproduct":
        // Точечная диагностика ОДНОГО товара: печатает сырой результат
        // CIBlockElement::GetFields() и GetProperties() как есть, без
        // фильтрации в getProductContext() — чтобы понять, почему для
        // некоторых ID свойства не читаются, хотя на живой странице они
        // видны (например, ID 128517 — "Балка двутавровая 12 Б 3ПС/СП").
        $debugId = (int)($argv[2] ?? 0);
        if (!$debugId) {
            echo "Использование: php ai_generate_descriptions.php debugproduct <ID>\n";
            exit(1);
        }
        $debugRes = CIBlockElement::GetList(
            [],
            ["ID" => $debugId],
            false,
            false,
            false // выбираем ВСЕ поля, без ограничения, в отличие от getProductContext
        );
        if (!$debugEl = $debugRes->GetNextElement()) {
            echo "Товар ID {$debugId} не найден (без фильтра по IBLOCK_ID).\n";
            break;
        }
        $debugFields = $debugEl->GetFields();
        echo "=== GetFields() (ключевые поля) ===\n";
        foreach (["ID", "IBLOCK_ID", "NAME", "IBLOCK_SECTION_ID", "ACTIVE"] as $key) {
            echo "{$key}: " . ($debugFields[$key] ?? "(нет)") . "\n";
        }
        echo "\n=== GetProperties() — все свойства как есть ===\n";
        $debugProps = $debugEl->GetProperties();
        if (empty($debugProps)) {
            echo "(пусто — GetProperties() вернул ничего)\n";
        }
        foreach ($debugProps as $propCode => $propData) {
            $val = $propData["VALUE"] ?? null;
            $valStr = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : var_export($val, true);
            echo "  [{$propCode}] NAME=\"" . ($propData["NAME"] ?? "") . "\" VALUE={$valStr}\n";
        }
        echo "\n=== Попытка через СКУ (торговые предложения), если это оффер ===\n";
        if (CModule::IncludeModule("catalog") && class_exists("CCatalogSKU")) {
            $skuInfo = CCatalogSKU::GetInfoByOfferIBlock(PRODUCT_IBLOCK_ID);
            echo "GetInfoByOfferIBlock(" . PRODUCT_IBLOCK_ID . "): " . json_encode($skuInfo, JSON_UNESCAPED_UNICODE) . "\n";
            $skuInfo2 = CCatalogSKU::GetInfoByProductIBlock(PRODUCT_IBLOCK_ID);
            echo "GetInfoByProductIBlock(" . PRODUCT_IBLOCK_ID . "): " . json_encode($skuInfo2, JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "Модуль catalog недоступен или CCatalogSKU не найден.\n";
        }
        break;

    case "catalogaudit":
        // Диагностика: проходит по ВСЕМ активным разделам каталога товаров
        // (IBLOCK_ID = PRODUCT_IBLOCK_ID), для каждого берёт один товар-пример
        // и печатает его свойства (название + значение). Нужна, чтобы понять,
        // как называется поле с типоразмером/диаметром/сечением в каждом
        // разделе — без этого нельзя подключить справочник сортамента
        // (см. dvutavr_sortament.php) к другим типам проката. Ничего не
        // меняет в Битриксе, только читает.
        $auditSections = CIBlockSection::GetList(
            ["LEFT_MARGIN" => "ASC"],
            ["IBLOCK_ID" => PRODUCT_IBLOCK_ID, "ACTIVE" => "Y"],
            false,
            ["ID", "NAME", "DEPTH_LEVEL", "LEFT_MARGIN"]
        );
        while ($auditSection = $auditSections->Fetch()) {
            $indent = str_repeat("  ", max(0, (int)$auditSection["DEPTH_LEVEL"] - 1));
            $auditElements = CIBlockElement::GetList(
                [],
                ["IBLOCK_ID" => PRODUCT_IBLOCK_ID, "SECTION_ID" => $auditSection["ID"], "ACTIVE" => "Y"],
                false,
                ["nTopCount" => 1],
                ["ID"]
            );
            $exampleId = null;
            if ($row = $auditElements->Fetch()) {
                $exampleId = (int)$row["ID"];
            }

            echo "{$indent}[{$auditSection['ID']}] {$auditSection['NAME']}\n";

            if ($exampleId === null) {
                echo "{$indent}    (нет товаров, пример не взят)\n";
                continue;
            }
            try {
                $exampleProduct = getProductContext($exampleId);
            } catch (\Throwable $e) {
                echo "{$indent}    ошибка чтения примера ID {$exampleId}: {$e->getMessage()}\n";
                continue;
            }
            if (!$exampleProduct) {
                echo "{$indent}    (getProductContext вернул null для ID {$exampleId})\n";
                continue;
            }
            echo "{$indent}    пример: ID {$exampleId}, \"{$exampleProduct['name']}\"\n";
            if (empty($exampleProduct["properties"])) {
                echo "{$indent}    свойств нет\n";
            }
            foreach ($exampleProduct["properties"] as $propName => $propValue) {
                $propValueStr = is_array($propValue) ? implode(", ", $propValue) : $propValue;
                echo "{$indent}    - {$propName}: {$propValueStr}\n";
            }
            // Небольшая пауза между разделами — раньше без неё на ~500
            // разделах подряд свойства для части товаров возвращались
            // пустыми (см. debugproduct — у того же ID при одиночном вызове
            // свойства читались нормально).
            usleep(100000);
        }
        break;

    case "listreadyids":
        // НЕ список товаров — список РАЗДЕЛОВ (категорий), для которых на
        // сервере уже есть отчёт с хотя бы одним готовым текстом товара
        // (approved — сгенерирован, ещё не записан в Битрикс, ИЛИ applied —
        // уже записан). Сканируем все файлы ai_desc_report_section_<ID>.json
        // в папке скрипта (а не только текущий "ID раздела" из формы), достаём
        // ID раздела из имени файла и подтягиваем его название из Битрикса.
        // Вывод: "ID - Название раздела", отсортировано по возрастанию ID.
        $reportFiles = glob(__DIR__ . "/ai_desc_report_section_*.json");
        $readySections = []; // sectionId => название раздела
        foreach ($reportFiles as $reportFilePath) {
            if (!preg_match('/ai_desc_report_section_(\d+)\.json$/', $reportFilePath, $m)) {
                continue;
            }
            $reportSectionId = (int)$m[1];
            $reportData = json_decode(file_get_contents($reportFilePath), true);
            if (!is_array($reportData)) {
                continue;
            }
            $hasReady = false;
            foreach ($reportData as $reportItem) {
                if (in_array($reportItem["status"] ?? "", ["approved", "applied"], true)) {
                    $hasReady = true;
                    break;
                }
            }
            if (!$hasReady) {
                continue;
            }
            $sectionRow = CIBlockSection::GetByID($reportSectionId)->Fetch();
            $readySections[$reportSectionId] = $sectionRow["NAME"] ?? "";
        }
        ksort($readySections, SORT_NUMERIC);
        echo "Разделов с готовыми текстами товаров — " . count($readySections) . "\n";
        foreach ($readySections as $readySectionId => $readySectionName) {
            echo $readySectionName !== ""
                ? "{$readySectionId} - {$readySectionName}\n"
                : "{$readySectionId}\n";
        }
        break;

    case "listreadysmartfilterids":
        // То же самое, но для SEO-страниц умного фильтра — у них нет
        // числового ID (это виртуальные страницы, не элементы Битрикса),
        // поэтому вместо ID показываем итоговый (чистый) адрес страницы,
        // отсортированный по алфавиту.
        if (empty($sectionArg)) {
            echo "Использование: php ai_generate_descriptions.php listreadysmartfilterids <SECTION_ID>\n";
            exit(1);
        }
        $readySfReportFile = getSmartFilterReportFile($sectionArg);
        if (!file_exists($readySfReportFile)) {
            echo "Раздел {$sectionArg}: готовых SEO-страниц — 0 (файл отчёта не найден)\n";
            exit(0);
        }
        $readySfReport = json_decode(file_get_contents($readySfReportFile), true);
        $readySfItems = []; // url => название (из H1, без макроса региона)
        if (is_array($readySfReport)) {
            foreach ($readySfReport as $item) {
                if (in_array($item["status"] ?? "", ["approved", "applied"], true)) {
                    $sfUrl = $item["clean_url"] ?? ($item["url"] ?? $item["filter_key"]);
                    $sfName = trim(str_replace(
                        [" в #REGION_NAME_DECLINE_PP#", "#REGION_NAME_DECLINE_PP#"],
                        "",
                        $item["h1"] ?? ""
                    ));
                    $readySfItems[$sfUrl] = $sfName;
                }
            }
        }
        ksort($readySfItems, SORT_STRING);
        echo "Раздел {$sectionArg}: готовых SEO-страниц — " . count($readySfItems) . "\n";
        foreach ($readySfItems as $sfUrl => $sfName) {
            echo $sfName !== "" ? "{$sfUrl} - {$sfName}\n" : "{$sfUrl}\n";
        }
        break;

    case "rawapicall":
        // Отправляет содержимое файла в API как есть, без подстановки
        // каких-либо плейсхолдеров и без привязки к товару/базе. Используется
        // для мета-задач вроде "проанализируй промпт и предложи правки" —
        // GUI сам собирает нужный текст и просто просит его обработать.
        $rawFile = $argv[2] ?? null;
        if (!$rawFile) {
            echo "Использование: php ai_generate_descriptions.php rawapicall <ПУТЬ_К_ФАЙЛУ>\n";
            exit(1);
        }
        if (!file_exists($rawFile)) {
            echo "Файл не найден: {$rawFile}\n";
            exit(1);
        }
        if (empty(ROUTER_CHEAP_API_KEY)) {
            echo "Ошибка: не задан ROUTER_CHEAP_API_KEY.\n";
            exit(1);
        }
        $rawContent = file_get_contents($rawFile);
        $rawResult = callClaudeAPI($rawContent);
        if (isset($rawResult["error"])) {
            echo "ОШИБКА: {$rawResult['error']}\n";
            exit(1);
        }
        echo $rawResult["text"] . "\n";
        break;

    case "testpromptproduct":
        // Тест произвольного текста промпта на РЕАЛЬНОМ товаре — ничего
        // никуда не сохраняет (ни в отчёт, ни в Битрикс), только выводит
        // результат. Используется приложением для "песочницы" промптов.
        // Принимает либо числовой ID, либо прямую ссылку на карточку товара.
        $testProductInput = $argv[2] ?? null;
        $testPromptFile = $argv[3] ?? null;
        if (!$testProductInput || !$testPromptFile) {
            echo "Использование: php ai_generate_descriptions.php testpromptproduct <PRODUCT_ID_ИЛИ_ССЫЛКА> <ПУТЬ_К_ФАЙЛУ_ПРОМПТА>\n";
            exit(1);
        }
        if (!file_exists($testPromptFile)) {
            echo "Файл промпта не найден: {$testPromptFile}\n";
            exit(1);
        }
        if (empty(ROUTER_CHEAP_API_KEY)) {
            echo "Ошибка: не задан ROUTER_CHEAP_API_KEY.\n";
            exit(1);
        }

        $testProductId = resolveProductIdFromInput($testProductInput);
        if (!$testProductId) {
            echo "Не удалось определить товар по значению: {$testProductInput}\n";
            exit(1);
        }

        $testProduct = getProductContext($testProductId);
        if (!$testProduct) {
            echo "Товар {$testProductId} не найден.\n";
            exit(1);
        }

        $testPropsText = "";
        foreach ($testProduct["properties"] as $name => $value) {
            $testPropsText .= "- {$name}: {$value}\n";
        }
        $testTemplate = file_get_contents($testPromptFile);
        $testRewriteBlock = renderRewriteBlock($testProduct);
        $testReplacements = [
            "{{ID}}" => $testProduct["id"],
            "{{NAME}}" => $testProduct["name"],
            "{{SECTION}}" => $testProduct["section"],
            "{{PROPS}}" => $testPropsText,
            "{{EXTRA_BLOCK}}" => "",
            "{{REWRITE_BLOCK}}" => $testRewriteBlock,
        ];
        $testPrompt = str_replace(array_keys($testReplacements), array_values($testReplacements), $testTemplate);

        echo "=== ТОВАР ===\n{$testProduct['name']} (ID {$testProduct['id']}, раздел: {$testProduct['section']})\n\n";
        echo "=== ЗАПРОС К API... ===\n";

        $testResult = callClaudeAPI($testPrompt);
        if (isset($testResult["error"])) {
            echo "ОШИБКА: {$testResult['error']}\n";
            exit(1);
        }

        echo "=== РЕЗУЛЬТАТ (" . mb_strlen($testResult["text"]) . " симв.) ===\n";
        echo $testResult["text"] . "\n";
        break;

    case "testpromptsmartfilter":
        // То же самое, но для страницы умного фильтра — по ссылке.
        $testUrl = $argv[2] ?? null;
        $testPromptFileSF = $argv[3] ?? null;
        if (!$testUrl || !$testPromptFileSF) {
            echo "Использование: php ai_generate_descriptions.php testpromptsmartfilter <ССЫЛКА> <ПУТЬ_К_ФАЙЛУ_ПРОМПТА>\n";
            exit(1);
        }
        if (!file_exists($testPromptFileSF)) {
            echo "Файл промпта не найден: {$testPromptFileSF}\n";
            exit(1);
        }
        if (empty(ROUTER_CHEAP_API_KEY)) {
            echo "Ошибка: не задан ROUTER_CHEAP_API_KEY.\n";
            exit(1);
        }

        $testParsed = parseSmartFilterUrl($testUrl);
        if (!$testParsed) {
            echo "Не удалось разобрать ссылку.\n";
            exit(1);
        }
        $testSectionInfo = resolveSectionIdByCode($testParsed["section_code"]);
        if (!$testSectionInfo) {
            echo "Раздел с кодом '{$testParsed['section_code']}' не найден.\n";
            exit(1);
        }

        $testDisplayParams = resolveFilterParamsDisplayValues($testParsed["filter_params"]);
        $testMatchingCount = getMatchingProductCount($testSectionInfo["id"], $testDisplayParams);
        $testVirtualProduct = buildVirtualProductFromFilter($testSectionInfo, $testDisplayParams, $testMatchingCount);

        $testPropsTextSF = "";
        foreach ($testVirtualProduct["properties"] as $name => $value) {
            $testPropsTextSF .= "- {$name}: {$value}\n";
        }
        $testTemplateSF = file_get_contents($testPromptFileSF);
        $testReplacementsSF = [
            "{{ID}}" => $testVirtualProduct["id"],
            "{{NAME}}" => $testVirtualProduct["name"],
            "{{SECTION}}" => $testVirtualProduct["section"],
            "{{PROPS}}" => $testPropsTextSF,
            "{{EXTRA_BLOCK}}" => "",
            "{{REWRITE_BLOCK}}" => "",
        ];
        $testPromptSF = str_replace(array_keys($testReplacementsSF), array_values($testReplacementsSF), $testTemplateSF);

        echo "=== РАЗДЕЛ ===\n{$testSectionInfo['name']} (ID {$testSectionInfo['id']})\n";
        echo "=== ПАРАМЕТРЫ ===\n";
        print_r($testDisplayParams);
        echo "Совпадающих товаров: {$testMatchingCount}\n\n";
        echo "=== ЗАПРОС К API... ===\n";

        $testResultSF = callClaudeAPI($testPromptSF);
        if (isset($testResultSF["error"])) {
            echo "ОШИБКА: {$testResultSF['error']}\n";
            exit(1);
        }

        echo "=== РЕЗУЛЬТАТ (" . mb_strlen($testResultSF["text"]) . " симв.) ===\n";
        echo $testResultSF["text"] . "\n";
        break;

    case "dumpprompt":
        // Диагностика: сохранить реальный промпт для одного товара в файл,
        // чтобы отправить его вручную через curl и проверить, зависает ли
        // API именно на этом объёме запроса вне PHP вообще.
        $productId = $limitArg ?: null;
        if (!$productId) {
            echo "Использование: php ai_generate_descriptions.php dumpprompt <SECTION_ID> <PRODUCT_ID>\n";
            exit(1);
        }
        $product = getProductContext($productId);
        if (!$product) {
            echo "Товар {$productId} не найден.\n";
            exit(1);
        }
        $prompt = buildPrompt($product, $sectionArg, $extraInstructions, $GLOBALS['SECTION_PROMPT_FILES']);
        $payload = json_encode([
            "model" => ROUTER_CHEAP_MODEL,
            "max_tokens" => 2000,
            "messages" => [["role" => "user", "content" => $prompt]],
        ], JSON_UNESCAPED_UNICODE);
        $dumpFile = __DIR__ . "/prompt_payload.json";
        file_put_contents($dumpFile, $payload);
        echo "Сохранено: {$dumpFile}\n";
        echo "Размер промпта: " . mb_strlen($prompt) . " символов\n";
        echo "Размер всего payload: " . strlen($payload) . " байт\n";
        echo "\nТеперь отправь вручную (подставь свой ключ вместо ТВОЙ_КЛЮЧ):\n";
        echo "curl --max-time 180 -v https://router.cheap/v1/messages \\\n";
        echo "  -H \"x-api-key: ТВОЙ_КЛЮЧ\" \\\n";
        echo "  -H \"anthropic-version: 2023-06-01\" \\\n";
        echo "  -H \"Content-Type: application/json\" \\\n";
        echo "  -d @{$dumpFile}\n";
        break;
    default:
        echo "Использование:\n";
        echo "  php ai_generate_descriptions.php review <SECTION_ID> [LIMIT]   — сгенерировать черновики по разделу\n";
        echo "                                                                    (LIMIT — необязательно, обработать только первые N товаров)\n";
        echo "  php ai_generate_descriptions.php apply <SECTION_ID>            — записать одобренные в Битрикс\n";
        echo "  php ai_generate_descriptions.php previewmetadescription <ID> [LIMIT] — предпросмотр ротации META_DESCRIPTION\n";
        echo "  php ai_generate_descriptions.php applymetadescription <ID> [LIMIT]   — записать ротируемое META_DESCRIPTION\n";
        echo "  php ai_generate_descriptions.php gensmartfilter <ФАЙЛ_СО_ССЫЛКАМИ>  — сгенерировать тексты по списку ссылок умного фильтра\n";
        echo "  php ai_generate_descriptions.php applysmartfilter <SECTION_ID>  — подтвердить (финализировать) тексты умного фильтра раздела\n";
        echo "  php ai_generate_descriptions.php listsmartfilterprompts        — список файлов промптов умного фильтра\n";
        echo "  php ai_generate_descriptions.php bindsmartfilterprompt <ID> <FILE> — привязать промпт умного фильтра к разделу\n";
        echo "  php ai_generate_descriptions.php unbindsmartfilterprompt <ID>  — убрать привязку промпта умного фильтра\n";
        echo "  php ai_generate_descriptions.php listsmartfiltermapping         — показать привязки промптов умного фильтра\n";
        echo "  php ai_generate_descriptions.php whichsmartfilterprompt <ID>   — какой промпт умного фильтра используется для раздела\n";
        echo "  php ai_generate_descriptions.php indexnow <SECTION_ID>         — отправить применённые URL в Яндекс/Bing через IndexNow\n";
        echo "  php ai_generate_descriptions.php count <SECTION_ID>            — сколько сгенерировано/применено из всего раздела\n";
        echo "  php ai_generate_descriptions.php whichprompt <SECTION_ID>      — какой файл промпта используется для раздела\n";
        echo "  php ai_generate_descriptions.php listprompts                    — список всех файлов промптов\n";
        echo "  php ai_generate_descriptions.php bindprompt <SECTION_ID> <FILE> — привязать раздел к файлу промпта\n";
        echo "  php ai_generate_descriptions.php unbindprompt <SECTION_ID>     — убрать привязку (вернуть default.txt)\n";
        echo "  php ai_generate_descriptions.php listmapping                    — показать все текущие привязки\n";
        echo "  php ai_generate_descriptions.php preview <SECTION_ID> <PRODUCT_ID>    — сохранить корректно декодированный превью-HTML для просмотра\n";
                echo "  php ai_generate_descriptions.php rawapicall <FILE>       — отправить произвольный текст в API без подстановок\n";
echo "  php ai_generate_descriptions.php testpromptproduct <PRODUCT_ID> <FILE>    — тест произвольного промпта на товаре, без сохранения\n";
        echo "  php ai_generate_descriptions.php testpromptsmartfilter <URL> <FILE>       — тест произвольного промпта на странице фильтра, без сохранения\n";
        echo "  php ai_generate_descriptions.php dumpprompt <SECTION_ID> <PRODUCT_ID> — сохранить промпт в файл для ручной диагностики\n";
}