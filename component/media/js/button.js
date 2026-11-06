import { JoomlaEditor, JoomlaEditorButton } from 'editor-api';

/*
 * Webmarka Редколлегия: кнопка «Типограф» в редакторе (меню «Контент» TinyMCE, кнопки под CodeMirror и простым полем).
 * Текст уходит на сервер компонента, возвращается исправленным и встаёт на место одним шагом:
 * в TinyMCE одно Ctrl+Z возвращает текст до прогона. Язык — из поля «Язык» материала («Все» — по буквам текста).
 */
const cfg = Joomla.getOptions('com_wmarka_redkollegiya.button') || {};
let busy = false;

const field = (id) => {
  const el = document.getElementById(id);
  return el ? String(el.value || '') : '';
};

const notify = (text, type) => {
  if (!text) return;
  Joomla.renderMessages({ [type]: [text] }, undefined, false, type === 'error' ? 0 : 10000);
};

const put = (editor, html) => {
  const raw = typeof editor.getRawInstance === 'function' ? editor.getRawInstance() : null;
  if (typeof editor.getType === 'function' && editor.getType() === 'tinymce' && raw && raw.undoManager) {
    raw.undoManager.transact(() => raw.setContent(html));
    raw.setDirty(true);
    raw.nodeChanged();
  } else {
    editor.setValue(html);
  }
};

export const typograph = async (editor) => {
  if (busy || !editor || !cfg.url) return;
  busy = true;
  const before = editor.getValue();
  const body = new FormData();
  body.append('text', before);
  body.append('lang', field('jform_language') || '*');
  body.append('html', '1');
  body.append('item', field('jform_id') || '0');
  body.append(Joomla.getOptions('csrf.token'), '1');

  try {
    const res = await fetch(cfg.url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json().catch(() => ({ ok: false }));
    if (!data.ok) throw new Error(data.error || cfg.error);
    if (data.total > 0 && data.text !== before) {
      // пока шёл ответ, текст правили руками — не затираем чужую правку
      if (editor.getValue() !== before) {
        notify(cfg.changed, 'warning');
        return;
      }
      put(editor, data.text);
    }
    notify(data.message, data.total > 0 ? 'message' : 'notice');
  } catch (e) {
    notify((e && e.message) || cfg.error, 'error');
  } finally {
    busy = false;
  }
};

JoomlaEditorButton.registerAction('wmarka-typograph', (editor) => typograph(editor || JoomlaEditor.getActive()));
