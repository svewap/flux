<?php
namespace FluidTYPO3\Flux\ViewHelpers\Outlet;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\ViewHelpers\AbstractFormViewHelper;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextInterface;

/**
 */
class ValidateViewHelper extends AbstractFormViewHelper {

    /**
     * Initialize
     * @return void
     */
    public function initializeArguments() {
        parent::initializeArguments();
        $this->registerArgument('type', 'string', 'validator to apply', TRUE);
        $this->registerArgument('options', 'array', 'additional validator arguments', FALSE);
    }

    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return void
     */
    static public function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext) {
        $viewHelperVariableContainer = $renderingContext->getViewHelperVariableContainer();

        $validators = $viewHelperVariableContainer->get(ValidateViewHelper::class, 'validators');
        $validators[] = $arguments;
        $viewHelperVariableContainer->addOrUpdate(ValidateViewHelper::class, 'validators', $validators);
    }
}