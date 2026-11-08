import { JoomlaEditor, JoomlaEditorButton } from 'editor-api';
import JoomlaDialog from 'joomla.dialog';

/*
 * Webmarka Редколлегия: кнопка «Вычитка». Текст не меняется сам: сервер отдаёт находки, редактор принимает или отклоняет
 * каждую, и только принятые правки вносятся — по месту и дословной цитате, одним шагом (в TinyMCE одно Ctrl+Z).
 * Находки без готовой правки (канцелярит, клише, ритм) — к сведению: переписать фразу может только человек.
 */
const cfg = Joomla.getOptions('com_wmarka_redkollegiya.proof') || {};
const T = cfg.text || {};
let busy = false;

const field = (id) => {
  const el = document.getElementById(id);
  return el ? String(el.value || '') : '';
};

const notify = (text, type) => {
  if (text) Joomla.renderMessages({ [type]: [text] }, undefined, false, type === 'error' ? 0 : 10000);
};

const post = async (url, data) => {
  const body = new FormData();
  Object.entries(data).forEach(([k, v]) => body.append(k, v));
  body.append(Joomla.getOptions('csrf.token'), '1');
  const res = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  const json = await res.json().catch(() => ({ ok: false }));
  if (!json.ok) throw new Error(json.error || T.error);
  return json;
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

const el = (tag, cls, text) => {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text !== undefined && text !== null) node.textContent = text;
  return node;
};

/* Видимый неразрывный пробел в правке: иначе «на 0,25 п. п.» и «на 0,25 п. п.» не отличить. */
const shown = (s) => String(s).replace(/\u00a0/g, '·');

