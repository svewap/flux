<?php
namespace FluidTYPO3\Flux\Integration;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Form;
use FluidTYPO3\Flux\Provider\Interfaces\DataStructureProviderInterface;
use FluidTYPO3\Flux\Provider\Interfaces\FormProviderInterface;
use FluidTYPO3\Flux\Service\FluxService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidCombinedPointerFieldException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidIdentifierException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidParentRowException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidParentRowLoopException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidParentRowRootException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidPointerFieldValueException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidSinglePointerFieldException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidTcaException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

class FlexFormBuilder
{
    protected FluxService $configurationService;

    public function __construct()
    {
        /** @var FluxService $fluxService */
        $fluxService = GeneralUtility::makeInstance(FluxService::class);
        $this->configurationService = $fluxService;
    }

    public function resolveDataStructureIdentifier(
        array $fieldTca,
        string $tableName,
        string $fieldName,
        array $record,
        array $originalIdentifier = []
    ): array {
        $defaultIdentifier = $this->getDefaultIdentifier($fieldTca, $tableName, $fieldName, $record);

        // Select a limited set of the $record being passed. When the $record is a new record, it will have
        // no UID but will contain a list of default values, in which case we extract a smaller list of
        // values based on the "useColumnsForDefaultValues" TCA control (we mimic the amount of data that
        // would be available via the new content wizard). If the record has a UID we record only the UID.
        // In the latter case we sacrifice some performance (having to reload the record by UID) in order
        // to pass an identifier small enough to be part of GET parameters. This class will then "thaw" the
        // record identified by UID to ensure that for all existing records, Providers receive the FULL data.
        if (($originalIdentifier['dataStructureKey'] ?? 'default') !== 'default') {
            return $defaultIdentifier;
        }
        if ((integer) ($record['uid'] ?? 0) > 0) {
            $limitedRecordData = ['uid' => $record['uid']];
        } else {
            $fields = GeneralUtility::trimExplode(
                ',',
                $GLOBALS['TCA'][$tableName]['ctrl']['useColumnsForDefaultValues']
            );
            if ($GLOBALS['TCA'][$tableName]['ctrl']['type'] ?? false) {
                $typeField = $GLOBALS['TCA'][$tableName]['ctrl']['type'];
                $fields[] = $GLOBALS['TCA'][$tableName]['ctrl']['type'];
                if ($GLOBALS['TCA'][$tableName]['ctrl'][$typeField]['subtype_value_field'] ?? false) {
                    $fields[] = $GLOBALS['TCA'][$tableName]['ctrl'][$typeField]['subtype_value_field'];
                }
            }
            $fields = array_combine($fields, $fields);
            $limitedRecordData = array_intersect_key($record, $fields);
            $limitedRecordData[$fieldName] = $record[$fieldName];
        }
        $provider = $this->configurationService->resolvePrimaryConfigurationProvider($tableName, $fieldName, $record);
        if (!$provider) {
            return $defaultIdentifier;
        }
        return [
            'type' => 'flux',
            'tableName' => $tableName,
            'fieldName' => $fieldName,
            'record' => $limitedRecordData
        ];
    }

    public function parseDataStructureByIdentifier(array $identifier): array|string
    {
        try {
            $defaultDataStructure = $this->getDefaultStructureForIdentifier($identifier);
        } catch (InvalidIdentifierException $e) {
        }

        if ($identifier['type'] !== 'flux') {
            return $defaultDataStructure;
        }
        $record = $identifier['record'];
        if (!$record) {
            return $defaultDataStructure;
        }
        /** @var array|null $fromCache */
        $fromCache = $this->configurationService->getFromCaches($identifier);
        if ($fromCache) {
            return $fromCache;
        }
        if (count($record) === 1 && isset($record['uid']) && is_numeric($record['uid'])) {
            // The record is a stub, has only "uid" and "uid" is numeric. Reload the full record from DB.
            $record = $this->loadRecordWithoutRestriction($identifier['tableName'], (integer) $record['uid']);
        }
        if (empty($record)) {
            throw new \UnexpectedValueException('Unable to resolve record for DS processing', 1668011937);
        }

        $fieldName = $identifier['fieldName'];
        $dataStructArray = [];
        $provider = $this->configurationService->resolvePrimaryConfigurationProvider(
            $identifier['tableName'],
            $fieldName,
            $record,
            null,
            [DataStructureProviderInterface::class]
        );
        if (!$provider instanceof FormProviderInterface) {
            // No Providers detected - return empty data structure (reported as invalid DS in backend)
            return $defaultDataStructure;
        }



        $form = $provider->getForm($record, $fieldName);
        $provider->postProcessDataStructure($record, $dataStructArray, $identifier);
        if ($form && $form->getOption(Form::OPTION_STATIC)) {
            // This provider has requested static DS caching; stop attempting
            // to process any other DS, cache and return this DS as final result:
            $this->configurationService->setInCaches($dataStructArray, true, $identifier);
            return $dataStructArray;
        }

        if (empty($dataStructArray)) {
            $dataStructArray = ['ROOT' => ['el' => []]];
        }

        $dataStructArray = $this->patchTceformsWrapper($dataStructArray);



        return $dataStructArray;
    }

