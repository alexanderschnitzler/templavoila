<?php

/*
 * This file is part of the TemplaVoilà project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

namespace Schnitzler\Templavoila\Domain\Repository;

use Schnitzler\Templavoila\Domain\Model\DataStructure;
use Schnitzler\Templavoila\Templavoila;
use Schnitzler\Templavoila\Traits\DatabaseConnection;
use Schnitzler\Templavoila\Traits\DataHandler;
use Schnitzler\Templavoila\Utility\StaticDataStructure\ToolsUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class to provide unique access to datastructure
 *
 *
 */
class DataStructureRepository
{
    use DatabaseConnection;
    use DataHandler;

    /**
     * @var bool
     */
    protected static $staticDsInitComplete = false;

    /**
     * Retrieve a single datastructure by uid or xml-file path
     *
     * @param int $uidOrFile
     *
     * @throws \InvalidArgumentException
     *
     * @return \Schnitzler\Templavoila\Domain\Model\AbstractDataStructure
     */
    public function getDatastructureByUidOrFilename($uidOrFile)
    {
        if ((int)$uidOrFile > 0) {
            $className = 'Schnitzler\\Templavoila\\Domain\\Model\\DataStructure';
        } else {
            if (($staticKey = $this->validateStaticDS((string)$uidOrFile)) !== false) {
                $uidOrFile = $staticKey;
                $className = 'Schnitzler\\Templavoila\\Domain\\Model\\StaticDataStructure';
            } else {
                throw new \InvalidArgumentException(
                    'Argument was supposed to be either a uid or a filename',
                    1273409810
                );
            }
        }

        return GeneralUtility::makeInstance($className, $uidOrFile);
    }

    /**
     * Retrieve a collection (array) of tx_templavoila_datastructure objects
     *
     * @param int $pid
     *
     * @return array
     */
    public function getDatastructuresByStoragePid($pid)
    {
        $dscollection = [];
        $confArr = self::getStaticDatastructureConfiguration();
        if (count($confArr)) {
            foreach ($confArr as $conf) {
                $ds = $this->getDatastructureByUidOrFilename($conf['path']);
                $pids = $ds->getStoragePids();
                if ($pids === '' || GeneralUtility::inList($pids, (string)$pid)) {
                    $dscollection[] = $ds;
                }
            }
        }

        if (!self::isStaticDsEnabled()) {
            $dsRows = (array)static::getDatabaseConnection()->exec_SELECTgetRows(
                'uid',
                'tx_templavoila_datastructure',
                'pid=' . (int)$pid
                . BackendUtility::deleteClause('tx_templavoila_datastructure')
                . ' AND pid!=-1 '
                . BackendUtility::versioningPlaceholderClause('tx_templavoila_datastructure')
            );
            foreach ($dsRows as $ds) {
                $dscollection[] = $this->getDatastructureByUidOrFilename($ds['uid']);
            }
        }
        usort($dscollection, [$this, 'sortDatastructures']);

        return $dscollection;
    }

    /**
     * Retrieve a collection (array) of tx_templavoila_datastructure objects
     *
     * @param int $pid
     * @param int $scope
     *
     * @return array
     */
    public function getDatastructuresByStoragePidAndScope($pid, $scope)
    {
        $dscollection = [];
        $confArr = self::getStaticDatastructureConfiguration();
        if (count($confArr)) {
            foreach ($confArr as $conf) {
                if ($conf['scope'] == $scope) {
                    $ds = $this->getDatastructureByUidOrFilename($conf['path']);
                    $pids = $ds->getStoragePids();
                    if ($pids === '' || GeneralUtility::inList($pids, (string)$pid)) {
                        $dscollection[] = $ds;
                    }
                }
            }
        }

        if (!self::isStaticDsEnabled()) {
            $dsRows = (array)static::getDatabaseConnection()->exec_SELECTgetRows(
                'uid',
                'tx_templavoila_datastructure',
                'scope=' . (int)$scope . ' AND pid=' . (int)$pid
                . BackendUtility::deleteClause('tx_templavoila_datastructure')
                . ' AND pid!=-1 '
                . BackendUtility::versioningPlaceholderClause('tx_templavoila_datastructure')
            );
            foreach ($dsRows as $ds) {
                $dscollection[] = $this->getDatastructureByUidOrFilename($ds['uid']);
            }
        }
        usort($dscollection, [$this, 'sortDatastructures']);

        return $dscollection;
    }

    /**
     * Retrieve a collection (array) of tx_templavoila_datastructure objects
     *
     * @param int $scope
     *
     * @return array
     */
    public function findByScope($scope)
    {
        $dscollection = [];
        $confArr = self::getStaticDatastructureConfiguration();
        if (count($confArr)) {
            foreach ($confArr as $conf) {
                if ($conf['scope'] == $scope) {
                    $ds = $this->getDatastructureByUidOrFilename($conf['path']);
                    $dscollection[] = $ds;
                }
            }
        }

        if (!self::isStaticDsEnabled()) {
            $dsRows = (array)static::getDatabaseConnection()->exec_SELECTgetRows(
                'uid',
                'tx_templavoila_datastructure',
                'scope=' . (int)$scope
                . BackendUtility::deleteClause('tx_templavoila_datastructure')
                . ' AND pid!=-1 '
                . BackendUtility::versioningPlaceholderClause('tx_templavoila_datastructure')
            );
            foreach ($dsRows as $ds) {
                $dscollection[] = $this->getDatastructureByUidOrFilename($ds['uid']);
            }
        }
        usort($dscollection, [$this, 'sortDatastructures']);

        return $dscollection;
    }

