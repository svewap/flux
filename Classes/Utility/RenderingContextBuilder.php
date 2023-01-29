<?php
declare(strict_types=1);
namespace FluidTYPO3\Flux\Utility;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\View\TemplatePaths;

class RenderingContextBuilder
{
    public function buildRenderingContextFor(
        string $extensionIdentity,
        string $controllerName,
        string $controllerActionName,
        ?string $templatePathAndFilename = null
    ): RenderingContextInterface {
        $extensionKey = ExtensionNamingUtility::getExtensionKey($extensionIdentity);

        $renderingContext = $this->createRenderingContextInstance();

        /** @var RequestBuilder $requestBuilder */
        $requestBuilder = GeneralUtility::makeInstance(RequestBuilder::class);
        /** @var RequestInterface&Request $request */
        $request = $requestBuilder->buildRequestFor(
            $extensionIdentity,
            $controllerName,
            'void',
            'void'
        );


        $renderingContext->setRequest($request);
        $renderingContext->setTemplatePaths(new TemplatePaths($extensionKey));


        $templatePaths = $renderingContext->getTemplatePaths();
        $templatePaths->fillDefaultsByPackageName($extensionKey);

        if ($templatePathAndFilename) {
            $templatePaths->setTemplatePathAndFilename($templatePathAndFilename);
        }
        if (method_exists($renderingContext, 'setControllerName')) {
            $renderingContext->setControllerName($controllerName);
        }
        if (method_exists($renderingContext, 'setControllerAction')) {
            $renderingContext->setControllerAction($controllerActionName);
        }
        return $renderingContext;
    }

    /**
     * @codeCoverageIgnore
     */
    private function createRenderingContextInstance(): RenderingContextInterface
    {
        /** @var RenderingContextFactory $renderingContextFactory */
        $renderingContextFactory = GeneralUtility::makeInstance(RenderingContextFactory::class);
        return $renderingContextFactory->create();
    }


}
