import { JoomlaEditor, JoomlaEditorButton } from 'editor-api';

/*
 * Webmarka Редколлегия: кнопки «Типограф» и «Вычитка» на панели TinyMCE — в одно нажатие, без меню «Контент».
 * Действия те же, что у пунктов меню (plg_editors-xtd_wmarka_redkollegiya). Настройки редактора на сайте не меняются:
 * панель дополняется на лету при открытии формы.
 */
const ui = Joomla.getOptions('com_wmarka_redkollegiya.tinymce');
const all = Joomla.getOptions('plg_editor_tinymce');
const buttons = (ui && Array.isArray(ui.buttons)) ? ui.buttons : [];
const NAMES = buttons.map((b) => b.name);

const place = (bar) => {
  if (typeof bar !== 'string' || !NAMES.length || NAMES.every((n) => bar.split(/\s+/).includes(n))) return bar;
  const list = bar.split(/\s+/).filter(Boolean).filter((t) => !NAMES.includes(t));
  if (ui.place === 'end') {
    list.push('|', ...NAMES);
  } else {
    // сразу за меню «Контент» (jxtdbuttons), а без него — в начале панели
    list.splice(list[0] === 'jxtdbuttons' ? 1 : 0, 0, '|', ...NAMES, '|');
  }
  return list.filter((t, i, a) => !(t === '|' && (i === 0 || i === a.length - 1 || a[i - 1] === '|'))).join(' ');
};

if (buttons.length && all && all.tinyMCE && window.tinymce) {
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

  Object.values(all.tinyMCE).forEach((set) => {
    if (!set || typeof set !== 'object') return;
    NAMES.forEach((name) => {
      if (typeof set.plugins === 'string' && !set.plugins.split(/[\s,]+/).includes(name)) set.plugins += `,${name}`;
      else if (Array.isArray(set.plugins) && !set.plugins.includes(name)) set.plugins.push(name);
    });
    ['toolbar', 'toolbar1'].forEach((key) => {
      if (typeof set[key] === 'string') set[key] = place(set[key]);
    });
  });
}
