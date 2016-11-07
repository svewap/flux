<?php
namespace FluidTYPO3\Flux\ViewHelpers;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextInterface;

/**
 */
class OutletViewHelper extends AbstractFormViewHelper   {

    /**
     * Initialize
     * @return void
     */
    public function initializeArguments() {
        parent::initializeArguments();
        $this->registerArgument('enabled', 'boolean', 'if the outlet is enabled', FALSE, TRUE);
    }

    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return void
     */
    static public function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext) {
        $outlet = static::getFormFromRenderingContext($renderingContext)->getOutlet();
        $outlet->setEnabled($arguments['enabled']);
        $renderChildrenClosure();
    }
}
