<?php

/**
 * Проверки ядра Редколлегии без Joomla: php tests/run.php
 * Совпадение переноса Мильчина и kk_typo с эталонами — отдельно, tests/parity/compare.py.
 */

require __DIR__ . '/bootstrap.php';

use Webmarka\Component\Redkollegiya\Administrator\Engine\Engine;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Html;

const NB = "\u{00A0}";

$pass = 0;
$fail = 0;

function check(string $name, string $got, string $want): void
{
    global $pass, $fail;

    if ($got === $want) {
        $pass++;

        return;
    }

    $fail++;
    $show = static fn (string $s): string => str_replace(NB, '⍽', $s);
    echo "НЕ ПРОШЛО  {$name}\n    ждал:     {$show($want)}\n    получил:  {$show($got)}\n";
}

function ru(string $s, array $o = [], bool $html = false): string
{
    return (new Engine($o))->process($s, 'ru-RU', $html)->text;
}

function kk(string $s, array $o = [], bool $html = false): string
{
    return (new Engine($o))->process($s, 'kk-KZ', $html)->text;
}

// ------------------------------------------------------------------ правила издания: минус

check('минус перед числом в начале', ru('-5% за год'), '−5% за год');
check('минус после пробела', ru('инфляция -0,25 п. п.'), 'инфляция −0,25' . NB . 'п.' . NB . 'п.');
check('минус из короткого тире', ru('до –3,2'), 'до −3,2');
check('минус в скобках', ru('(-12%)'), '(−12%)');
check('вычитание «5 -3» — не минус', ru('счёт 5 -3'), 'счёт 5 -3');
check('телефон не трогать', ru('звоните -701-123-45'), 'звоните -701-123-45');
check('дефис в слове не трогать', ru('COVID-19 и Ту-154'), 'COVID-19 и' . NB . 'Ту-154');
check('минус выключен', ru('-5%', ['minus' => false]), '-5%');

// ------------------------------------------------------------------ десятичная запятая

check('запятая перед «млрд»', ru('рост на 1.5 млрд'), 'рост на 1,5' . NB . 'млрд');
check('запятая перед процентом', ru('ставка 16.5%'), 'ставка 16,5%');
check('запятая перед «раза»', ru('в 2.5 раза'), 'в' . NB . '2,5 раза');
check('версия не трогается', ru('Joomla 6.2 вышла'), 'Joomla 6.2 вышла');
check('пункт ТЗ не трогается', ru('п. 3.2 ТЗ'), 'п. 3.2 ТЗ');
check('дата не трогается', ru('06.11.2026 тыс. зрителей'), '06.11.2026' . NB . 'тыс. зрителей');
check('версия из трёх чисел', ru('1.5.2 млн'), '1.5.2' . NB . 'млн');
check('«т. е.» после числа — не тонны', ru('версия 2.0 т. е. новая'), 'версия 2.0' . NB . 'т.' . NB . 'е. новая');
check('запятая выключена', ru('1.5 млрд', ['decimal_comma' => false]), '1.5' . NB . 'млрд');

// ------------------------------------------------------------------ «млн.», единицы, тире между словами

