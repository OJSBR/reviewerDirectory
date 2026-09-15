<?php

/**
 * @file plugins/generic/reviewerDirectory/ReviewerDirectoryPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewerDirectoryPlugin
 *
 * @brief Internal reviewer directory: a backend page, restricted to managers and
 *  editors, that lists and filters the users with the Reviewer role in the journal.
 */

namespace APP\plugins\generic\reviewerDirectory;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class ReviewerDirectoryPlugin extends GenericPlugin
{
    /** The page (route) served by the plugin. */
    public const PAGE = 'reviewerdirectory';

    /** Roles that may open the directory: the handler and the menu entry use the same list. */
    public const ALLOWED_ROLES = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_SITE_ADMIN];

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
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->callbackLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->callbackSetupBackendPage(...));
        }

        return $success;
    }

    /**
     * Serves the directory page.
     *
     * @param string $hookName
     * @param array $args [&$page, &$op, &$sourceFile, &$handler]
     */
    public function callbackLoadHandler($hookName, $args): bool
    {
        [&$page, &$op, &$sourceFile, &$handler] = $args;
        if ($page !== self::PAGE) {
            return Hook::CONTINUE;
        }

        $handler = new ReviewerDirectoryHandler($this);
        return Hook::ABORT;
    }

    /**
     * Appends a shortcut to the directory at the end of the backend menu, for the roles
     * the page authorizes. The hook is called without arguments.
     */
    public function callbackSetupBackendPage($hookName, $args): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user) {
            return Hook::CONTINUE;
        }

        $templateMgr = TemplateManager::getManager($request);
        $menu = $templateMgr->getState('menu');
        if (!is_array($menu) || !$menu || !$this->userCanAccess($user->getId(), $context->getId())) {
            return Hook::CONTINUE;
        }

        $menu['reviewerDirectory'] = [
            'name' => __('plugins.generic.reviewerDirectory.displayName'),
            'icon' => 'ReviewAssignments',
            'url' => $this->getDirectoryUrl($request),
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ];
        $templateMgr->setState(['menu' => $menu]);

        return Hook::CONTINUE;
    }

    /**
     * Whether the user holds one of the allowed roles in the journal, or is a site administrator.
     */
    protected function userCanAccess(int $userId, int $contextId): bool
    {
        foreach (Repo::userGroup()->userUserGroups($userId, $contextId) as $userGroup) {
            if (in_array((int) $userGroup->roleId, self::ALLOWED_ROLES, true)) {
                return true;
            }
        }

        // The site administrator role belongs to no journal: all the user's groups are checked.
        foreach (Repo::userGroup()->userUserGroups($userId) as $userGroup) {
            if ((int) $userGroup->roleId === Role::ROLE_ID_SITE_ADMIN) {
                return true;
            }
        }

        return false;
    }

    /**
     * The URL of the directory in the current journal.
     */
    public function getDirectoryUrl($request): string
    {
        return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, self::PAGE, 'index');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) {
            return $actions;
        }

        array_unshift($actions, new LinkAction(
            'openDirectory',
            new RedirectAction($this->getDirectoryUrl($request)),
            __('plugins.generic.reviewerDirectory.openDirectory')
        ));

        return $actions;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\reviewerDirectory\ReviewerDirectoryPlugin', '\ReviewerDirectoryPlugin');
}
