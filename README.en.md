# Webmarka Redkollegiya

Typograph and pre-editing for Russian and Kazakh text in Joomla 6. Sets «guillemets», dashes, the minus sign, no-break spaces, the decimal comma and the letter ё — with a button in the editor and by itself on article save.

[Русский](README.md) · [Қазақша](README.kk.md)

## What it fixes

`"Самрук-Казына" еще не ответил...` → `«Самрук-Казына» ещё не ответил…`; `Нацбанк - регулятор` → `Нацбанк — регулятор`; `инфляция -0.5%` → `инфляция −0,5%`; `на 0.25 п.п.` → `на 0,25 п. п.`; `5 млн. тенге` → `5 млн тенге`; `₸ 8,5 млрд` → `₸8,5 млрд`; no-break spaces after one-letter prepositions and between numbers and units; look-alike Latin letters inside Cyrillic words; invisible characters. Kazakh: `Ол "Қазақ" - деді` → `Ол «Қазақ» — деді`, `т. б.` → `т.б.`.

It never touches meaning, grammar, code, links, e-mail addresses, plugin calls like `{loadmoduleid 5}`, tags or attributes. Unpaired straight quotes are left as they are.

## Where it works

- **Typograph button** in the editor: CMS Content menu and toolbar in TinyMCE, below CodeMirror or a plain field. One Ctrl+Z in TinyMCE undoes the whole pass.
- **On article save**: text, title, meta description, image captions and alt text. Runs last, after text cleaning (Webmarka Clean).
- **Components → Webmarka Redkollegiya**: check a text with marked changes, recent runs, 30-day totals.
- **From other extensions**: `Typographer::run($html, 'ru-RU', true, 'mcp', $articleId)`.

## Where the rules come from

- Russian: a PHP port of [Milchin](https://github.com/beaverbeard/milchin) (MIT) — only rules whose right answer does not depend on meaning.
- Kazakh: a PHP port of kk_typo from the [Kazakh Redkollegiya](https://github.com/bsakhanov/kk-redkollegiya), rules Y01–Y14.
- The letter ё: the safe dictionary of [eyo](https://github.com/e2yo/eyo-kernel) by Denis Seleznev (MIT). Proper names are left alone by default.
- House rules: minus sign, decimal comma, «млн» without a dot, em dash instead of an en dash between words, currency sign — only where no mistake is possible.

`tests/parity/compare.py` checks the port against the originals character by character: all 26,139 texts matched at release 0.1.0.

## Install

Install `pkg_wmarka_redkollegiya_v<version>.zip` from the [releases](https://github.com/bsakhanov/com_wmarka_redkollegiya/releases) via System → Install → Extensions. Requires Joomla 6.0+, PHP 8.3+ with `mbstring` (and preferably `intl`). Tested on Joomla 6.2.0 with PHP 8.3 and 8.4.

## License

GNU GPL version 3 or later. The ported parts of Milchin and eyo are MIT; see NOTICE.