/** Панель находок; resolve(решения) или null, если закрыта без внесения. */
const panel = (findings) => new Promise((resolve) => {
  const state = new Map(); // id → 'accept' | 'decline'
  const root = el('div', 'rk-proof');
  const head = el('p', 'rk-proof__lead', T.lead);
  root.append(head);

  const order = ['title', 'house', 'valikhanov', 'slop', 'altynsarin', 'shakarim', 'baitursynov', 'chukovsky', 'rozental', 'yo'];
  const groups = new Map();
  findings.forEach((f) => {
    if (!groups.has(f.layer)) groups.set(f.layer, []);
    groups.get(f.layer).push(f);
  });

  const rows = new Map();
  const mark = (f, value) => {
    const row = rows.get(f.id);
    state.set(f.id, value);
    row.dataset.state = value;
    row.querySelectorAll('button[data-act]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.act === value ? 'true' : 'false'));
  };

  [...groups.keys()].sort((a, b) => order.indexOf(a) - order.indexOf(b)).forEach((layer) => {
    const list = groups.get(layer);
    const sec = el('section', 'rk-proof__group');
    const head = el('div', 'rk-proof__head');
    head.append(el('h3', 'rk-proof__layer', `${list[0].layerLabel} — ${list.length}`));
    // «млн» → «миллионов» по всему тексту — не по одной: правки слоя принимаются разом, отклонить отдельные можно и после
    const withEdit = list.filter((f) => f.edit !== null && f.edit !== undefined);
    if (withEdit.length >= 2) {
      const all = el('button', 'btn btn-sm btn-outline-success', `${T.acceptGroup} (${withEdit.length})`);
      all.type = 'button';
      all.addEventListener('click', () => withEdit.forEach((f) => mark(f, 'accept')));
      head.append(all);
    }
    sec.append(head);
    list.forEach((f) => {
      const row = el('div', `rk-proof__item rk-proof__item--${f.weight}`);
      row.dataset.state = '';
      const ctx = el('p', 'rk-proof__context');
      ctx.append(document.createTextNode(f.context[0]));
      ctx.append(el('mark', null, f.context[1] || f.quote));
      ctx.append(document.createTextNode(f.context[2]));
      row.append(ctx);
      if (f.edit !== null && f.edit !== undefined) {
        const fix = el('p', 'rk-proof__fix');
        fix.append(el('del', null, shown(f.quote)), document.createTextNode(' → '));
        fix.append(f.edit === '' ? el('em', null, T.remove) : el('ins', null, shown(f.edit)));
        row.append(fix);
      }
      row.append(el('p', 'rk-proof__why', f.message));
      const acts = el('div', 'rk-proof__acts');
      if (f.edit !== null && f.edit !== undefined) {
        const yes = el('button', 'btn btn-sm btn-outline-success', T.accept);
        yes.type = 'button';
        yes.dataset.act = 'accept';
        yes.addEventListener('click', () => mark(f, 'accept'));
        acts.append(yes);
      }
      const no = el('button', 'btn btn-sm btn-outline-secondary', f.edit === null || f.edit === undefined ? T.seen : T.decline);
      no.type = 'button';
      no.dataset.act = 'decline';
      no.addEventListener('click', () => mark(f, 'decline'));
      acts.append(no);
      row.append(acts);
      rows.set(f.id, row);
      sec.append(row);
    });
    root.append(sec);
  });

  const auto = findings.filter((f) => f.auto);
  let done = false;
  // JoomlaDialog при закрытии возвращает содержимое на прежнее место — даём ему это место, иначе ошибка в консоли
  const holder = el('div');
  holder.hidden = true;
  document.body.append(holder);
  holder.append(root);
  const dialog = new JoomlaDialog({
    popupType: 'inline',
    textHeader: T.title,
    popupContent: root,
    width: '64rem',
    height: '86vh',
    className: 'rk-proof-dialog',
    popupButtons: [
      ...(auto.length ? [{ label: `${T.acceptAuto} (${auto.length})`, className: 'btn btn-outline-success', onClick: () => auto.forEach((f) => mark(f, 'accept')) }] : []),
      {
        label: T.apply,
        className: 'btn btn-primary',
        onClick: () => {
          done = true;
          dialog.close();
          resolve({
            accepted: findings.filter((f) => state.get(f.id) === 'accept' && f.edit !== null && f.edit !== undefined),
            declined: findings.filter((f) => state.get(f.id) === 'decline').map((f) => f.rule),
          });
        },
      },
      { label: T.close, className: 'btn btn-secondary', onClick: () => dialog.close() },
    ],
  });
  dialog.addEventListener('joomla-dialog:close', () => {
    dialog.destroy();
    holder.remove();
    if (!done) resolve(null);
  });
  dialog.show();
});

export const proofread = async (editor) => {
  if (busy || !editor || !cfg.check) return;
  busy = true;
  try {
    const before = editor.getValue();
    const lang = field('jform_language') || '*';
    const res = await post(cfg.check, { text: before, lang, html: '1', catid: field('jform_catid') || '0', item: field('jform_id') || '0', title: field('jform_title') });
    if (!res.findings.length) {
      notify(res.message, 'notice');
      return;
    }
    const decision = await panel(res.findings);
    if (!decision || (!decision.accepted.length && !decision.declined.length)) return;
    const now = editor.getValue();
    const out = await post(cfg.apply, {
      text: now,
      lang,
      html: '1',
      item: field('jform_id') || '0',
      accepted: JSON.stringify(decision.accepted.map((f) => ({ id: f.id, block: f.block, offset: f.offset, quote: f.quote, edit: f.edit, rule: f.rule }))),
      declined: JSON.stringify(decision.declined),
    });
    if (out.applied.length && out.text !== now) {
      if (editor.getValue() !== now) {
        notify(T.changed, 'warning');
        return;
      }
      put(editor, out.text);
    }
    notify(out.message, out.skipped.length ? 'warning' : 'message');
  } catch (e) {
    notify((e && e.message) || T.error, 'error');
  } finally {
    busy = false;
  }
};

JoomlaEditorButton.registerAction('wmarka-proof', (editor) => proofread(editor || JoomlaEditor.getActive()));