check('«млн.» внутри предложения', ru('5 млн. тенге'), '5' . NB . 'млн' . NB . 'тенге');
check('«млрд.» в конце предложения', ru('Ущерб 8 млрд. Работы идут'), 'Ущерб 8' . NB . 'млрд. Работы идут');
check('«тыс.» с точкой остаётся', ru('5 тыс. человек'), '5' . NB . 'тыс. человек');
check('знак тенге после числа', ru('стоит 500 ₸'), 'стоит 500' . NB . '₸');
check('знак тенге перед числом', ru('₸8,5 млрд'), '₸8,5' . NB . 'млрд');
check('«п.п.» — процентные пункты', ru('на 0,5 п.п. выше'), 'на 0,5' . NB . 'п.' . NB . 'п. выше');
check('короткое тире между словами', ru('Нацбанк – регулятор'), 'Нацбанк' . NB . '— регулятор');
check('короткое тире между числами с пробелами', ru('счёт 2 – 1'), 'счёт 2 – 1');
check('длинное тире в диапазоне по настройке', ru('2020-2025', ['range_dash' => 'em']), '2020—2025');
check('короткие предлоги по настройке', ru('для бизнеса и по закону', ['prepositions' => 'short']), 'для' . NB . 'бизнеса и' . NB . 'по' . NB . 'закону');
check('частица «же»', ru('он же сказал', ['prepositions' => 'short']), 'он' . NB . 'же сказал');
check('процент через пробел по настройке', ru('рост 5%', ['percent' => 'nbsp']), 'рост 5' . NB . '%');
check('инициалы как есть по настройке', ru('А.С. Пушкин', ['initials' => 'keep']), 'А.С. Пушкин');

// ------------------------------------------------------------------ знак валюты

check('₸ перед числом — слитно', ru('выделено ₸ 8,5 млрд'), 'выделено ₸8,5' . NB . 'млрд');
check('$ перед числом — слитно', ru('стоит $ 5'), 'стоит $5');
check('₸ после числа как написал автор', ru('стоит 500 ₸'), 'стоит 500' . NB . '₸');
check('знак первого числа не липнет ко второму', ru('500 ₸ 20 раз'), '500' . NB . '₸ 20 раз');
check('₸ вперёд по настройке', ru('стоит 500 ₸', ['currency_before' => true]), 'стоит ₸500');
check('$ вперёд с «млрд»', ru('сделка на 5.2 млрд $', ['currency_before' => true]), 'сделка на $5,2' . NB . 'млрд');
check('₸ вперёд с разрядами', ru('цена 1 500 ₸', ['currency_before' => true]), 'цена ₸1 500');
check('₸ вперёд — уже впереди', ru('₸8,5 млрд', ['currency_before' => true]), '₸8,5' . NB . 'млрд');
check('kk ₸ вперёд', kk('бағасы 500 ₸', ['currency_before' => true]), 'бағасы ₸500');
check('счётное слово и валюта', ru('2 млрд долларов'), '2' . NB . 'млрд' . NB . 'долларов');

// ------------------------------------------------------------------ буква ё

check('ё по словарю', ru('Еще раз ее учет'), 'Ещё раз её учёт');
check('«объем» не трогается (глагол «объесть»)', ru('объем'), 'объем');
check('«все» не трогается', ru('все пришли'), 'все пришли');
check('ё в имени не ставится', ru('Артем сказал'), 'Артем сказал');
check('ё в имени по настройке', ru('Артем сказал', ['yo_names' => true]), 'Артём сказал');
check('сокращение «мед.» не трогается', ru('мед. училище'), 'мед. училище');
check('ё снять', ru('Ещё её', ['yo' => 'remove']), 'Еще ее');
check('ё не трогать', ru('еще ее', ['yo' => 'off']), 'еще ее');
check('ё в казахском не ставится', kk('еще ее Қазақстан әрі'), 'еще ее Қазақстан әрі');

// ------------------------------------------------------------------ казахский: kk_typo и правила издания

check('kk кавычки и тире', kk('Ол "Қазақ" - деді'), 'Ол «Қазақ»' . NB . '— деді');
check('kk минус и запятая', kk('инфляция -0.5% болды'), 'инфляция −0,5% болды');
check('kk «млн.»', kk('5 млн. теңге'), '5' . NB . 'млн' . NB . 'теңге');
check('kk знак тенге после числа', kk('500 ₸ тұрады'), '500' . NB . '₸ тұрады');

// ------------------------------------------------------------------ язык

