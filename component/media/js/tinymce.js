import { JoomlaEditor, JoomlaEditorButton } from 'editor-api';

/*
 * Webmarka Редколлегия: кнопка «Типограф» на панели TinyMCE — в одно нажатие, без меню «Контент».
 * Действие то же, что у пункта «Типограф» в меню (plg_editors-xtd_wmarka_redkollegiya). Настройки редактора на сайте
 * не меняются: панель дополняется на лету при открытии формы.
 */
const ui = Joomla.getOptions('com_wmarka_redkollegiya.tinymce');
const all = Joomla.getOptions('plg_editor_tinymce');
const NAME = 'wmarkatypograph';

const place = (bar) => {
  if (typeof bar !== 'string' || bar.split(/\s+/).includes(NAME)) return bar;
  const list = bar.split(/\s+/).filter(Boolean);
  if (ui.place === 'end') {
    list.push('|', NAME);
  } else {
    // сразу за меню «Контент» (jxtdbuttons), а без него — в начале панели
    list.splice(list[0] === 'jxtdbuttons' ? 1 : 0, 0, '|', NAME, '|');
  }
  return list.filter((t, i, a) => !(t === '|' && (i === 0 || i === a.length - 1 || a[i - 1] === '|'))).join(' ');
};

if (ui && all && all.tinyMCE && window.tinymce) {
  window.tinymce.PluginManager.add(NAME, (editor) => {
    editor.ui.registry.addIcon(NAME, ui.icon);
    editor.ui.registry.addButton(NAME, {
      icon: NAME,
      text: ui.text,
      tooltip: ui.tooltip,
      onAction: () => {
        JoomlaEditor.setActive(editor.id);
        JoomlaEditorButton.runAction('wmarka-typograph', {});
      },
    });
    return { getMetadata: () => ({ name: 'Webmarka Редколлегия', url: 'https://webmarka.kz' }) };
  });

  Object.values(all.tinyMCE).forEach((set) => {
    if (!set || typeof set !== 'object') return;
    if (typeof set.plugins === 'string' && !set.plugins.split(/[\s,]+/).includes(NAME)) set.plugins += `,${NAME}`;
    else if (Array.isArray(set.plugins) && !set.plugins.includes(NAME)) set.plugins.push(NAME);
    ['toolbar', 'toolbar1'].forEach((key) => {
      if (typeof set[key] === 'string') set[key] = place(set[key]);
    });
  });
}
