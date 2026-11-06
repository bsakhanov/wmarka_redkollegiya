/*
 * Webmarka Редколлегия: страница «Проверить текст». Текст прогоняется тем же типографом, что кнопка в редакторе,
 * правки показаны на месте: вставленное — подчёркнуто, убранное — зачёркнуто, неразрывный пробел — зелёной точкой, убранный пробел — знаком «␣».
 */
const cfg = Joomla.getOptions('com_wmarka_redkollegiya.dashboard') || {};
const $ = (id) => document.getElementById(id);
let fixed = '';

const run = async () => {
  const text = $('rk-text').value;
  if (!text.trim()) return;
  const body = new FormData();
  body.append('text', text);
  body.append('lang', $('rk-lang').value);
  body.append('html', $('rk-html').checked ? '1' : '0');
  body.append('source', 'check');
  body.append(Joomla.getOptions('csrf.token'), '1');
  $('rk-run').disabled = true;
  try {
    const res = await fetch(cfg.url, { method: 'POST', body, credentials: 'same-origin' });
    const data = await res.json().catch(() => ({ ok: false }));
    if (!data.ok) throw new Error(data.error || cfg.error);
    fixed = data.text;
    $('rk-message').textContent = data.message;
    $('rk-marked').innerHTML = data.marked;
    $('rk-marked').lang = data.lang === 'kk' ? 'kk' : 'ru';
    $('rk-result').hidden = false;
    $('rk-copied').hidden = true;
  } catch (e) {
    Joomla.renderMessages({ error: [(e && e.message) || cfg.error] });
  } finally {
    $('rk-run').disabled = false;
  }
};

document.addEventListener('DOMContentLoaded', () => {
  $('rk-run').addEventListener('click', run);
  $('rk-text').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) run();
  });
  $('rk-copy').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(fixed);
      $('rk-copied').hidden = false;
    } catch (e) {
      Joomla.renderMessages({ error: [cfg.error] });
    }
  });
});