check('язык по буквам: русский', (new Engine())->process('Он сказал "да"', '*', false)->lang, 'ru');
check('язык по буквам: казахский', (new Engine())->process('Қазақстан әрі өндіріс', '*', false)->lang, 'kk');
check('казахское имя в русском тексте', (new Engine())->process('Президент Қасым-Жомарт Токаев сказал, что экономика растёт быстрее', '*', false)->lang, 'ru');
check('английский не трогается', (new Engine())->process('He said "yes" - ok...', 'en-GB', false)->text, 'He said "yes" - ok...');

// ------------------------------------------------------------------ защита

check('вызов плагина не трогается', ru('см. {loadmoduleid 5} и "текст"'), 'см. {loadmoduleid 5} и' . NB . '«текст»');
check('вызов с кавычками внутри', ru('{gallery dir="a-b"} - тут'), '{gallery dir="a-b"}' . NB . '— тут');
check('защищённое слово', ru('Банк Ежик и ежик', ['protect' => ['Ежик']]), 'Банк Ежик и' . NB . 'ёжик');
check('адрес не трогается', ru('см. https://a.kz/x-1.5-млн тут'), 'см. https://a.kz/x-1.5-млн тут');
check('почта не трогается', ru('пишите на a-1.5@b.kz'), 'пишите на a-1.5@b.kz');

// ------------------------------------------------------------------ разметка

$h = static fn (string $s, array $o = []): string => ru($s, $o, true);

check('теги и атрибуты целы', $h('<p class="x" title="a - b">"Да" - нет</p>'), '<p class="x" title="a - b">«Да»&nbsp;— нет</p>');
check('кавычки через <em>', $h('<p>"<em>Самрук</em>" - фонд</p>'), '<p>«<em>Самрук</em>»&nbsp;— фонд</p>');
check('предлог перед ссылкой', $h('<p>в <a href="/x">Астане</a></p>'), '<p>в&nbsp;<a href="/x">Астане</a></p>');
check('пробел после запятой перед <b>', $h('<p>Короче,<b>рассказываю</b></p>'), '<p>Короче, <b>рассказываю</b></p>');
check('абзацы не сливаются', $h('<p>"Да</p><p>нет"</p>'), '<p>"Да</p><p>нет"</p>');
check('код не трогается', $h('<p>Команда <code>a -- b "x"</code> работает</p>'), '<p>Команда <code>a -- b "x"</code> работает</p>');
check('pre не трогается', $h('<pre>"x" - y...</pre><p>"x" - y...</p>'), '<pre>"x" - y...</pre><p>«x»&nbsp;— y…</p>');
check('класс notypo', $h('<p><span class="notypo">"x" - y</span> и "z"</p>'), '<p><span class="notypo">"x" - y</span> и&nbsp;«z»</p>');
check('сущности', $h('<p>A &amp; B - &laquo;C&raquo; &lt;tag&gt;</p>'), '<p>A &amp; B&nbsp;— «C» &lt;tag&gt;</p>');
check('неразрывный пробел записью &nbsp;', $h('<p>5 кг</p>'), '<p>5&nbsp;кг</p>');
check('без правок — разметка байт в байт', $h('<p>Готово&nbsp;&mdash; всё.</p>'), '<p>Готово&nbsp;&mdash; всё.</p>');
check('комментарий и скрипт', $h('<!-- "a" - b --><script>x = "a" - 1;</script><p>a - b</p>'), '<!-- "a" - b --><script>x = "a" - 1;</script><p>a&nbsp;— b</p>');
check('вложенный код с тем же тегом', $h('<pre><pre>"x"</pre>"y"</pre><p>"z"</p>'), '<pre><pre>"x"</pre>"y"</pre><p>«z»</p>');
check('<br> делит фразы', $h('<p>"Да<br>нет"</p>'), '<p>"Да<br>нет"</p>');
check('встраивание Webmarka Clean', $h('<div class="wm-embed" data-wm-embed="youtube" data-url="https://y.t/a-b"><a href="https://y.t/a-b">https://y.t/a-b</a></div>'),
    '<div class="wm-embed" data-wm-embed="youtube" data-url="https://y.t/a-b"><a href="https://y.t/a-b">https://y.t/a-b</a></div>');
