<?php

declare(strict_types=1);

namespace FluidTYPO3\Flux\Updates;

use Doctrine\DBAL\Driver\Statement;
use FluidTYPO3\Flux\Provider\Interfaces\FluidProviderInterface;
use FluidTYPO3\Flux\Provider\Interfaces\GridProviderInterface;
use FluidTYPO3\Flux\Service\FluxService;
use FluidTYPO3\Flux\Utility\ColumnNumberUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\BackendWorkspaceRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendGroupRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendWorkspaceRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Updates\ConfirmableInterface;
use TYPO3\CMS\Install\Updates\Confirmation;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;


class MigrateContentRecords implements UpgradeWizardInterface, ConfirmableInterface
{
    /**
     * @var Confirmation
     */
    protected $confirmation;

    public function __construct()
    {
        $possibleConflictingRecords = $this->loadPossiblyConflictingRecords();

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
        return 'fluxMigrateContentRecords';
    }

    /**
     * @return string Title of this updater
     */
    public function getTitle(): string
    {
        return 'Flux: Migrate content records';
    }

    /**
     * @return string Longer description of this updater
     */
    public function getDescription(): string
    {
        return 'Convert old colPos 18181 value to the new format';
    }

    /**
     *
     * @return bool Whether an update is required (TRUE) or not (FALSE)
     */
    public function updateNecessary(): bool
    {

        $schemaManager = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->getSchemaManager();
        if ($schemaManager) {
            $columns = $schemaManager->listTableColumns('tt_content');
            if (!array_key_exists('tx_flux_column',$columns)) return false;
            if (!array_key_exists('tx_flux_parent',$columns)) return false;
        }


        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
        $queryBuilder->getRestrictions()->removeByType(StartTimeRestriction::class);
        $queryBuilder->getRestrictions()->removeByType(EndTimeRestriction::class);
        $queryBuilder->getRestrictions()->removeByType(BackendWorkspaceRestriction::class);
        $queryBuilder->getRestrictions()->removeByType(FrontendGroupRestriction::class);
        $queryBuilder->getRestrictions()->removeByType(FrontendWorkspaceRestriction::class);
        $count = $queryBuilder->count('uid')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(18181, \PDO::PARAM_INT)))
            ->execute()->fetchColumn(0);
        return $count > 0;
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

        // Integrity check and data gathering loop. Verify that all records which have a Provider which returns a Grid,
        // are able to load the template that is associated with it. Failure to load the template gets analysed and the
        // reason gets reported; if the reason is "required argument colPos not used on flux:grid.column" the failure
        // gets reported specially as a required migration.
        $statement = $this->loadContentRecords();

        while ($row = $statement->fetch()) {
            // Check 1: If this record has Provider(s), check if it will return a Grid. If it cannot, and the failure is
            // that colPos is not provided for the ViewHelper, track this template as one that needs migration and track
            // the content record as a parent which requires migration.
            $parentUid = $row['uid'];
            $uidForColumnPositionCalculation = $row['l18n_parent'] ?: $row['uid'];


            foreach ($this->loadProvidersForRecord($row) as $provider) {
                try {
                    if ($provider instanceof FluidProviderInterface) {
                        $templatePathAndFilename = $provider->getTemplatePathAndFilename($row);
                    } else {
                        $templatePathAndFilename = sprintf(
                            'Not a file-based grid. Manual migration of class "%s" may be necessary!',
                            get_class($provider)
                        );
                    }
                    $grid = $provider->getGrid($row);
                    foreach ($grid->getRows() as $gridRow) {
                        foreach ($gridRow->getColumns() as $gridColumn) {
                            $name = $gridColumn->getName();
                            $columnPosition = $gridColumn->getColumnPosition();
                            $columnPositionMigrationMap[$parentUid][$name] = ColumnNumberUtility::calculateColumnNumberForParentAndColumn(
                                $uidForColumnPositionCalculation,
                                $columnPosition
                            );
                        }
                    }
                    unset($grid);
                } catch (\TYPO3Fluid\Fluid\Core\Parser\Exception $exception) {
                    if (strpos($exception->getMessage(), 'Required argument "colPos" was not supplied.') !== false) {
                        $templateFilesRequiringMigration[$templatePathAndFilename] = $exception->getMessage();
                    } else {
                        $templateFilesWithErrors[$templatePathAndFilename] = $exception->getMessage();
                    }
                } catch (\Exception $exception) {
                    $templateFilesWithErrors[$templatePathAndFilename] = $exception->getMessage();
                }
            }

            // Check 2: If this content record has the legacy Flux colPos value 18181, it needs adjustments. We collect
            // the UID values in a minimal array which we can process in a separate loop. We add a second check if the
            // record was already migrated, to protect the edge case of parent=181 and column=81 from being processed.
            if ((int)$row['colPos'] === 18181 && empty($row['tx_flux_migrated_version'])) {
                $childContentRequiringMigration[] = [
                    'uid' => $row['uid'],
                    'pid' => $row['pid'],
                    'colPos' => $row['colPos'],
                    'tx_flux_column' => $row['tx_flux_column'],
                    'tx_flux_parent' => $row['tx_flux_parent']
                ];
            }
            unset($row);
        }

        foreach ($childContentRequiringMigration as $childContent) {
            $newColumnPosition = $columnPositionMigrationMap[$childContent['tx_flux_parent']][$childContent['tx_flux_column']] ?? null;
            if ($newColumnPosition === null) {
                $notMigratedChildContentUids[] = $childContent['uid'];
            } else {
                $migratedChildContent[] = $this->fixColumnPositionInRecord(
                    $childContent,
                    $newColumnPosition
                );
            }
        }

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


    protected function loadPossiblyConflictingRecords(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid', 'pid', 'colPos')
            ->from('tt_content')
            ->andWhere(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->neq('colPos', 18181),
                $queryBuilder->expr()->gt('colPos', 99),
                $queryBuilder->expr()->isNull('tx_flux_migrated_version')
            );
        return $queryBuilder->execute()->fetchAll();
    }

    protected function loadContentRecords(): Statement
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
        $queryBuilder->getRestrictions()->removeByType(StartTimeRestriction::class);
        $queryBuilder->getRestrictions()->removeByType(EndTimeRestriction::class);
        $queryBuilder->getRestrictions()->removeByType(BackendWorkspaceRestriction::class);
        $queryBuilder->getRestrictions()->removeByType(FrontendGroupRestriction::class);
        $queryBuilder->getRestrictions()->removeByType(FrontendWorkspaceRestriction::class);
        $queryBuilder->select('*')->from('tt_content');
        return $queryBuilder->execute();
    }

    protected function fixColumnPositionInRecord(array $record, int $newColumnPosition)
    {
        $recordUid = $record['uid'];
        unset($record['uid']);
        $record['colPos'] = $newColumnPosition;
        $record['tx_flux_migrated_version'] = '9.0';
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->update('tt_content')->where($queryBuilder->expr()->eq('uid', $recordUid));
        foreach ($record as $key => $value) {
            $queryBuilder->set($key, $value, true);
        }
        $queryBuilder->execute();
        return ['uid' => $recordUid] + $record;
    }

    /**
     * @param array $record
     * @return GridProviderInterface[]
     */
    protected function loadProvidersForRecord(array $record): array
    {
        return GeneralUtility::makeInstance(FluxService::class)->resolveConfigurationProviders(
            'tt_content',
            null,
            $record,
            null,
            GridProviderInterface::class
        );
    }

}
