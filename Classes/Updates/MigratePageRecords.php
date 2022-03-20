<?php

declare(strict_types=1);

namespace FluidTYPO3\Flux\Updates;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Updates\ConfirmableInterface;
use TYPO3\CMS\Install\Updates\Confirmation;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;


class MigratePageRecords implements UpgradeWizardInterface, ConfirmableInterface
{
    /**
     * @var Confirmation
     */
    protected $confirmation;

    public function __construct()
    {
        $this->confirmation = new Confirmation(
            'Please make sure to read the following carefully:',
            $this->getDescription(),
            false,
            'Yes, I understand!',
            '',
            true
        );
    }

    /**
     * @return string Unique identifier of this updater
     */
    public function getIdentifier(): string
    {
        return 'fluxMigratePageRecords';
    }

    /**
     * @return string Title of this updater
     */
    public function getTitle(): string
    {
        return 'Flux: Migrate page records';
    }

    /**
     * @return string Longer description of this updater
     */
    public function getDescription(): string
    {
        return 'Converting fluidpages__fluidpages value to flux__grid value in backend layout';
    }

    /**
     *
     * @return bool Whether an update is required (TRUE) or not (FALSE)
     */
    public function updateNecessary(): bool
    {

        if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('fluidpages')) {
            return false;
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->count('uid')->from('pages')->where(
            $queryBuilder->expr()->orX(
                $queryBuilder->expr()->eq('backend_layout', $queryBuilder->createNamedParameter('fluidpages__fluidpages', \PDO::PARAM_STR)),
                $queryBuilder->expr()->eq('backend_layout_next_level', $queryBuilder->createNamedParameter('fluidpages__fluidpages', \PDO::PARAM_STR)),
                $queryBuilder->expr()->eq('backend_layout', $queryBuilder->createNamedParameter('fluidpages__grid', \PDO::PARAM_STR)),
                $queryBuilder->expr()->eq('backend_layout_next_level', $queryBuilder->createNamedParameter('fluidpages__grid', \PDO::PARAM_STR))
            )
        );
        return $queryBuilder->execute()->fetchColumn(0) > 0;
    }

    /**
     * @return string[] All new fields and tables must exist
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    /**
     * This upgrade wizard has informational character only, it does not perform actions.
     *
     * @return bool Whether everything went smoothly or not
     */
    public function executeUpdate(): bool
    {

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->update('pages');
        $q1 = clone $queryBuilder;
        $q2 = clone $queryBuilder;
        $q1->set('backend_layout', 'flux__grid')
            ->where(
                $q1->expr()->orX(
                    $q1->expr()->eq('backend_layout', $q1->createNamedParameter('fluidpages__fluidpages', \PDO::PARAM_STR)),
                    $q1->expr()->eq('backend_layout_next_level', $q1->createNamedParameter('fluidpages__grid', \PDO::PARAM_STR))
                )
            )->execute();
        $q2->set('backend_layout_next_level', 'flux__grid')
            ->where(
                $q2->expr()->orX(
                    $q2->expr()->eq('backend_layout_next_level', $q2->createNamedParameter('fluidpages__fluidpages', \PDO::PARAM_STR)),
                    $q2->expr()->eq('backend_layout_next_level', $q2->createNamedParameter('fluidpages__grid', \PDO::PARAM_STR))
                )
            )->execute();

        
        return true;
    }

    /**
     * Return a confirmation message instance
     *
     * @return Confirmation
     */
    public function getConfirmation(): Confirmation
    {
        return $this->confirmation;
    }
}