check('подпись снимка', $h('<figure><img src="a.jpg" alt="a - b"><figcaption>Фото: "КМГ" - архив</figcaption></figure>'),
    '<figure><img src="a.jpg" alt="a - b"><figcaption>Фото: «КМГ»&nbsp;— архив</figcaption></figure>');

// ------------------------------------------------------------------ вставки: Wmarka Media, Webmarka Clean, соцсети

$vid = '<figure class="wm-figure wm-figure--video" data-wm-video="https://www.youtube.com/watch?v=abc-123" data-wm-title="Нацбанк - о ставке">'
    . '<a href="https://www.youtube.com/watch?v=abc-123"><img src="https://i.ytimg.com/vi/abc-123/hqdefault.jpg" alt="Нацбанк - о ставке" loading="lazy"></a>'
    . '<figcaption>Глава Нацбанка - о "ставке". <span class="wm-figure__credit">Видео: YouTube</span></figcaption></figure>';
check('видео Wmarka Media: атрибуты целы, подпись поправлена', $h($vid), str_replace('<figcaption>Глава Нацбанка - о "ставке".', '<figcaption>Глава Нацбанка&nbsp;— о&nbsp;«ставке».', $vid));
check('видео Wmarka Media: ссылка-заглушка', $h('<figure class="wm-figure wm-figure--video" data-wm-video="https://t.me/c/1"><a href="https://t.me/c/1">Пост - Смотреть</a></figure>'),
    '<figure class="wm-figure wm-figure--video" data-wm-video="https://t.me/c/1"><a href="https://t.me/c/1">Пост&nbsp;— Смотреть</a></figure>');

foreach ([
    'документ Wmarka Media' => '<p><a class="wm-doc wm-doc--pdf" href="files/otchet-2025.pdf">Отчёт за 2025 год</a> (PDF, 2,4 МБ)</p>',
    'фрейм' => '<iframe src="https://vk.com/video_ext.php?oid=-1&amp;id=2" allow="autoplay; encrypted-media" allowfullscreen></iframe>',
    'X/Twitter' => '<blockquote class="twitter-tweet"><p lang="en">Rates "unchanged" - for now...</p>&mdash; Bank (@bank) <a href="https://twitter.com/bank/status/1">Nov 6</a></blockquote><script async src="https://platform.twitter.com/widgets.js"></script>',
    'Instagram' => '<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/p/X/"><div><p>Публикация от "Банк" - 6 ноября</p></div></blockquote><script async src="//www.instagram.com/embed.js"></script>',
    'Telegram' => '<script async src="https://telegram.org/js/telegram-widget.js?22" data-telegram-post="channel/123"></script>',
    'TikTok' => '<blockquote class="tiktok-embed" cite="https://www.tiktok.com/@a/video/1" data-video-id="1"><section>текст - "ролика"</section></blockquote>',
    'Threads' => '<blockquote class="text-post-media" data-text-post-permalink="https://www.threads.net/@a/post/1"><a href="https://www.threads.net/@a/post/1">"Пост" - Threads</a></blockquote>',
    'метка Webmarka Clean' => '<div class="wm-embed" data-wm-embed="youtube" data-url="https://youtu.be/a-b"><a href="https://youtu.be/a-b">Видео - "тут"</a></div>',
    'видео-тег' => '<video controls src="a.mp4">Ваш браузер не показывает "видео" - скачайте файл</video>',
] as $name => $emb) {
    check('вставка не трогается: ' . $name, $h($emb), $emb);
}

check('«>» в значении атрибута', $h('<p data-note="a > b - c">Текст - "с" атрибутом</p>'), '<p data-note="a > b - c">Текст&nbsp;— «с» атрибутом</p>');
check('«<» в тексте', $h('<p>a < b - c</p>'), '<p>a &lt; b&nbsp;— c</p>');
check('вызов плагина со скобками внутри', ru('{gallery {"dir":"a - b"}} и "текст"'), '{gallery {"dir":"a - b"}} и' . NB . '«текст»');

