<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Engine;
use Webmarka\Component\Redkollegiya\Administrator\Service\Journal;
use Webmarka\Component\Redkollegiya\Administrator\Service\Typographer;

\defined('_JEXEC') or die;

/**
 * Вычитка (JSON): proof.check отдаёт находки, proof.apply вносит принятые и пишет решения в журнал.
 * Текст на сервере не хранится; пускает того, кто может писать или править материалы.
 */
class ProofController extends BaseController
{
    private const LIMIT = 4194304;

    public function check(): void
    {
        $this->guard();
        $text  = (string) $this->input->post->get('text', '', 'raw');
        $lang  = (string) $this->input->post->getString('lang', '*');
        $html  = (bool) $this->input->post->getInt('html', 1);
        $item  = $this->input->post->getInt('item', 0);
        $orig  = Typographer::original($item, Engine::language($lang, $text));
        $proof = Typographer::proofreader($this->input->post->getInt('catid', 0), null, $orig);
        $title = (string) $this->input->post->getString('title', '');
        $found = Typographer::describe(array_merge($proof->title($title, $lang), $proof->check($text, $lang, $html)));
        $code  = Engine::language($lang, $text);

        $this->json([
            'ok'       => true,
            'lang'     => $code ?: 'other',
            'findings' => $found,
            'message'  => match (true) {
                $code === ''  => Text::_('COM_WMARKA_REDKOLLEGIYA_PROOF_OTHER_LANG'),
                !$found       => Text::_('COM_WMARKA_REDKOLLEGIYA_PROOF_NONE'),
                default       => Text::plural('COM_WMARKA_REDKOLLEGIYA_PROOF_FOUND', \count($found)),
            },
        ]);
    }

    public function apply(): void
    {
        $this->guard();
        $text     = (string) $this->input->post->get('text', '', 'raw');
        $lang     = (string) $this->input->post->getString('lang', '*');
        $html     = (bool) $this->input->post->getInt('html', 1);
        $item     = $this->input->post->getInt('item', 0);
        $accepted = json_decode((string) $this->input->post->get('accepted', '[]', 'raw'), true);
        $declined = json_decode((string) $this->input->post->get('declined', '[]', 'raw'), true);
        $accepted = \is_array($accepted) ? array_values(array_filter($accepted, 'is_array')) : [];
        $declined = \is_array($declined) ? array_values(array_filter($declined, 'is_string')) : [];

        $result = Typographer::proofreader()->apply($text, $accepted, $lang, $html);
        $rules  = [];

        foreach ($accepted as $a) {
            if (\in_array((string) ($a['id'] ?? ''), $result['applied'], true)) {
                $rules[] = (string) ($a['rule'] ?? '');
            }
        }

        Journal::proof(array_filter($rules), $declined, Engine::language($lang, $text) ?: 'other', $item);

        $this->json([
            'ok'      => true,
            'text'    => $result['text'],
            'applied' => $result['applied'],
            'skipped' => $result['skipped'],
            'message' => Text::sprintf('COM_WMARKA_REDKOLLEGIYA_PROOF_DONE', \count($result['applied']), \count($declined), \count($result['skipped'])),
        ]);
    }

    private function guard(): void
    {
        if (!Session::checkToken('post')) {
            $this->json(['ok' => false, 'error' => Text::_('JINVALID_TOKEN')], 403);
        }

        $user = $this->app->getIdentity();

        if (!$user || !($user->authorise('core.manage', 'com_wmarka_redkollegiya') || $user->authorise('core.create', 'com_content')
            || $user->authorise('core.edit', 'com_content') || $user->authorise('core.edit.own', 'com_content'))) {
            $this->json(['ok' => false, 'error' => Text::_('JERROR_ALERTNOAUTHOR')], 403);
        }

        if (\strlen((string) $this->input->post->get('text', '', 'raw')) > self::LIMIT) {
            $this->json(['ok' => false, 'error' => Text::_('COM_WMARKA_REDKOLLEGIYA_TOO_LONG')], 413);
        }
    }

    private function json(array $data, int $status = 200): never
    {
        $this->app->setHeader('status', (string) $status, true);
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->app->close();
    }
}
