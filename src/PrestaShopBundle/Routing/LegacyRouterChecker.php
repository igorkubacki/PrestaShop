<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace PrestaShopBundle\Routing;

use Dispatcher;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface;
use PrestaShopBundle\Entity\Repository\TabRepository;
use Symfony\Bundle\FrameworkBundle\Routing\Attribute\AsRoutingConditionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * This checker is bound to the LegacyController that wraps legacy controller, its matching condition are based on the query parameters.
 * Its priority is set low on purpose because it should be the last route to match in favor of all the other "real" Symfony route.
 */
#[AsRoutingConditionService(priority: -1)]
class LegacyRouterChecker
{
    public function __construct(
        protected readonly TabRepository $tabRepository,
        protected readonly HookDispatcherInterface $hookDispatcher,
    ) {
    }

    public function check(Request $request): bool
    {
        if (!$request->query->has('controller') && !$request->request->has('controller')) {
            return false;
        }

        $controller = $request->get('controller');
        // AdminLogin must remain accessible so ignoring it prevents having to handle multiple exceptions to ignore security on it
        if (empty($controller)) {
            return false;
        }

        // Now we are in a condition to display a legacy controller, so we check that the controller class exists
        $queryController = $request->get('controller');
        $this->hookDispatcher->dispatchWithParameters('actionDispatcherBefore', ['controller_type' => Dispatcher::FC_ADMIN]);
        $tab = $this->tabRepository->findOneByClassName($queryController);
        $isModule = $tab && !empty($tab->getModule());

        if ($isModule) {
            $moduleName = $tab->getModule();
            $controllers = Dispatcher::getControllers(_PS_MODULE_DIR_ . $moduleName . '/controllers/admin/');
            if (!isset($controllers[strtolower($queryController)])) {
                throw new NotFoundHttpException(sprintf('Unknown controller %s', $queryController));
            } else {
                $controllerName = $controllers[strtolower($queryController)];
                // Controllers in modules can be named AdminXXX.php or AdminXXXController.php
                include_once _PS_MODULE_DIR_ . "{$moduleName}/controllers/admin/$controllerName.php";
                if (file_exists(
                    _PS_OVERRIDE_DIR_ . "modules/{$moduleName}/controllers/admin/$controllerName.php"
                )) {
                    include_once _PS_OVERRIDE_DIR_ . "modules/{$moduleName}/controllers/admin/$controllerName.php";
                    $controllerClass = $controllerName . (
                        strpos($controllerName, 'Controller') ? 'Override' : 'ControllerOverride'
                    );
                } else {
                    $controllerClass = $controllerName . (
                        strpos($controllerName, 'Controller') ? '' : 'Controller'
                    );
                }
            }
        } else {
            $controllers = Dispatcher::getControllers(
                [
                    _PS_ADMIN_CONTROLLER_DIR_,
                    _PS_OVERRIDE_DIR_ . 'controllers/admin/',
                ]
            );

            // Controller not found, previously the legacy Dispatcher rendered the first child if present which doesn't make sense.
            // It's clearer to actually return a not found exception, for now the dispatcher is still used as fallback in index.php
            // but when it's cleared and only Symfony handles the whole routing then we can display a proper not found Symfony page
            if (!isset($controllers[strtolower($queryController)])) {
                $controllerClass = 'AdminNotFoundController';
            } else {
                $controllerClass = $controllers[strtolower($queryController)];
            }
        }
        // We load the controller early in the process (during router matching actually), because the controller
        // configuration has many impacts on the contexts, the security listeners, ... And the relevant data can
        // only be retrieved once the legacy class is instantiated to access its public configuration
        // But for performance issues we only instantiate (and init) the controller here once and then store it (along
        // with other related attributes) in the request attributes so they can be retrieved easily by the code depending on them
        $adminController = new $controllerClass();
        $adminController->init();

        $request->attributes->set(LegacyControllerConstants::INSTANCE_ATTRIBUTE, $adminController);
        $request->attributes->set(LegacyControllerConstants::ANONYMOUS_ATTRIBUTE, $adminController->isAnonymousAllowed());
        $request->attributes->set(LegacyControllerConstants::IS_ALL_SHOP_CONTEXT_ATTRIBUTE, $adminController->multishop_context === ShopConstraint::ALL_SHOPS);
        $request->attributes->set(LegacyControllerConstants::CLASS_ATTRIBUTE, $controllerClass);
        $request->attributes->set(LegacyControllerConstants::IS_MODULE_ATTRIBUTE, $isModule);

        return true;
    }
}