// ------------------------------------------------------------------ тире в начале абзаца (реплика, пункт)

check('реплика', ru('- Привет, - сказал он.'), '—' . NB . 'Привет,' . NB . '— сказал он.');
check('реплика с «а»', ru('- а он?'), '—' . NB . 'а' . NB . 'он?');
check('реплики в абзацах', $h('<p>- Да.</p><p>-- Нет.</p><p>– Может.</p>'), '<p>—&nbsp;Да.</p><p>—&nbsp;Нет.</p><p>—&nbsp;Может.</p>');
check('реплика после <br>', $h('<p>Он спросил:<br>- Кто?</p>'), '<p>Он спросил:<br>—&nbsp;Кто?</p>');
check('минус в начале — не тире', ru('-5% за год'), '−5% за год');
check('kk реплика', kk('- Сәлем!'), '—' . NB . 'Сәлем!');
check('kk тире после запятой', kk('- Сәлем, - деді ол.'), '—' . NB . 'Сәлем,' . NB . '— деді ол.');
check('kk тире после «!» и короткое тире', kk('Сәлем! – деді ол.'), 'Сәлем!' . NB . '— деді ол.');
check('kk «--» после «?»', kk('Кім? -- деп сұрады.'), 'Кім?' . NB . '— деп сұрады.');
check('kk минус после запятой — не тире', kk('өсім, -5%'), 'өсім, −5%');
check('тире после запятой, короткое', ru('Привет, – сказал он.'), 'Привет,' . NB . '— сказал он.');
check('минус после запятой — не тире', ru('рост, -5%'), 'рост, −5%');

// раскладка по кусочкам: текст на выходе всегда ровно исправленный
$run = [[0, 'один "два '], [1, 'три" - '], [2, 'четыре...']];
$out = 'один «два три»' . NB . '— четыре…';
check('раскладка сохраняет текст', implode('', Html::distribute($run, $out)), $out);
check('раскладка по местам', implode('|', Html::distribute($run, $out)), 'один «два |три»' . NB . '— |четыре…');

// ------------------------------------------------------------------ повторный прогон ничего не меняет

$samples = [
    '<p>Он сказал: "Нацбанк - регулятор, ставка -0.25 п.п., рост 1.5 млрд..." И т.д.</p><ul><li>в 2020-2025 гг.</li></ul>',
    '<p>Ол "Қазақ" - 5 % 2020-2025 ,т. б. -0.5 млн. теңге</p>',
];

$samples[] = '<p>- Да, - сказал он, и т. д. На 0,25 п.п. выше, 5 млн тенге.</p><p>Қ.К. Тоқаев - "Ақорда" т. б. 2020-2025</p>';

foreach ($samples as $i => $s) {
    $once  = (new Engine())->process($s, '*', true)->text;
    $again = (new Engine())->process($once, '*', true);
    check("повторный прогон #{$i}", $again->text, $once);
    check("повторный прогон #{$i}: правок ноль", (string) $again->total(), '0');
}

check('счёт: только настоящие правки', json_encode((new Engine())->process('На 0,25' . NB . 'п.' . NB . 'п. и "да"', 'ru-RU', false)->stats()), '{"T1":2,"T5":1}');

// ------------------------------------------------------------------ вычитка: находки и внесение принятых

use Webmarka\Component\Redkollegiya\Administrator\Engine\Proof\Proofreader;

$rules = static function (array $found): string {
    return implode(' ', array_map(static fn ($f) => $f['rule'] . ($f['edit'] !== null ? '=' . $f['edit'] : ''), $found));
};
$pr = new Proofreader();

