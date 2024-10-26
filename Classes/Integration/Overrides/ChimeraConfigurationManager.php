<?php
declare(strict_types=1);
namespace FluidTYPO3\Flux\Integration\Overrides;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Extbase\Configuration\BackendConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\FrontendConfigurationManager;

class ChimeraConfigurationManager extends AbstractChimeraConfigurationManager
{


    public function setRequest(ServerRequestInterface $request): void
    {
        $this->updateRequest($request);
    }
}
