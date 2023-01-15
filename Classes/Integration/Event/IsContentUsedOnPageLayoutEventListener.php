<?php
namespace FluidTYPO3\Flux\Integration\Event;

use TYPO3\CMS\Backend\View\Event\IsContentUsedOnPageLayoutEvent;

class IsContentUsedOnPageLayoutEventListener {

    public function __invoke(IsContentUsedOnPageLayoutEvent $event): void
    {

        $record = $event->getRecord();
        $context = $event->getPageLayoutContext();

        if ($record['colPos'] > 1000) {
            $event->setUsed(true);
        }

    }


}
