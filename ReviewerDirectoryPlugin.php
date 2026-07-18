<?php

/**
 * @file ReviewerDirectoryPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewerDirectoryPlugin
 *
 * @brief Diretório interno de avaliadores: página de backend, restrita a
 *  gerentes e editores, que lista e permite filtrar os usuários com papel de
 *  Avaliador cadastrados na revista.
 */

namespace APP\plugins\generic\reviewerDirectory;

use APP\core\Application;
use PKP\core\Registry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class ReviewerDirectoryPlugin extends GenericPlugin
{
    /** Nome da página (rota) servida por este plugin. */
    public const PAGE = 'reviewerdirectory';

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.reviewerDirectory.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.reviewerDirectory.description');
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                // Registra a rota da página do diretório.
                Hook::add('LoadHandler', $this->callbackLoadHandler(...));
            }
            return true;
        }
        return false;
    }

    /**
     * Intercepta o roteamento para servir a página do diretório de avaliadores.
     *
     * @param string $hookName
     * @param array $args [$page, $op, $sourceFile, $handler]
     *
     * @return bool
     */
    public function callbackLoadHandler($hookName, $args)
    {
        $page = $args[0];
        if ($page !== self::PAGE) {
            return false;
        }

        $handler = &$args[3];
        $handler = new ReviewerDirectoryHandler($this);
        return true;
    }

    /**
     * URL da página do diretório no contexto atual.
     */
    public function getDirectoryUrl($request): string
    {
        return $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            null,
            self::PAGE,
            'index'
        );
    }

    /**
     * @copydoc Plugin::getActions()
     *
     * Adiciona um atalho "Abrir diretório" na listagem de plugins.
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        array_unshift(
            $actions,
            new LinkAction(
                'openDirectory',
                new RedirectAction($this->getDirectoryUrl($request)),
                __('plugins.generic.reviewerDirectory.openDirectory'),
                null
            )
        );
        return $actions;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\reviewerDirectory\ReviewerDirectoryPlugin', '\ReviewerDirectoryPlugin');
}
