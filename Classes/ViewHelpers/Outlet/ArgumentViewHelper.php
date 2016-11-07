<?php
namespace FluidTYPO3\Flux\ViewHelpers\Outlet;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Outlet\OutletArgument;
use FluidTYPO3\Flux\ViewHelpers\AbstractFormViewHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextInterface;

/**
 */
class ArgumentViewHelper extends AbstractFormViewHelper {

    /**
     * Initialize
     * @return void
     */
    public function initializeArguments() {
        parent::initializeArguments();
        $this->registerArgument('name', 'string', 'name of the argument', TRUE);
        $this->registerArgument('type', 'string', 'type of the argument', FALSE, 'string');
    }

    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return void
     */
    static public function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext) {
        $outlet = static::getFormFromRenderingContext($renderingContext)->getOutlet();
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $argument = $objectManager->get(OutletArgument::class, $arguments['name'], $arguments['type']);

        $viewHelperVariableContainer = $renderingContext->getViewHelperVariableContainer();
        $viewHelperVariableContainer->addOrUpdate(ValidateViewHelper::class, 'validators', array());
        $renderChildrenClosure();
        $validators = $viewHelperVariableContainer->get(ValidateViewHelper::class, 'validators');
        foreach ($validators as $validator) {
            $argument->addValidator($validator['type'], $validator['options']);
        }
        $outlet->addArgument($argument);
    }
}