    /**
     * Temporary method during FormEngine transition!
     *
     * Performs a duplication in data source, applying a wrapper
     * around field configurations which require it for correct
     * rendering in flex form containers.
     */
    protected function patchTceformsWrapper(array $dataStructure, ?string $parentIndex = null): array
    {
        foreach ($dataStructure as $index => $subStructure) {
            if (is_array($subStructure)) {
                $dataStructure[$index] = $this->patchTceformsWrapper($subStructure, $index);
            }
        }
        if (isset($dataStructure['config']['type']) && $parentIndex !== 'TCEforms') {
            $dataStructure = ['TCEforms' => $dataStructure];
        }
        return $dataStructure;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function loadRecordWithoutRestriction(string $table, int $uid): ?array
    {
        return BackendUtility::getRecord($table, $uid, '*', '', false);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function getCache(): FrontendInterface
    {
        static $cache;
        if (!$cache) {
            /** @var CacheManager $cacheManager */
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            $cache = $cacheManager->getCache('flux');
        }
        return $cache;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function getRuntimeCache(): FrontendInterface
    {
        static $cache;
        if (!$cache) {
            /** @var CacheManager $cacheManager */
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            $cache = $cacheManager->getCache('runtime');
        }
        return $cache;
    }

    protected function getDefaultIdentifier(array $fieldTca, string $tableName, string $fieldName, array $row): array
    {
        $tcaDataStructureArray = $fieldTca['config']['ds'] ?? null;
        $tcaDataStructurePointerField = $fieldTca['config']['ds_pointerField'] ?? null;
        if (!is_array($tcaDataStructureArray) && $tcaDataStructurePointerField) {
            // "ds" is not an array, but "ds_pointerField" is set -> data structure is found in different table
            $dataStructureIdentifier = $this->getDataStructureIdentifierFromRecord(
                $fieldTca,
                $tableName,
                $fieldName,
                $row
            );
        } elseif (is_array($tcaDataStructureArray)) {
            $dataStructureIdentifier = $this->getDataStructureIdentifierFromTcaArray(
                $fieldTca,
                $tableName,
                $fieldName,
                $row
            );
        } else {
            throw new \RuntimeException(
                'TCA misconfiguration in table "' . $tableName . '" field "' . $fieldName . '" config section:'
                . ' The field is configured as type="flex" and no "ds_pointerField" is defined and "ds" is not an array.'
                . ' Either configure a default data structure in [\'ds\'][\'default\'] or add a "ds_pointerField" lookup mechanism'
                . ' that specifies the data structure',
                1463826960
            );
        }

        return $dataStructureIdentifier;
    }


    protected function getDataStructureIdentifierFromRecord(array $fieldTca, string $tableName, string $fieldName, array $row): array
    {
        $pointerFieldName = $finalPointerFieldName = $fieldTca['config']['ds_pointerField'];
        if (!array_key_exists($pointerFieldName, $row)) {
            // Pointer field does not exist in row at all -> throw
            throw new InvalidTcaException(
                'No data structure for field "' . $fieldName . '" in table "' . $tableName . '" found, no "ds" array'
                . ' configured and given row does not have a field with ds_pointerField name "' . $pointerFieldName . '".',
                1464115059
            );
        }
        $pointerValue = $row[$pointerFieldName];
        // If set, this is typically set to "pid"
        $parentFieldName = $fieldTca['config']['ds_pointerField_searchParent'] ?? null;
        $pointerSubFieldName = $fieldTca['config']['ds_pointerField_searchParent_subField'] ?? null;
        if (!$pointerValue && $parentFieldName) {
            // Fetch rootline until a valid pointer value is found
            $handledUids = [];
            while (!$pointerValue) {
                $handledUids[$row['uid']] = 1;
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
                $queryBuilder->getRestrictions()
                    ->removeAll()
                    ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                $queryBuilder->select('uid', $parentFieldName, $pointerFieldName);
                if (!empty($pointerSubFieldName)) {
                    $queryBuilder->addSelect($pointerSubFieldName);
                }
                $queryStatement = $queryBuilder->from($tableName)
                    ->where(
                        $queryBuilder->expr()->eq(
                            'uid',
                            $queryBuilder->createNamedParameter($row[$parentFieldName], Connection::PARAM_INT)
                        )
                    )
                    ->executeQuery();
                $rowCount = $queryBuilder
                    ->count('uid')
                    ->executeQuery()
                    ->fetchOne();
                if ($rowCount !== 1) {
                    throw new InvalidParentRowException(
                        'The data structure for field "' . $fieldName . '" in table "' . $tableName . '" has to be looked up'
                        . ' in field "' . $pointerFieldName . '". That field had no valid value, so a lookup in parent record'
                        . ' with uid "' . $row[$parentFieldName] . '" was done. This row however does not exist or was deleted.',
                        1463833794
                    );
                }
                $row = $queryStatement->fetchAssociative();
                if (isset($handledUids[$row[$parentFieldName]])) {
                    // Row has been fetched before already -> loop detected!
                    throw new InvalidParentRowLoopException(
                        'The data structure for field "' . $fieldName . '" in table "' . $tableName . '" has to be looked up'
                        . ' in field "' . $pointerFieldName . '". That field had no valid value, so a lookup in parent record'
                        . ' with uid "' . $row[$parentFieldName] . '" was done. A loop of records was detected, the tree is broken.',
                        1464110956
                    );
                }
                BackendUtility::workspaceOL($tableName, $row);
                // New pointer value: This is the "subField" value if given, else the field value
                // ds_pointerField_searchParent_subField is the "template on next level" structure from templavoila
                if ($pointerSubFieldName && $row[$pointerSubFieldName]) {
                    $finalPointerFieldName = $pointerSubFieldName;
                    $pointerValue = $row[$pointerSubFieldName];
                } else {
                    $pointerValue = $row[$pointerFieldName];
                }
                if (!$pointerValue && ((int)$row[$parentFieldName] === 0 || $row[$parentFieldName] === null)) {
                    // If on root level and still no valid pointer found -> exception
                    throw new InvalidParentRowRootException(
                        'The data structure for field "' . $fieldName . '" in table "' . $tableName . '" has to be looked up'
                        . ' in field "' . $pointerFieldName . '". That field had no valid value, so a lookup in parent record'
                        . ' with uid "' . $row[$parentFieldName] . '" was done. Root node with uid "' . $row['uid'] . '"'
                        . ' was fetched and still no valid pointer field value was found.',
                        1464112555
                    );
                }
            }
        }
        if (!$pointerValue) {
            // Still no valid pointer value -> exception, This still can be a data integrity issue, so throw a catchable exception
            throw new InvalidPointerFieldValueException(
                'No data structure for field "' . $fieldName . '" in table "' . $tableName . '" found, no "ds" array'
                . ' configured and data structure could be found by resolving parents. This is probably a TCA misconfiguration.',
                1464114011
            );
        }
        // Ok, finally we have the field value. This is now either a data structure directly, or a pointer to a file,
        // or the value can be interpreted as integer (is a uid) and "ds_tableField" is set, so this is the table, uid and field
        // where the final data structure can be found.
        if (MathUtility::canBeInterpretedAsInteger($pointerValue)) {
            if (!isset($fieldTca['config']['ds_tableField'])) {
                throw new InvalidTcaException(
                    'Invalid data structure pointer for field "' . $fieldName . '" in table "' . $tableName . '", the value'
                    . 'resolved to "' . $pointerValue . '" . which is an integer, so "ds_tableField" must be configured',
                    1464115639
                );
            }
            if (substr_count($fieldTca['config']['ds_tableField'], ':') !== 1) {
                // ds_tableField must be of the form "table:field"
                throw new InvalidTcaException(
                    'Invalid TCA configuration for field "' . $fieldName . '" in table "' . $tableName . '", the setting'
                    . '"ds_tableField" must be of the form "tableName:fieldName"',
                    1464116002
                );
            }
            [$foreignTableName, $foreignFieldName] = GeneralUtility::trimExplode(':', $fieldTca['config']['ds_tableField']);
            $dataStructureIdentifier = [
                'type' => 'record',
                'tableName' => $foreignTableName,
                'uid' => (int)$pointerValue,
                'fieldName' => $foreignFieldName,
            ];
        } else {
            $dataStructureIdentifier = [
                'type' => 'record',
                'tableName' => $tableName,
                'uid' => (int)$row['uid'],
                'fieldName' => $finalPointerFieldName,
            ];
        }
        return $dataStructureIdentifier;
    }


    protected function getDataStructureIdentifierFromTcaArray(array $fieldTca, string $tableName, string $fieldName, array $row): array
    {
        $dataStructureIdentifier = [
            'type' => 'tca',
            'tableName' => $tableName,
            'fieldName' => $fieldName,
            'dataStructureKey' => null,
        ];
        $tcaDataStructurePointerField = $fieldTca['config']['ds_pointerField'] ?? null;
        if ($tcaDataStructurePointerField === null) {
            // No ds_pointerField set -> use 'default' as ds array key if exists.
            if (isset($fieldTca['config']['ds']['default'])) {
                $dataStructureIdentifier['dataStructureKey'] = 'default';
            } else {
                // A tca is configured as flex without ds_pointerField. A 'default' key must exist, otherwise
                // this is a configuration error.
                // May happen with an unloaded extension -> catchable
                throw new InvalidTcaException(
                    'TCA misconfiguration in table "' . $tableName . '" field "' . $fieldName . '" config section:'
                    . ' The field is configured as type="flex" and no "ds_pointerField" is defined. Either configure'
                    . ' a default data structure in [\'ds\'][\'default\'] or add a "ds_pointerField" lookup mechanism'
                    . ' that specifies the data structure',
                    1463652560
                );
            }
        } else {
            // ds_pointerField is set, it can be a comma separated list of two fields, explode it.
            $pointerFieldArray = GeneralUtility::trimExplode(',', $tcaDataStructurePointerField, true);
            // Obvious configuration error, either one or two fields must be declared
            $pointerFieldsCount = count($pointerFieldArray);
            if ($pointerFieldsCount !== 1 && $pointerFieldsCount !== 2) {
                // If it's there, it must be correct -> not catchable
                throw new \RuntimeException(
                    'TCA misconfiguration in table "' . $tableName . '" field "' . $fieldName . '" config section:'
                    . ' ds_pointerField must be either a single field name, or a comma separated list of two fields,'
                    . ' the invalid configuration string provided was: "' . $tcaDataStructurePointerField . '"',
                    1463577497
                );
            }
            // Verify first field exists in row array. If not, this is a hard error: Any extension that sets a
            // ds_pointerField to some field name should take care that field does exist, too. They are a pair,
            // so there shouldn't be a situation where the field does not exist. Throw an exception if that is violated.
            if (!isset($row[$pointerFieldArray[0]])) {
                // If it's declared, it must exist -> not catchable
                throw new \RuntimeException(
                    'TCA misconfiguration in table "' . $tableName . '" field "' . $fieldName . '" config section:'
                    . ' ds_pointerField "' . $pointerFieldArray[0] . '" points to a field name that does not exist.',
                    1463578899
                );
            }
            // Similar situation for the second field: If it is set, the field must exist.
            if (isset($pointerFieldArray[1]) && !isset($row[$pointerFieldArray[1]])) {
                // If it's declared, it must exist -> not catchable
                throw new \RuntimeException(
                    'TCA misconfiguration in table "' . $tableName . '" field "' . $fieldName . '" config section:'
                    . ' Second part "' . $pointerFieldArray[1] . '" of ds_pointerField with full value "'
                    . $tcaDataStructurePointerField . '" points to a field name that does not exist.',
                    1463578900
                );
            }
            if ($pointerFieldsCount === 1) {
                if (isset($fieldTca['config']['ds'][$row[$pointerFieldArray[0]]])) {
                    // Field value points directly to an existing key in tca ds
                    $dataStructureIdentifier['dataStructureKey'] = $row[$pointerFieldArray[0]];
                } elseif (isset($fieldTca['config']['ds']['default'])) {
                    // Field value does not exit in tca ds, fall back to default key if exists
                    $dataStructureIdentifier['dataStructureKey'] = 'default';
                } else {
                    // The value of the ds_pointerField field points to a key in the ds array that does
                    // not exist, and there is no fallback either. This can happen if an extension brings
                    // new flex form definitions and that extension is unloaded later. "Old" records of the
                    // extension could then still point to the no longer existing key in ds. We throw a
                    // specific exception here to give controllers an opportunity to catch this case.
                    throw new InvalidSinglePointerFieldException(
                        'Field value of field "' . $pointerFieldArray[0] . '" of database record with uid "'
                        . $row['uid'] . '" from table "' . $tableName . '" points to a "ds" key ' . $row[$pointerFieldArray[0]]
                        . ' but this key does not exist and there is no "default" fallback.',
                        1463653197
                    );
                }
            } else {
                // Two comma separated field names
                if (isset($fieldTca['config']['ds'][$row[$pointerFieldArray[0]] . ',' . $row[$pointerFieldArray[1]]])) {
                    // firstValue,secondValue
                    $dataStructureIdentifier['dataStructureKey'] = $row[$pointerFieldArray[0]] . ',' . $row[$pointerFieldArray[1]];
                } elseif (isset($fieldTca['config']['ds'][$row[$pointerFieldArray[0]] . ',*'])) {
                    // firstValue,*
                    $dataStructureIdentifier['dataStructureKey'] = $row[$pointerFieldArray[0]] . ',*';
                } elseif (isset($fieldTca['config']['ds']['*,' . $row[$pointerFieldArray[1]]])) {
                    // *,secondValue
                    $dataStructureIdentifier['dataStructureKey'] = '*,' . $row[$pointerFieldArray[1]];
                } elseif (isset($fieldTca['config']['ds'][$row[$pointerFieldArray[0]]])) {
                    // firstValue
                    $dataStructureIdentifier['dataStructureKey'] = $row[$pointerFieldArray[0]];
                } elseif (isset($fieldTca['config']['ds']['default'])) {
                    // Fall back to default
                    $dataStructureIdentifier['dataStructureKey'] = 'default';
                } else {
                    // No ds_pointerField value could be determined and 'default' does not exist as
                    // fallback. This is the same case as the above scenario, throw a
                    // InvalidCombinedPointerFieldException here, too.
                    throw new InvalidCombinedPointerFieldException(
                        'Field combination of fields "' . $pointerFieldArray[0] . '" and "' . $pointerFieldArray[1] . '" of database'
                        . 'record with uid "' . $row['uid'] . '" from table "' . $tableName . '" with values "' . $row[$pointerFieldArray[0]] . '"'
                        . ' and "' . $row[$pointerFieldArray[1]] . '" could not be resolved to any registered data structure and '
                        . ' no "default" fallback exists.',
                        1463678524
                    );
                }
            }
        }
        return $dataStructureIdentifier;
    }

    protected function getDefaultStructureForIdentifier(array $identifier): string
    {
        if (($identifier['type'] ?? '') === 'record') {
            // Handle "record" type, see getDataStructureIdentifierFromRecord()
            if (empty($identifier['tableName']) || empty($identifier['uid']) || empty($identifier['fieldName'])) {
                throw new \RuntimeException(
                    'Incomplete "record" based identifier: ' . json_encode($identifier),
                    1478113873
                );
            }
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($identifier['tableName']);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $dataStructure = $queryBuilder
                ->select($identifier['fieldName'])
                ->from($identifier['tableName'])
                ->where(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter($identifier['uid'], Connection::PARAM_INT)
                    )
                )
                ->executeQuery()
                ->fetchOne();
        } elseif (($identifier['type'] ?? '') === 'tca') {
            // Handle "tca" type, see getDataStructureIdentifierFromTcaArray
            if (empty($identifier['tableName']) || empty($identifier['fieldName']) || empty($identifier['dataStructureKey'])) {
                throw new \RuntimeException(
                    'Incomplete "tca" based identifier: ' . json_encode($identifier),
                    1478113471
                );
            }
            $table = $identifier['tableName'];
            $field = $identifier['fieldName'];
            $dataStructureKey = $identifier['dataStructureKey'];
            if (!isset($GLOBALS['TCA'][$table]['columns'][$field]['config']['ds'][$dataStructureKey])
                || !is_string($GLOBALS['TCA'][$table]['columns'][$field]['config']['ds'][$dataStructureKey])
            ) {
                // This may happen for elements pointing to an unloaded extension -> catchable
                throw new InvalidIdentifierException(
                    'Specified identifier ' . json_encode($identifier) . ' does not resolve to a valid'
                    . ' TCA array value',
                    1478105491
                );
            }
            $dataStructure = $GLOBALS['TCA'][$table]['columns'][$field]['config']['ds'][$dataStructureKey];
        } else {
            throw new InvalidIdentifierException(
                'Identifier ' . json_encode($identifier) . ' could not be resolved',
                1478104554
            );
        }
        return $dataStructure;
    }


}