    /**
     * Retrieve a collection (array) of tx_templavoila_datastructure objects
     *
     * @param int $scope
     *
     * @return array
     */
    public function getDatastructuresByScope($scope)
    {
        $dscollection = [];
        $confArr = self::getStaticDatastructureConfiguration();
        if (count($confArr)) {
            foreach ($confArr as $conf) {
                if ($conf['scope'] == $scope) {
                    $ds = $this->getDatastructureByUidOrFilename($conf['path']);
                    $dscollection[] = $ds;
                }
            }
        }

        if (!self::isStaticDsEnabled()) {
            $dsRows = (array)static::getDatabaseConnection()->exec_SELECTgetRows(
                'uid',
                'tx_templavoila_datastructure',
                'scope=' . (int)$scope
                . BackendUtility::deleteClause('tx_templavoila_datastructure')
                . ' AND pid!=-1 '
                . BackendUtility::versioningPlaceholderClause('tx_templavoila_datastructure')
            );
            foreach ($dsRows as $ds) {
                $dscollection[] = $this->getDatastructureByUidOrFilename($ds['uid']);
            }
        }
        usort($dscollection, [$this, 'sortDatastructures']);

        return $dscollection;
    }

    /**
     * Retrieve a collection (array) of tx_templavoila_datastructure objects
     *
     * @return array
     */
    public function getAll()
    {
        $dscollection = [];
        $confArr = self::getStaticDatastructureConfiguration();
        if (count($confArr)) {
            foreach ($confArr as $conf) {
                $ds = $this->getDatastructureByUidOrFilename($conf['path']);
                $dscollection[] = $ds;
            }
        }

        if (!self::isStaticDsEnabled()) {
            $dsRows = (array)static::getDatabaseConnection()->exec_SELECTgetRows(
                'uid',
                'tx_templavoila_datastructure',
                '1=1'
                . BackendUtility::deleteClause('tx_templavoila_datastructure')
                . ' AND pid!=-1 '
                . BackendUtility::versioningPlaceholderClause('tx_templavoila_datastructure')
            );
            foreach ($dsRows as $ds) {
                $dscollection[] = $this->getDatastructureByUidOrFilename($ds['uid']);
            }
        }
        usort($dscollection, [$this, 'sortDatastructures']);

        return $dscollection;
    }

    /**
     * @param string $file
     *
     * @return mixed
     */
    protected function validateStaticDS($file)
    {
        $confArr = self::getStaticDatastructureConfiguration();
        $confKey = false;
        if (count($confArr)) {
            $fileAbsName = GeneralUtility::getFileAbsFileName($file);
            foreach ($confArr as $key => $conf) {
                if (GeneralUtility::getFileAbsFileName($conf['path']) === $fileAbsName) {
                    $confKey = $key;
                    break;
                }
            }
        }

        return $confKey;
    }

    /**
     * @return bool
     */
    protected static function isStaticDsEnabled()
    {
        $extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][Templavoila::EXTKEY]);

        return $extConf['staticDS.']['enable'];
    }

    /**
     * @return array
     */
    public static function getStaticDatastructureConfiguration()
    {
        $config = [];
        if (!self::$staticDsInitComplete) {
            $extConfig = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][Templavoila::EXTKEY]);
            if ($extConfig['staticDS.']['enable']) {
                ToolsUtility::readStaticDsFilesIntoArray($extConfig);
            }
            self::$staticDsInitComplete = true;
        }
        if (is_array($GLOBALS['TBE_MODULES_EXT']['xMOD_tx_templavoila_cm1']['staticDataStructures'])) {
            $config = $GLOBALS['TBE_MODULES_EXT']['xMOD_tx_templavoila_cm1']['staticDataStructures'];
        }

        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Templavoila::EXTKEY]['staticDataStructures'])) {
            $config = array_merge($config, $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Templavoila::EXTKEY]['staticDataStructures']);
        }

        $finalConfig = [];
        foreach ($config as $cfg) {
            $key = md5($cfg['path'] . $cfg['title'] . $cfg['scope']);
            $finalConfig[$key] = $cfg;
        }

        return array_values($finalConfig);
    }

    /**
     * Sorts datastructure alphabetically
     *
     * @param \Schnitzler\Templavoila\Domain\Model\AbstractDataStructure $obj1
     * @param \Schnitzler\Templavoila\Domain\Model\AbstractDataStructure $obj2
     *
     * @return int Result of the comparison (see strcmp())
     *
     * @see usort()
     * @see strcmp()
     */
    public function sortDatastructures($obj1, $obj2)
    {
        return strcmp(strtolower($obj1->getSortingFieldValue()), strtolower($obj2->getSortingFieldValue()));
    }

    /**
     * @param int $pid
     *
     * @return int
     */
    public function getDatastructureCountForPid($pid)
    {
        return (int)static::getDatabaseConnection()->exec_SELECTcountRows(
            '*',
            'tx_templavoila_tmplobj',
            'pid=' . (int)$pid . BackendUtility::deleteClause('tx_templavoila_tmplobj')
        );
    }

    /**
     * @param int $pid
     *
     * @return int
     */
    public function countByPid($pid)
    {
        return $this->getDatastructureCountForPid($pid);
    }

    /**
     * @param int $uid
     * @param array $updates
     */
    public function update($uid, array $updates)
    {
        $data = [];
        $data[DataStructure::TABLE][$uid] = $updates;

        $dataHandler = static::getDataHandler();
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
    }

    /**
     * @param array $inserts
     * @return int
     */
    public function create(array $inserts)
    {
        $data = [];
        $data[DataStructure::TABLE]['NEW'] = $inserts;

        $dataHandler = static::getDataHandler();
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        return (int)$dataHandler->substNEWwithIDs['NEW'];
    }
}