check('вычитка: следы чат-бота', $rules($pr->check('<p>Конечно, вот черновик статьи: Рост 5% oaicite:3 за год.</p>', 'ru-RU')), 'S-FRAME= S-CITE=');
$fx = $pr->check('Рост 5% oaicite:3. Спад turn0search1, 2%.', 'ru-RU', false);
check('вычитка: след чат-бота без знака после', $pr->apply('Рост 5% oaicite:3. Спад turn0search1, 2%.', $fx, 'ru-RU', false)['text'], 'Рост 5%. Спад, 2%.');
check('вычитка: служебный токен и запись HTML', $rules($pr->check('<p>Цена&amp;mdash;высокая<|im_end|></p>', 'ru-RU')), 'S-ENT=— S-TOKEN=');
check('вычитка: «Надеюсь, было полезно»', $rules($pr->check('Рост 5%. Надеюсь, это было полезно!', 'ru-RU', false)), 'S-FRAME=');
check('вычитка: канцелярит с правкой', $rules($pr->check('Данный проект позволяет снизить затраты.', 'ru-RU', false)), 'K-DANN=Этот K-POZV=даёт');
check('вычитка: «позволяет» без инфинитива — к сведению', $rules($pr->check('Проект позволяет компании расти.', 'ru-RU', false)), 'K-POZV');
check('вычитка: в цитате канцелярит не трогается', $rules($pr->check('Министр сказал: «Данный проект является важным».', 'ru-RU', false)), '');
check('вычитка: Розенталь', $rules($pr->check('Согласно приказа и в течении года, по окончанию работ, не смотря на это, вследствии чего надо оплатить за проезд.', 'ru-RU', false)),
    'R-SOGL=Согласно приказу R-TECH=в течение R-PO=по окончании R-NESM=несмотря на R-VSLED=вследствие R-OPLAT=оплатить');
check('вычитка: «в течении реки» не трогается', $rules($pr->check('В течении реки много порогов.', 'ru-RU', false)), '');
check('вычитка: удвоенное слово', $rules($pr->check('Рост составил составил 5%. Так так, да да.', 'ru-RU', false)), 'R-DOUBLE=составил');
check('вычитка: «ё» в двояких', $rules($pr->check('Все равно о нем знают, объем вырос за счет экспорта.', 'ru-RU', false)), 'Y-VSE=Всё Y-NEM=о нём Y-OBEM=объём Y-SCHET=счёт');
check('вычитка: «все пришли» не трогается', $rules($pr->check('Все пришли.', 'ru-RU', false)), '');
check('вычитка: слова-маркеры в новостях не проверяются', $rules((new Proofreader(['genre' => 'news']))->check('Ключевой инновационный проект.', 'ru-RU', false)), '');
check('вычитка: слова-маркеры в разборе', $rules($pr->check('Ключевой инновационный проект.', 'ru-RU', false)), 'S-WORD S-WORD');
check('вычитка: стая', $rules($pr->check('Важно отметить, что данный проект является ключевым в рамках программы.', 'ru-RU', false)),
    'S-CLUSTER S-PHR=Д K-YAVL S-WORD K-RAMK');
check('вычитка: ритм', $rules($pr->check('Рост был высоким весь год. Спрос был большим весь год. Цены были ровными весь год. Банк был спокоен весь год. Рынок был тихим весь год.', 'ru-RU', false)), 'RH-MONO');
check('вычитка: казахский — только следы', $rules($pr->check('Ол данный жоба туралы айтты oaicite:1 әрі.', 'kk-KZ', false)), 'S-CITE=');
check('вычитка: «Как ИИ повлияет…» — не рамка', $rules($pr->check('Как ИИ повлияет на рынок труда?', 'ru-RU', false)), '');
check('вычитка: «Как языковая модель, я…» — рамка', $rules($pr->check('Как языковая модель, я не могу знать курс.', 'ru-RU', false)), 'S-FRAME=');
check('вычитка: «кампания по возвращению активов» — дательный верен', $rules($pr->check('Кампания по возвращению активов идёт.', 'ru-RU', false)), '');
check('вычитка: «на данный момент» → «сейчас»', $rules($pr->check('На данный момент ставка 16,5%.', 'ru-RU', false)), 'K-DANN=Сейчас');
check('вычитка: «может стать» в новостях — норма', $rules((new Proofreader(['genre' => 'news']))->check('Это может стать препятствием.', 'ru-RU', false)), '');
check('вычитка: чистый текст', $rules($pr->check('<p>Нацбанк сохранил ставку на уровне 16,5%.</p>', 'ru-RU')), '');

