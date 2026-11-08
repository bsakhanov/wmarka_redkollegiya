import { JoomlaEditor, JoomlaEditorButton } from 'editor-api';

/*
 * Webmarka Редколлегия: панель TinyMCE для новостной редакции.
 * - Кнопки «Типограф» и «Вычитка» — в одно нажатие, без меню «Контент».
 * - Список «Блоки» без H1: на странице он один, это заголовок материала.
 * - Подзаголовки H2, H3, H4 кнопками — на месте выпадающего списка «Формат» / «Блоки».
 * - Списки — под «⋯», а на их место — кнопки вставки: Фототека, Видео и Файлотека Wmarka Media или штатные «Изображение»
 *   и «Мультимедиа». Порядок сценариев не важен: если Wmarka Media уже поставила свои кнопки, переносятся они.
 * Настройки редактора на сайте не меняются: панель перестраивается на лету при открытии формы.
 */
const ui = Joomla.getOptions('com_wmarka_redkollegiya.tinymce');
const all = Joomla.getOptions('plg_editor_tinymce');
const buttons = (ui && Array.isArray(ui.buttons)) ? ui.buttons : [];
const headings = (ui && Array.isArray(ui.headings)) ? ui.headings : [];
const NAMES = buttons.map((b) => b.name);
const HEADS = headings.map((h) => `wmarka${h.tag}`);
const INSERT = ['wmarkamedia', 'wmarkavideo', 'wmarkafiles', 'image', 'media'];
const LISTS = ['bullist', 'numlist'];

const tidy = (list) => list.filter((t, i, a) => !(t === '|' && (i === 0 || i === a.length - 1 || a[i - 1] === '|'))).join(' ');

const place = (bar) => {
  if (typeof bar !== 'string') return bar;
  let list = bar.split(/\s+/).filter(Boolean);

  // Типограф и Вычитка: сразу за меню «Контент» (jxtdbuttons), а без него — в начале; или в конце панели
  if (NAMES.length && !NAMES.every((n) => list.includes(n))) {
    list = list.filter((t) => !NAMES.includes(t));
    if (ui.place === 'end') list.push('|', ...NAMES);
    else list.splice(list[0] === 'jxtdbuttons' ? 1 : 0, 0, '|', ...NAMES, '|');
  }

  // подзаголовки: на месте «Формата» (styles) или «Блоков» (blocks); без них — за группой «Жирный, Курсив»
  if (HEADS.length && !HEADS.some((h) => list.includes(h))) {
    const at = ['styles', 'blocks'].map((t) => list.indexOf(t)).find((i) => i !== -1);
    if (at !== undefined) list.splice(at, 1, ...HEADS);
    else {
      const after = ['underline', 'italic', 'bold'].map((t) => list.indexOf(t)).find((i) => i !== -1);
      list.splice(after === undefined ? list.length : after + 1, 0, '|', ...HEADS, '|');
    }
    list = list.filter((t) => t !== 'styles' && t !== 'blocks');
  }

  // списки — в конец панели (под «⋯»), на их место — кнопки вставки
  if (ui.listsMore) {
    const at = LISTS.map((t) => list.indexOf(t)).filter((i) => i !== -1).sort((a, b) => a - b)[0];
    const tail = list.filter((t) => t !== '|').slice(-LISTS.filter((t) => list.includes(t)).length);
    // списки уже в конце панели — перестраивать нечего (повторный проход, другой сценарий)
    if (at !== undefined && !tail.every((t) => LISTS.includes(t))) {
      const ins = list.filter((t) => INSERT.includes(t));
      const lists = list.filter((t) => LISTS.includes(t));
      const marker = '\u0000';
      list.splice(at, 0, marker);
      list = list.filter((t) => !INSERT.includes(t) && !LISTS.includes(t));
      list.splice(list.indexOf(marker), 1, ...(ins.length ? ['|', ...ins, '|'] : []));
      list.push('|', ...lists);
    }
  }

  return tidy(list);
};

if (ui && ui.blockFormats && all && all.tinyMCE) {
  Object.values(all.tinyMCE).forEach((set) => {
    if (set && typeof set === 'object') set.block_formats = ui.blockFormats;
  });
}

if (ui && all && all.tinyMCE && window.tinymce) {
  buttons.forEach((b) => {
    window.tinymce.PluginManager.add(b.name, (editor) => {
      editor.ui.registry.addIcon(b.name, b.icon);
      editor.ui.registry.addButton(b.name, {
        icon: b.name,
        text: b.text,
        tooltip: b.tooltip,
        onAction: () => {
          JoomlaEditor.setActive(editor.id);
          JoomlaEditorButton.runAction(b.action, {});
        },
      });
      return { getMetadata: () => ({ name: 'Webmarka Редколлегия', url: 'https://webmarka.kz' }) };
    });
  });

  if (headings.length) {
    // H2, H3, H4: нажатие ставит подзаголовок, повторное — возвращает абзац; кнопка подсвечена, пока курсор в таком подзаголовке
    window.tinymce.PluginManager.add('wmarkaheadings', (editor) => {
      headings.forEach((h) => {
        editor.ui.registry.addToggleButton(`wmarka${h.tag}`, {
          text: h.text,
          tooltip: h.tooltip,
          onAction: () => editor.execCommand('mceToggleFormat', false, h.tag),
          onSetup: (api) => {
            const off = editor.formatter.formatChanged(h.tag, (state) => api.setActive(state));
            return () => off.unbind();
          },
        });
      });
      return { getMetadata: () => ({ name: 'Webmarka Редколлегия', url: 'https://webmarka.kz' }) };
    });
  }

  const plugins = [...NAMES, ...(headings.length ? ['wmarkaheadings'] : [])];
  Object.values(all.tinyMCE).forEach((set) => {
    if (!set || typeof set !== 'object') return;
    plugins.forEach((name) => {
      if (typeof set.plugins === 'string' && !set.plugins.split(/[\s,]+/).includes(name)) set.plugins += `,${name}`;
      else if (Array.isArray(set.plugins) && !set.plugins.includes(name)) set.plugins.push(name);
    });
    ['toolbar', 'toolbar1'].forEach((key) => {
      if (typeof set[key] === 'string') set[key] = place(set[key]);
    });
  });
}
