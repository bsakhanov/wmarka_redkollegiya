<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Result;

/** @var \Webmarka\Component\Redkollegiya\Administrator\View\Dashboard\HtmlView $this */

$t      = $this->totals;
$groups = [];

foreach (Result::GROUPS as $group => $rules) {
    $n = 0;

    foreach ($rules as $r) {
        $n += $t['rules'][$r] ?? 0;
    }

    if ($n) {
        $groups[$group] = $n;
    }
}

arsort($groups);
$summary = static function (string $json): string {
    $stats = (array) json_decode($json, true);
    $parts = [];

    // вычитка: «+правило» — принято, «-правило» — отклонено
    if ($stats && \in_array(substr((string) array_key_first($stats), 0, 1), ['+', '-'], true)) {
        $plus  = array_sum(array_filter($stats, static fn ($k) => $k[0] === '+', ARRAY_FILTER_USE_KEY));
        $minus = array_sum(array_filter($stats, static fn ($k) => $k[0] === '-', ARRAY_FILTER_USE_KEY));

        return Text::sprintf('COM_WMARKA_REDKOLLEGIYA_PROOF_JOURNAL_ROW', $plus, $minus);
    }

    foreach (Result::GROUPS as $group => $rules) {
        $n = 0;

        foreach ($rules as $r) {
            $n += (int) ($stats[$r] ?? 0);
        }

        if ($n) {
            $parts[] = Text::_('COM_WMARKA_REDKOLLEGIYA_GROUP_' . strtoupper($group)) . ' ' . $n;
        }
    }

    return implode(', ', $parts);
};
?>
<div class="rk">
    <section class="rk-check card mb-4" aria-labelledby="rk-check-title">
        <div class="card-body">
            <h2 id="rk-check-title" class="h4"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_CHECK'); ?></h2>
            <p class="text-body-secondary"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_CHECK_DESC'); ?></p>
            <form id="rk-check-form" class="rk-form" onsubmit="return false">
                <label for="rk-text" class="form-label visually-hidden"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_TEXT'); ?></label>
                <textarea id="rk-text" class="form-control" rows="8" spellcheck="false"
                          placeholder="<?php echo $this->escape(Text::_('COM_WMARKA_REDKOLLEGIYA_TEXT_HINT')); ?>"></textarea>
                <div class="rk-controls">
                    <label class="form-label" for="rk-lang"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_LANGUAGE'); ?></label>
                    <select id="rk-lang" class="form-select">
                        <option value="*"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_LANG_AUTO'); ?></option>
                        <option value="ru-RU"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_LANG_RU'); ?></option>
                        <option value="kk-KZ"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_LANG_KK'); ?></option>
                    </select>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rk-html">
                        <label class="form-check-label" for="rk-html"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_IS_HTML'); ?></label>
                    </div>
                    <button type="button" id="rk-run" class="btn btn-primary"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_RUN'); ?></button>
                </div>
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
            <div id="rk-result" class="rk-result" hidden>
                <p id="rk-message" class="rk-message" role="status"></p>
                <div id="rk-marked" class="rk-marked" lang="ru"></div>
                <div id="rk-findings" class="rk-proof rk-findings" hidden></div>
                <div class="rk-result-actions">
                    <button type="button" id="rk-copy" class="btn btn-secondary btn-sm"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_COPY'); ?></button>
                    <span id="rk-copied" class="text-body-secondary" hidden><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_COPIED'); ?></span>
                </div>
            </div>
        </div>
    </section>

    <section class="rk-state mb-4" aria-label="<?php echo $this->escape(Text::_('COM_WMARKA_REDKOLLEGIYA_STATE')); ?>">
        <p>
            <?php echo Text::_($this->buttonOn ? 'COM_WMARKA_REDKOLLEGIYA_BUTTON_ON' : 'COM_WMARKA_REDKOLLEGIYA_BUTTON_OFF'); ?>
            <?php echo Text::_($this->saveOn ? 'COM_WMARKA_REDKOLLEGIYA_SAVE_ON' : 'COM_WMARKA_REDKOLLEGIYA_SAVE_OFF'); ?>
        </p>
    </section>

    <section class="rk-journal" aria-labelledby="rk-journal-title">
        <h2 id="rk-journal-title" class="h4"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_JOURNAL'); ?></h2>
        <?php if (!$this->journalOn) : ?>
            <p class="text-body-secondary"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_JOURNAL_OFF'); ?></p>
        <?php elseif (!$t['runs'] && !$t['proof']['runs']) : ?>
            <p class="text-body-secondary"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_JOURNAL_EMPTY'); ?></p>
        <?php else : ?>
            <p>
                <?php echo Text::sprintf('COM_WMARKA_REDKOLLEGIYA_TOTALS', $t['runs'], $t['changes']); ?>
                <?php if ($groups) : ?>
                    <?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_TOTALS_GROUPS'); ?>
                    <?php echo $this->escape(implode(', ', array_map(static fn ($g, $n) => Text::_('COM_WMARKA_REDKOLLEGIYA_GROUP_' . strtoupper($g)) . ' ' . $n, array_keys($groups), $groups))); ?>.
                <?php endif; ?>
            </p>
            <?php if ($t['proof']['runs']) : ?>
                <p>
                    <?php echo Text::sprintf('COM_WMARKA_REDKOLLEGIYA_PROOF_TOTALS', $t['proof']['runs'], $t['proof']['accepted'], $t['proof']['declined']); ?>
                    <?php $declinedMost = array_filter($t['proof']['rules'], static fn ($r) => ($r['declined'] ?? 0) > ($r['accepted'] ?? 0)); ?>
                    <?php if ($declinedMost) : ?>
                        <?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_PROOF_TOTALS_DECLINED'); ?>
                        <?php echo $this->escape(implode(', ', array_map(static fn ($rule, $r) => Text::_('COM_WMARKA_REDKOLLEGIYA_RULE_' . str_replace('-', '_', $rule)) . ' (' . (int) ($r['declined'] ?? 0) . ')', array_keys($declinedMost), $declinedMost))); ?>.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm rk-table">
                    <caption class="visually-hidden"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_JOURNAL'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_COL_TIME'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_COL_ITEM'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_COL_WHO'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_COL_SOURCE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_COL_CHANGES'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->recent as $row) : ?>
                        <tr>
                            <td class="text-nowrap"><?php echo HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC5')); ?></td>
                            <td>
                                <?php if ($row->item_id && $row->item_title !== null) : ?>
                                    <a href="<?php echo Route::_('index.php?option=com_content&task=article.edit&id=' . (int) $row->item_id); ?>"><?php echo $this->escape($row->item_title); ?></a>
                                <?php elseif ($row->item_id) : ?>
                                    <?php echo Text::sprintf('COM_WMARKA_REDKOLLEGIYA_ITEM_N', (int) $row->item_id); ?>
                                <?php else : ?>
                                    <span class="text-body-secondary"><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_ITEM_NEW'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $this->escape((string) ($row->user_name ?? '')); ?></td>
                            <td><?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_SOURCE_' . strtoupper(preg_replace('/\W/', '', (string) $row->source))); ?>,
                                <?php echo Text::_('COM_WMARKA_REDKOLLEGIYA_LANG_' . strtoupper((string) $row->lang)); ?></td>
                            <td><?php echo (int) $row->changes; ?>: <?php echo $this->escape($summary((string) $row->stats)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