$src   = '<p>Данный проект <em>позволяет снизить</em> затраты. Согласно приказа министра.</p><p>Надеюсь, это было полезно!</p>';
$found = $pr->check($src, 'ru-RU');
$acc   = array_values(array_filter($found, static fn ($f) => $f['edit'] !== null));
$res   = $pr->apply($src, $acc, 'ru-RU');
check('вычитка: внесение по месту, теги целы', $res['text'], '<p>Этот проект <em>даёт снизить</em> затраты. Согласно приказу министра.</p><p></p>');
check('вычитка: внесено всё', \count($res['applied']) . '/' . \count($res['skipped']), \count($acc) . '/0');

// текст изменился после проверки: правка ищется по цитате, а двоякая цитата не вносится
$moved = '<p>Вступление. Данный проект <em>позволяет снизить</em> затраты. Согласно приказа министра.</p><p>Надеюсь, это было полезно!</p>';
$res2  = $pr->apply($moved, $acc, 'ru-RU');
check('вычитка: сдвиг — по цитате', $res2['text'], '<p>Вступление. Этот проект <em>даёт снизить</em> затраты. Согласно приказу министра.</p><p></p>');
$twice = '<p>Согласно приказа один. Согласно приказа два.</p>';
$f2    = $pr->check($twice, 'ru-RU');
$res3  = $pr->apply('<p>Начало. Согласно приказа один. Согласно приказа два.</p>', [$f2[0]], 'ru-RU');
check('вычитка: двоякая цитата не вносится', \count($res3['skipped']) . ' ' . $res3['text'], '1 <p>Начало. Согласно приказа один. Согласно приказа два.</p>');
check('вычитка: строка без разметки', $pr->apply('  Данный банк. ', $pr->check('  Данный банк. ', 'ru-RU', false), 'ru-RU', false)['text'], '  Этот банк. ');

$archive = getenv('REDKOLLEGIYA_CORPUS');

if ($archive && is_dir($archive)) {
    // сверка на своём корпусе: каждый текст дважды, второй прогон не должен ничего менять; ни одной ошибки и предупреждения
    $files = glob($archive . '/*.txt') ?: [];
    $t0    = microtime(true);
    $bad   = 0;
    $edits = 0;

    foreach ($files as $f) {
        $txt  = (string) file_get_contents($f);
        $html = '<p>' . implode('</p><p>', array_map('htmlspecialchars', preg_split('/\n+/', $txt) ?: [])) . '</p>';
        $r1   = (new Engine())->process($html, 'ru-RU', true);
        $r2   = (new Engine())->process($r1->text, 'ru-RU', true);
        $edits += $r1->total();

        if ($r2->text !== $r1->text) {
            $bad++;

            if ($bad <= 3) {
                echo 'повторный прогон меняет ' . basename($f) . ': ' . json_encode($r2->stats(), JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }

    $fail += $bad ? 1 : 0;
    $pass += $bad ? 0 : 1;
    printf("корпус: %d текстов, %d правок, %.1f мс на текст, %.1f МБ памяти в пике, неустойчивых %d\n",
        \count($files), $edits, 1000 * (microtime(true) - $t0) / max(1, \count($files)), memory_get_peak_usage() / 1048576, $bad);
}

echo "\nпрошло {$pass}, не прошло {$fail}\n";
exit($fail ? 1 : 0);
