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

namespace Schnitzler\Templavoila\Controller;

use Schnitzler\Templavoila\Domain\Model\HtmlMarkup;
use Schnitzler\Templavoila\Domain\Repository\DataStructureRepository;
use Schnitzler\Templavoila\Domain\Repository\TemplateRepository;
use Schnitzler\Templavoila\Exception\Data\ObjectNotFoundException;
use Schnitzler\Templavoila\Exception\RuntimeException;
use Schnitzler\Templavoila\Exception\Runtime\SerializationException;
use Schnitzler\Templavoila\Templavoila;
use Schnitzler\Templavoila\Traits\BackendUser;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageGenerator;
use TYPO3\CMS\Frontend\Page\PageRepository;
use TYPO3\CMS\Frontend\Plugin\AbstractPlugin;

/**
 * Plugin 'Flexible Content' for the 'templavoila' extension.
 *
 *
 *
 */
class FrontendController extends AbstractPlugin
{
    use BackendUser;

    /**
     * If set, children-translations will take the value from the default if "false" (zero or blank)
     *
     * @var int
     */
    public $inheritValueFromDefault = 1;

    /**
     * @var bool
     */
    public static $enablePageRenderer = true;

    /**
     * Markup object
     *
     * @var HtmlMarkup
     */
    public $htmlMarkup;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @param DatabaseConnection $databaseConnection
     * @param TypoScriptFrontendController $frontendController
     */
    public function __construct(DatabaseConnection $databaseConnection = null, TypoScriptFrontendController $frontendController = null)
    {
        parent::__construct($databaseConnection, $frontendController);

        $this->prefixId = 'tx_templavoila_pi1';
        $this->scriptRelPath = 'pi1/class.tx_templavoila_pi1.php';

        /** @var LogManager $logManager */
        $logManager = GeneralUtility::makeInstance(LogManager::class);
        $this->logger = $logManager->getLogger(__CLASS__);
    }

    /**
     * Will set up various stuff in the class based on input TypoScript
     *
     * @param array $conf TypoScript options
     */
    public function initVars(array $conf)
    {
        $this->inheritValueFromDefault = $conf['dontInheritValueFromDefault'] ? 0 : 1;
        // naming choosen to fit the regular TYPO3 integrators needs ;)
        self::$enablePageRenderer = isset($conf['advancedHeaderInclusion']) ? $conf['advancedHeaderInclusion'] : self::$enablePageRenderer;
        $this->conf = $conf;
    }

    /**
     * Main function for rendering of Flexible Content elements of TemplaVoila
     *
     * @param string $content Standard content input. Ignore.
     * @param array $conf TypoScript array for the plugin.
     *
     * @return string HTML content for the Flexible Content elements.
     */
    public function main($content, array $conf)
    {
        $this->initVars($conf);

        return $this->renderElement($this->cObj->data, 'tt_content');
    }

    /**
     * Main function for rendering records from system tables (like fe_users) using TemplaVoila. Function creates fake flexform, ds and to fields for the record and calls {@link #renderElement($row,$table) renderElement} for processing.
     *
     * <strong>This is still undocumented and unsupported! Do not use unless you are ready to risk!</strong>.
     *
     * Example TS for listing FE users:
     * <code><pre>
     * lib.members = CONTENT
     * lib.members {
     *    select {
     * pidInList = {$styles.content.loginform.pid}
     * orderBy = tx_lglalv_mysorting,uid
     *    }
     *    table = fe_users
     *    renderObj = USER
     *    renderObj {
     * userFunc = tx_templavoila_pi1->renderRecord
     * ds = 2
     * to = 4
     * table = fe_users
     *    }
     * }
     * </pre/></code>
     * This example lists all frontend users using DS with DS=2 and TO=4.
     *
     * Required configuration options (in <code>$conf</code>):
     * <ul>
     *    <li><code>ds</code> - DS UID to use
     *    <li><code>to</code> - TO UID to use
     *    <li><code>table</code> - table of the record
     * </ul>
     *
     * @param string $content Unused
     * @param array $conf Configuration (see above for entries)
     *
     * @return string Generated content
     *
     * @todo Create a new content element with this functionality and DS/TO selector?
     * @todo Create TS element with this functionality?
     * @todo Support sheet selector?
     */
    public function renderRecord($content, array $conf)
    {
        $this->initVars($conf);

        // Make a copy of the data, do not spoil original!
        $data = $this->cObj->data;

        // setup ds/to
        $data['tx_templavoila_ds'] = $conf['ds'];
        $data['tx_templavoila_to'] = $conf['to'];

        /** @var DataStructureRepository $dsRepo */
        $dsRepo = GeneralUtility::makeInstance(DataStructureRepository::class);

        // prepare fake flexform
        $values = [];
        foreach ($data as $k => $v) {
            // Make correct language identifiers here!
            if ($this->frontendController->sys_language_isocode) {
                try {
                    $dsObj = $dsRepo->getDatastructureByUidOrFilename($data['tx_templavoila_ds']);
                    $DS = $dsObj->getDataprotArray();
                } catch (\InvalidArgumentException $e) {
                    $DS = null;
                }
                if (is_array($DS)) {
                    $langChildren = $DS['meta']['langChildren'] ? 1 : 0;
                    $langDisabled = $DS['meta']['langDisable'] ? 1 : 0;
                    $lKey = $this->resolveLanguageKey((bool)$langDisabled, (bool)$langChildren);
                    $vKey = (!$langDisabled && $langChildren) ? 'v' . $this->frontendController->sys_language_isocode : 'vDEF';
                } else {
                    return $this->formatError('
                        Couldn\'t find a Data Structure set with uid/file=' . $conf['ds'] . '
                        Please put correct DS and TO into your TS setup first.');
                }
            } else {
                $lKey = 'lDEF';
                $vKey = 'vDEF';
            }
            $values['data']['sDEF'][$lKey][$k][$vKey] = $v;
        }
        $ff = GeneralUtility::makeInstance(FlexFormTools::class);
        $data['tx_templavoila_flex'] = $ff->flexArray2Xml($values);

        return $this->renderElement($data, $conf['table']);
    }

    /**
     * Main function for rendering of Page Templates of TemplaVoila
     *
     * @param string $content Standard content input. Ignore.
     * @param array $conf TypoScript array for the plugin.
     *
     * @return string HTML content for the Page Template elements.
     */
    public function renderPage($content, array $conf)
    {
        $this->initVars($conf);

        // Current page record which we MIGHT manipulate a little:
        $pageRecord = $this->frontendController->page;

        // Find DS and Template in root line IF there is no Data Structure set for the current page:
        if (!$pageRecord['tx_templavoila_ds']) {
            foreach ($this->frontendController->tmpl->rootLine as $pRec) {
                if ($pageRecord['uid'] !== $pRec['uid']) {
                    if ($pRec['tx_templavoila_next_ds']) { // If there is a next-level DS:
                        $pageRecord['tx_templavoila_ds'] = $pRec['tx_templavoila_next_ds'];
                        $pageRecord['tx_templavoila_to'] = $pRec['tx_templavoila_next_to'];
                    } elseif ($pRec['tx_templavoila_ds']) { // Otherwise try the NORMAL DS:
                        $pageRecord['tx_templavoila_ds'] = $pRec['tx_templavoila_ds'];
                        $pageRecord['tx_templavoila_to'] = $pRec['tx_templavoila_to'];
                    }
                } else {
                    break;
                }
            }
        }

        // "Show content from this page instead" support. Note: using current DS/TO!
        if ($pageRecord['content_from_pid']) {
            $ds = $pageRecord['tx_templavoila_ds'];
            $to = $pageRecord['tx_templavoila_to'];
            $pageRecord = $this->frontendController->sys_page->getPage($pageRecord['content_from_pid']);
            $pageRecord['tx_templavoila_ds'] = $ds;
            $pageRecord['tx_templavoila_to'] = $to;
        }

        return $this->renderElement($pageRecord, 'pages');
    }

    /**
     * Render section index for TV
     *
     * @param string $content
     * @param array $conf config of tt_content.menu.20.3
     *
     * @return string rendered section index
     */
    public function renderSectionIndex($content, array $conf)
    {
        $ceField = $this->cObj->stdWrap($conf['indexField'], $conf['indexField.']);
        $pids = isset($conf['select.']['pidInList.'])
            ? trim($this->cObj->stdWrap($conf['select.']['pidInList'], $conf['select.']['pidInList.']))
            : trim($conf['select.']['pidInList']);
        $contentIds = [];
        if ($pids) {
            $pageIds = GeneralUtility::trimExplode(',', $pids);
            foreach ($pageIds as $pageId) {
                $page = $this->frontendController->sys_page->checkRecord('pages', $pageId);
                /** @var array $page */
                if (is_array($page) && isset($page['tx_templavoila_flex'])) {
                    $flex = [];
                    $this->cObj->readFlexformIntoConf($page['tx_templavoila_flex'], $flex);
                    $contentIds = array_merge($contentIds, GeneralUtility::trimExplode(',', $flex[$ceField]));
                }
            }
        } else {
            $flex = [];
            $this->cObj->readFlexformIntoConf($this->frontendController->page['tx_templavoila_flex'], $flex);
            $contentIds = array_merge($contentIds, GeneralUtility::trimExplode(',', $flex[$ceField]));
        }

        if (count($contentIds) > 0) {
            $conf['source'] = implode(',', $contentIds);
            $conf['tables'] = 'tt_content';
            $conf['conf.'] = [
                'tt_content' => $conf['renderObj'],
                'tt_content.' => $conf['renderObj.'],
            ];
            $conf['dontCheckPid'] = 1;
            unset($conf['renderObj']);
            unset($conf['renderObj.']);
        }

        // tiny trink to include the section index element itself too
        $this->frontendController->recordRegister[$this->frontendController->currentRecord] = -1;
        $renderedIndex = $this->cObj->cObjGetSingle('RECORDS', $conf);

        $wrap = isset($conf['wrap.'])
            ? $this->cObj->stdWrap($conf['wrap'], $conf['wrap.'])
            : $conf['wrap'];
        if ($wrap) {
            $renderedIndex = $this->cObj->wrap($renderedIndex, $wrap);
        }

        return $renderedIndex;
    }

    /**
     * Common function for rendering of the Flexible Content / Page Templates.
     * For Page Templates the input row may be manipulated to contain the proper reference to a data structure (pages can have those inherited which content elements cannot).
     *
     * @param array $row Current data record, either a tt_content element or page record.
     * @param string $table Table name, either "pages" or "tt_content".
     *
     * @return string HTML output.
     */
    protected function renderElement(array $row, $table)
    {
        // First prepare user defined objects (if any) for hooks which extend this function:
        $hooks = [];
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Templavoila::EXTKEY]['pi1']['renderElementClass'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Templavoila::EXTKEY]['pi1']['renderElementClass'] as $classRef) {
                $hooks[] = & GeneralUtility::getUserObj($classRef);
            }
        }

        // Hook: renderElement_preProcessRow
        foreach ($hooks as $hook) {
            if (is_callable([$hook, 'renderElement_preProcessRow'])) {
                try {
                    $hook->renderElement_preProcessRow($row, $table, $this);
                } catch (\Exception $e) {
                    $this->getLogger()->error(
                        sprintf('Caught exception during processing hook "%s::renderElement_preProcessRow"', get_class($hook)),
                        [
                            'message' => $e->getMessage(),
                            'code' => $e->getCode()
                        ]
                    );
                }
            }
        }

        try {
            $dataStructureRepository = GeneralUtility::makeInstance(DataStructureRepository::class);
            try {
                /** @var \Schnitzler\Templavoila\Domain\Model\DataStructure $dsObj */
                $dsObj = $dataStructureRepository->getDatastructureByUidOrFilename($row['tx_templavoila_ds']);
                $dataStructure = $dsObj->getDataprotArray();
            } catch (\InvalidArgumentException $e) {
                $dataStructure = null;
            }

            if (!is_array($dataStructure)) {
                throw new ObjectNotFoundException('
                    Couldn\'t find a Data Structure set for table/row "' . $table . ':' . $row['uid'] . '".
                    Please select a Data Structure and Template Object first.',
                    1480960877
                );
            }

            // Sheet Selector:
            if ($dataStructure['meta']['sheetSelector']) {
                // <meta><sheetSelector> could be something like "EXT:user_extension/class.user_extension_selectsheet.php:&amp;user_extension_selectsheet"
                $sheetSelector = & GeneralUtility::getUserObj($dataStructure['meta']['sheetSelector']);
                $renderSheet = $sheetSelector->selectSheet();
            } else {
                $renderSheet = 'sDEF';
            }

            // Initialize:
            $langChildren = $dataStructure['meta']['langChildren'] ? 1 : 0;
            $langDisabled = $dataStructure['meta']['langDisable'] ? 1 : 0;
            list($dataStructureSheet, $sheet, $singleSheet) = GeneralUtility::resolveSheetDefInDS($dataStructure, $renderSheet);

            // Data from FlexForm field:
            $data = GeneralUtility::xml2array($row['tx_templavoila_flex']);

            $lKey = $this->resolveLanguageKey((bool)$langDisabled, (bool)$langChildren);

            /* Hook to modify language key - e.g. used for EXT:languagevisibility */

            foreach ($hooks as $hook) {
                if (is_callable([$hook, 'renderElement_preProcessLanguageKey'])) {
                    try {
                        $lKey = $hook->renderElement_preProcessLanguageKey($row, $table, $lKey, $langDisabled, $langChildren, $this);
                    } catch (\Exception $e) {
                        $this->getLogger()->error(
                            sprintf('Caught exception during processing hook "%s::renderElement_preProcessLanguageKey"', get_class($hook)),
                            [
                                'message' => $e->getMessage(),
                                'code' => $e->getCode()
                            ]
                        );
                    }
                }
            }

            $dataValues = [];
            if (is_array($data) && isset($data['data'][$sheet][$lKey]) && is_array($data['data'][$sheet][$lKey])) {
                $dataValues = $data['data'][$sheet][$lKey];
            }

            // Init mark up object.
            $this->htmlMarkup = GeneralUtility::makeInstance(HtmlMarkup::class);

            // Get template record:
            if (!$row['tx_templavoila_to']) {
                throw new RuntimeException('
                    You haven\'t selected a Template Object yet for table/uid "' . $table . '/' . $row['uid'] . '".
                    Without a Template Object TemplaVoila cannot map the XML content into HTML.
                    Please select a Template Object now.',
                    1480961034
                );
            }

            // Initialize rendering type:
            $renderType = GeneralUtility::_GP('print') ? 'print' : '';
            if ($this->conf['childTemplate']) {
                $renderType = $this->conf['childTemplate'];
                if (strpos($renderSheet, 'USERFUNC:') === 0) {
                    $conf = [
                        'conf' => is_array($this->conf['childTemplate.']) ? $this->conf['childTemplate.'] : [],
                        'toRecord' => $row
                    ];
                    $renderType = GeneralUtility::callUserFunction(substr($renderType, 9), $conf, $this);
                }
            }

            // Get Template Object record:
            $TOrec = $this->getTemplateRecord($row['tx_templavoila_to'], $renderType, $this->frontendController->sys_language_uid);
            if (!is_array($TOrec)) {
                throw new ObjectNotFoundException('
                    Couldn\'t find Template Object with UID "' . $row['tx_templavoila_to'] . '".
                    Please make sure a Template Object is accessible.',
                    1480961100
                );
            }

            // Get mapping information from Template Record:
            $TO = unserialize($TOrec['templatemapping']);
            if (!is_array($TO)) {
                throw new SerializationException('
                    Template Object could not be unserialized successfully.
                    Are you sure you saved mapping information into Template Object with UID "' . $row['tx_templavoila_to'] . '"?'
                );
            }

            // Get local processing:
            $TOproc = [];
            if ($TOrec['localprocessing']) {
                $TOproc = GeneralUtility::xml2array($TOrec['localprocessing']);
                if (!is_array($TOproc)) {
                    // Must be a error!
                    // TODO log to TT the content of $TOproc (it is a error message now)
                    $TOproc = [];
                }
            }
            // Processing the data array:
            if ($GLOBALS['TT']->LR) {
                $GLOBALS['TT']->push('Processing data');
            }
            $vKey = ($this->frontendController->sys_language_isocode && !$langDisabled && $langChildren) ? 'v' . $this->frontendController->sys_language_isocode : 'vDEF';

            /* Hook to modify value key - e.g. used for EXT:languagevisibility */
            foreach ($hooks as $hook) {
                if (is_callable([$hook, 'renderElement_preProcessValueKey'])) {
                    try {
                        $vKey = $hook->renderElement_preProcessValueKey($row, $table, $vKey, $langDisabled, $langChildren, $this);
                    } catch (\Exception $e) {
                        $this->getLogger()->error(
                            sprintf('Caught exception during processing hook "%s::renderElement_preProcessValueKey"', get_class($hook)),
                            [
                                'message' => $e->getMessage(),
                                'code' => $e->getCode()
                            ]
                        );
                    }
                }
            }

            $TOlocalProc = $singleSheet ? $TOproc['ROOT']['el'] : $TOproc['sheets'][$sheet]['ROOT']['el'];
            // Store the original data values before the get processed.
            $originalDataValues = $dataValues;
            $this->processDataValues($dataValues, $dataStructureSheet['ROOT']['el'], $TOlocalProc, $vKey, ($this->conf['renderUnmapped'] !== 'false' ? true : $TO['MappingInfo']['ROOT']['el']));

            // Hook: renderElement_postProcessDataValues
            $flexformData = [
                'table' => $table,
                'row' => $row,
                'sheet' => $renderSheet,
                'sLang' => $lKey,
                'vLang' => $vKey
            ];
            foreach ($hooks as $hook) {
                if (is_callable([$hook, 'renderElement_postProcessDataValues'])) {
                    try {
                        $hook->renderElement_postProcessDataValues($dataStructure, $dataValues, $originalDataValues, $flexformData);
                    } catch (\Exception $e) {
                        $this->getLogger()->error(
                            sprintf('Caught exception during processing hook "%s::renderElement_postProcessDataValues"', get_class($hook)),
                            [
                                'message' => $e->getMessage(),
                                'code' => $e->getCode()
                            ]
                        );
                    }
                }
            }

            if ($GLOBALS['TT']->LR) {
                $GLOBALS['TT']->pull();
            }

            // Merge the processed data into the cached template structure:
            if ($GLOBALS['TT']->LR) {
                $GLOBALS['TT']->push('Merge data and TO');
            }
            // Getting the cached mapping data out (if sheets, then default to "sDEF" if no mapping exists for the specified sheet!)
            $mappingDataBody = $singleSheet ? $TO['MappingData_cached'] : (is_array($TO['MappingData_cached']['sub'][$sheet]) ? $TO['MappingData_cached']['sub'][$sheet] : $TO['MappingData_cached']['sub']['sDEF']);
            $content = $this->htmlMarkup->mergeFormDataIntoTemplateStructure($dataValues, $mappingDataBody, '', $vKey);

            $this->setHeaderBodyParts($TO['MappingInfo_head'], $TO['MappingData_head_cached'], $TO['BodyTag_cached'], self::$enablePageRenderer);

            if ($GLOBALS['TT']->LR) {
                $GLOBALS['TT']->pull();
            }

            // Edit icon (frontend editing):
            $eIconf = ['styleAttribute' => 'position:absolute;'];
            if ($table === 'pages') {
                $eIconf['beforeLastTag'] = -1;
            } // For "pages", set icon in top, not after.
            $content = $this->pi_getEditIcon($content, 'tx_templavoila_flex', 'Edit element', $row, $table, $eIconf);

            // Visual identification aids:

            //$feedit = is_object(static::getBackendUser()) && method_exists(static::getBackendUser(), 'isFrontendEditingActive') && static::getBackendUser()->isFrontendEditingActive();
            //
            //if ($this->frontendController->fePreview && $this->frontendController->beUserLogin && !$this->frontendController->workspacePreview && !$this->conf['disableExplosivePreview'] && !$feedit) {
            //    throw new \RuntimeException('Further execution of code leads to PHP errors.', 1404750505);
            //    $content = $this->visualID($content, $srcPointer, $DSrec, $TOrec, $row, $table);
            //}
        } catch (ObjectNotFoundException $e) {
            $content = $this->formatError($e->getMessage());
        } catch (SerializationException $e) {
            $content = $this->formatError($e->getMessage());
        } catch (RuntimeException $e) {
            $content = $this->formatError($e->getMessage());
        } catch (\Exception $e) {
            // todo: log this case
            $content = $this->formatError($e->getMessage()); // todo: decide what to do with generic exceptions
        }

        return $content;
    }

    /**
     * Performing pre-processing of the data array.
     * This will transform the data in the data array according to various rules before the data is merged with the template HTML
     * Notice that $dataValues is changed internally as a reference so the function returns no content but internally changes the passed variable for $dataValues.
     *
     * @param array &$dataValues The data values from the XML file (converted to array). Passed by reference.
     * @param array $DSelements The data structure definition which the data in the dataValues array reflects.
     * @param array $TOelements The local XML processing information found in associated Template Objects (TO)
     * @param string $valueKey Value key
     * @param mixed $mappingInfo Mapping information
     */
    protected function processDataValues(array &$dataValues, array $DSelements, $TOelements, $valueKey = 'vDEF', $mappingInfo = true)
    {
        // Create local processing information array:
        $LP = [];
        foreach ($DSelements as $key => $dsConf) {
            if ($mappingInfo === true || array_key_exists($key, $mappingInfo)) {
                if ($DSelements[$key]['type'] !== 'array') { // For all non-arrays:
                    // Set base configuration:
                    $LP[$key] = $DSelements[$key]['tx_templavoila'];
                    // Overlaying local processing:
                    if (is_array($TOelements[$key]['tx_templavoila'])) {
                        if (is_array($LP[$key])) {
                            ArrayUtility::mergeRecursiveWithOverrule($LP[$key], $TOelements[$key]['tx_templavoila']);
                        } else {
                            $LP[$key] = $TOelements[$key]['tx_templavoila'];
                        }
                    }
                }
            }
        }

        // Prepare a fake data record for cObj (important to do now before processing takes place):
        $dataRecord = [];
        foreach ($dataValues as $key => $values) {
            if (isset($dataValues[$key]) && is_array($dataValues[$key])) {
                $dataRecord[$key] = $this->inheritValue($dataValues[$key], $valueKey, $LP[$key]['langOverlayMode']);
            }
        }

        // Check if information about parent record should be set. Note: we do not push/pop registers here because it may break LOAD_REGISTER/RESTORE_REGISTER data transfer between FCEs!
        $savedParentInfo = [];
        $registerKeys = [];
        if (is_array($this->cObj->data)) {
            $tArray = $this->cObj->data;
            ksort($tArray);
            $checksum = md5(serialize($tArray));

            $sameParent = false;
            if (isset($this->frontendController->register['tx_templavoila_pi1.parentRec.__SERIAL'])) {
                $sameParent = ($checksum === $this->frontendController->register['tx_templavoila_pi1.parentRec.__SERIAL']);
            }

            if (!$sameParent) {
                // Step 1: save previous parent records from registers. This happens when pi1 is called for FCEs on a page.
                $unsetKeys = [];
                foreach ($this->frontendController->register as $dkey => $dvalue) {
                    if (preg_match('/^tx_templavoila_pi1\.parentRec\./', $dkey)) {
                        $savedParentInfo[$dkey] = $dvalue;
                        $unsetKeys[] = $dkey;
                    }
                    if (preg_match('/^tx_templavoila_pi1\.(nested_fields|current_field)/', $dkey)) {
                        $savedParentInfo[$dkey] = $dvalue;
                    }
                }

                // Step 2: unset previous parent info
                foreach ($unsetKeys as $dkey) {
                    unset($this->frontendController->register[$dkey]);
                }
                unset($unsetKeys); // free memory

                // Step 3: set new parent record to register
                $registerKeys = [];
                foreach ($this->cObj->data as $dkey => $dvalue) {
                    $registerKeys[] = $tkey = 'tx_templavoila_pi1.parentRec.' . $dkey;
                    $this->frontendController->register[$tkey] = $dvalue;
                }

                // Step 4: update checksum
                $this->frontendController->register['tx_templavoila_pi1.parentRec.__SERIAL'] = $checksum;
                $registerKeys[] = 'tx_templavoila_pi1.parentRec.__SERIAL';
            }
        }

        // For each DS element:
        foreach ($DSelements as $key => $dsConf) {
            // Store key of DS element and the parents being handled in global register
            if (isset($savedParentInfo['nested_fields'])) {
                $this->frontendController->register['tx_templavoila_pi1.nested_fields'] = $savedParentInfo['nested_fields'] . ',' . $key;
            } else {
                $this->frontendController->register['tx_templavoila_pi1.nested_fields'] = $key;
            }
            $this->frontendController->register['tx_templavoila_pi1.current_field'] = $key;

            // Array/Section:
            if ($DSelements[$key]['type'] === 'array' || $DSelements[$key]['tx_templavoila']['type'] === 'array') {
                /* no DS-childs: bail out
                 * no EL-childs: progress (they may all be TypoScript elements without visual representation)
                 */
                if (is_array($DSelements[$key]['el']) /* &&
                    is_array($TOelements[$key]['el'])*/
                ) {
                    if (!isset($dataValues[$key]['el'])) {
                        $dataValues[$key]['el'] = [];
                    }

                    if ($DSelements[$key]['section'] && is_array($dataValues[$key]['el'])) {
                        $registerCounter = 1;
                        foreach ($dataValues[$key]['el'] as $ik => $el) {
                            $this->frontendController->register['tx_templavoila_pi1.sectionPos'] = $registerCounter;
                            $this->frontendController->register['tx_templavoila_pi1.sectionCount'] = count($dataValues[$key]['el']);
                            $this->frontendController->register['tx_templavoila_pi1.sectionIsFirstItem'] = ($registerCounter === 1);
                            $this->frontendController->register['tx_templavoila_pi1.sectionIsLastItem'] = count($dataValues[$key]['el']) === $registerCounter;
                            $registerCounter++;
                            if (is_array($el)) {
                                $theKey = key($el);
                                if (is_array($dataValues[$key]['el'][$ik][$theKey]['el'])) {
                                    $this->processDataValues($dataValues[$key]['el'][$ik][$theKey]['el'], $DSelements[$key]['el'][$theKey]['el'], $TOelements[$key]['el'][$theKey]['el'], $valueKey);

                                    // If what was an array is returned as a non-array (eg. string "__REMOVE") then unset the whole thing:
                                    if (!is_array($dataValues[$key]['el'][$ik][$theKey]['el'])) {
                                        unset($dataValues[$key]['el'][$ik]);
                                    }
                                }
                            }
                        }
                    } else {
                        $this->processDataValues($dataValues[$key]['el'], $DSelements[$key]['el'], $TOelements[$key]['el'], $valueKey);
                    }
                }
            } else {

                // Language inheritance:
                if ($valueKey !== 'vDEF') {
                    if (isset($dataValues[$key]) && is_array($dataValues[$key])) {
                        $dataValues[$key][$valueKey] = $this->inheritValue($dataValues[$key], $valueKey, $LP[$key]['langOverlayMode']);
                    }

                    // The value "__REMOVE" will trigger removal of the item!
                    if (is_array($dataValues[$key][$valueKey]) && !strcmp($dataValues[$key][$valueKey]['ERROR'], '__REMOVE')) {
                        $dataValues = '__REMOVE';

                        return;
                    }
                }

                $tsparserObj = GeneralUtility::makeInstance(TypoScriptParser::class);

                $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
                $cObj->setParent($this->cObj->data, $this->cObj->currentRecord);
                $cObj->start($dataRecord, '_NO_TABLE');

                $cObj->setCurrentVal($dataValues[$key][$valueKey]);

                // Render localized labels for 'select' elements:
                if ($DSelements[$key]['TCEforms']['config']['type'] === 'select'
                    && strpos($dataValues[$key][$valueKey], 'LLL:') === 0
                ) {
                    $tempLangVal = $this->frontendController->sL($dataValues[$key][$valueKey]);
                    if ($tempLangVal !== '') {
                        $dataValues[$key][$valueKey] = $tempLangVal;
                    }
                    unset($tempLangVal);
                }

                // TypoScript / TypoScriptObjPath:
                if (trim($LP[$key]['TypoScript']) || trim($LP[$key]['TypoScriptObjPath'])) {
                    if (trim($LP[$key]['TypoScript'])) {

                        // If constants were found locally/internally in DS/TO:
                        if (is_array($LP[$key]['TypoScript_constants'])) {
                            foreach ($LP[$key]['TypoScript_constants'] as $constant => $value) {

                                // First, see if the constant is itself a constant referring back to TypoScript Setup Object Tree:
                                if (strpos(trim($value), '{$') === 0 && substr(trim($value), -1) === '}') {
                                    $objPath = substr(trim($value), 2, -1);

                                    // If no value for this object path reference was found, get value:
                                    if (!isset($this->frontendController->applicationData['tx_templavoila']['TO_constantCache'][$objPath])) {
                                        // Get value from object path:
                                        $cF = GeneralUtility::makeInstance(TypoScriptParser::class);
                                        list($objPathValue) = $cF->getVal($objPath, $this->frontendController->tmpl->setup);
                                        // Set value in cache table:
                                        $this->frontendController->applicationData['tx_templavoila']['TO_constantCache'][$objPath] .= '' . $objPathValue;
                                    }
                                    // Setting value to the value of the TypoScript Setup object path referred to:
                                    $value = $this->frontendController->applicationData['tx_templavoila']['TO_constantCache'][$objPath];
                                }

                                // Substitute constant:
                                $LP[$key]['TypoScript'] = str_replace('{$' . $constant . '}', $value, $LP[$key]['TypoScript']);
                            }
                        }

                        // If constants were found in Plugin configuration, "plugins.tx_templavoila_pi1.TSconst":
                        if (is_array($this->conf['TSconst.'])) {
                            foreach ($this->conf['TSconst.'] as $constant => $value) {
                                if (!is_array($value)) {
                                    // Substitute constant:
                                    $LP[$key]['TypoScript'] = str_replace('{$TSconst.' . $constant . '}', $value, $LP[$key]['TypoScript']);
                                }
                            }
                        }

                        // Copy current global TypoScript configuration except numerical objects:
                        if (is_array($this->frontendController->tmpl->setup)) {
                            foreach ($this->frontendController->tmpl->setup as $tsObjectKey => $tsObjectValue) {
                                if ($tsObjectKey !== (int)$tsObjectKey) {
                                    $tsparserObj->setup[$tsObjectKey] = $tsObjectValue;
                                }
                            }
                        }

                        $tsparserObj->parse($LP[$key]['TypoScript']);
                        $dataValues[$key][$valueKey] = $cObj->cObjGet($tsparserObj->setup, 'TemplaVoila_Proc.');
                    }
                    if (trim($LP[$key]['TypoScriptObjPath'])) {
                        list($name, $conf) = $tsparserObj->getVal(trim($LP[$key]['TypoScriptObjPath']), $this->frontendController->tmpl->setup);
                        $dataValues[$key][$valueKey] = $cObj->cObjGetSingle($name, $conf, 'TemplaVoila_ProcObjPath--' . str_replace('.', '*', $LP[$key]['TypoScriptObjPath']) . '.');
                    }
                }

                // Various local quick-processing options:
                $pOptions = $LP[$key]['proc'];
                if (is_array($pOptions)) {
                    if ($pOptions['int']) {
                        $dataValues[$key][$valueKey] = (int)$dataValues[$key][$valueKey];
                    }
                    // HSC of all values by default:
                    if ($pOptions['HSC']) {
                        $dataValues[$key][$valueKey] = htmlspecialchars($dataValues[$key][$valueKey]);
                    }
                    if (trim($pOptions['stdWrap'])) {
                        $tsparserObj = GeneralUtility::makeInstance(TypoScriptParser::class);
                        // BUG HERE: should convert array to TypoScript...
                        $tsparserObj->parse($pOptions['stdWrap']);
                        $dataValues[$key][$valueKey] = $cObj->stdWrap($dataValues[$key][$valueKey], $tsparserObj->setup);
                    }
                }
            }
        }

        // Unset curent parent record info
        foreach ($registerKeys as $dkey) {
            unset($this->frontendController->register[$dkey]);
        }

        // Restore previous parent record info if necessary
        foreach ($savedParentInfo as $dkey => $dvalue) {
            $this->frontendController->register[$dkey] = $dvalue;
        }
    }

    /**
     * Processing of language fallback values (inheritance/overlaying)
     * You never need to call this function when "$valueKey" is "vDEF"
     *
     * @param array $dV Array where the values for language and default might be in as keys for "vDEF" and "vXXX"
     * @param string $valueKey Language key, "vXXX"
     * @param string $overlayMode Overriding overlay mode from local processing in Data Structure / TO.
     *
     * @return string|array The value
     */
    protected function inheritValue(array $dV, $valueKey, $overlayMode = '')
    {
        $returnValue = '';

        try {
            if (!is_array($dV)) {
                throw new \InvalidArgumentException(sprintf('Argument "%s" must be of type array, "%s" given', '$dV', gettype($dV)));
            }

            if (!isset($dV['vDEF'])) {
                throw new \RuntimeException('Key "vDEF" of array "$dV" doesn\'t exist');
            }

            if ($valueKey !== 'vDEF') {
                // Prevent PHP warnings
                $defaultValue = isset($dV['vDEF']) ? $dV['vDEF'] : '';
                $languageValue = isset($dV[$valueKey]) ? $dV[$valueKey] : '';

                // Consider overlay modes:
                switch ((string) $overlayMode) {
                    case 'ifFalse': // Normal inheritance based on whether the value evaluates false or not (zero or blank string)
                        $returnValue .= trim($languageValue) ? $languageValue : $defaultValue;
                        break;
                    case 'ifBlank': // Only if the value is truely blank!
                        $returnValue .= strcmp(trim($languageValue), '') ? $languageValue : $defaultValue;
                        break;
                    case 'never':
                        $returnValue .= $languageValue; // Always return its own value
                        break;
                    case 'removeIfBlank':
                        if (!strcmp(trim($languageValue), '')) {
                            // Find a way to avoid returning an array here
                            return ['ERROR' => '__REMOVE'];
                        }
                        break;
                    default:
                        // If none of the overlay modes matched, simply use the default:
                        if ($this->inheritValueFromDefault) {
                            $returnValue .= trim($languageValue) ? $languageValue : $defaultValue;
                        }
                        break;
                }
            } else {
                $returnValue .= $dV[$valueKey];
            }
        } catch (\Exception $e) {
            $this->getLogger()->error($e->getMessage());
        }

        return $returnValue;
    }

    /**
     * Creates an error message for frontend output
     *
     * @param string $string
     *
     * @return string Error message output
     * @string string Error message input
     */
    protected function formatError($string)
    {

        // Set no-cache since the error message shouldn't be cached of course...
        $this->frontendController->set_no_cache();

        if ((int)$this->conf['disableErrorMessages']) {
            return '';
        }
        //
        $output = '
            <!-- TemplaVoila ERROR message: -->
            <div class="tx_templavoila_pi1-error" style="
                    border: 2px red solid;
                    background-color: yellow;
                    color: black;
                    text-align: center;
                    padding: 20px 20px 20px 20px;
                    margin: 20px 20px 20px 20px;
                    ">' .
            '<strong>TemplaVoila ERROR:</strong><br /><br />' . nl2br(htmlspecialchars(trim($string))) .
            '</div>';

        return $output;
    }

    /**
     * Creates a visual response to the TemplaVoila blocks on the page.
     *
     * @param string $content
     * @param string $srcPointer
     * @param array $DSrec
     * @param array $TOrec
     * @param array $row
     * @param string $table
     *
     * @return string
     */
    protected function visualID($content, $srcPointer, $DSrec, $TOrec, $row, $table)
    {

        // Create table rows:
        $tRows = [];

        switch ($table) {
            case 'pages':
                $tRows[] = '<tr style="background-color: #ABBBB4;">
                        <td colspan="2"><b>Page:</b> ' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($row['title'], 30)) . ' <em>[UID:' . $row['uid'] . ']</em></td>
                    </tr>';
                break;
            case 'tt_content':
                $tRows[] = '<tr style="background-color: #ABBBB4;">
                        <td colspan="2"><b>Flexible Content:</b> ' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($row['header'], 30)) . ' <em>[UID:' . $row['uid'] . ']</em></td>
                    </tr>';
                break;
            default:
                $tRows[] = '<tr style="background-color: #ABBBB4;">
                        <td colspan="2">Table "' . $table . '" <em>[UID:' . $row['uid'] . ']</em></td>
                    </tr>';
                break;
        }

        // Draw data structure:
        if (is_numeric($srcPointer)) {
            $tRows[] = '<tr>
                    <td valign="top"><b>Data Structure:</b></td>
                    <td>' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($DSrec['title'], 30)) . ' <em>[UID:' . $srcPointer . ']</em>' .
                ($DSrec['previewicon'] ? '<br/><img src="uploads/tx_templavoila/' . $DSrec['previewicon'] . '" alt="" />' : '') .
                '</td>
        </tr>';
        } else {
            $tRows[] = '<tr>
                    <td valign="top"><b>Data Structure:</b></td>
                    <td>' . htmlspecialchars($srcPointer) . '</td>
                </tr>';
        }

        // Template Object:
        $tRows[] = '<tr>
                <td valign="top"><b>Template Object:</b></td>
                <td>' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($TOrec['title'], 30)) . ' <em>[UID:' . $TOrec['uid'] . ']</em>' .
            ($TOrec['previewicon'] ? '<br/><img src="uploads/tx_templavoila/' . $TOrec['previewicon'] . '" alt="" />' : '') .
            '</td>
    </tr>';
        if ($TOrec['description']) {
            $tRows[] = '<tr>
                    <td valign="top" nowrap="nowrap">&nbsp; &nbsp; &nbsp; Description:</td>
                    <td>' . htmlspecialchars($TOrec['description']) . '</td>
                </tr>';
        }
        $tRows[] = '<tr>
                <td valign="top" nowrap="nowrap">&nbsp; &nbsp; &nbsp; Template File:</td>
                <td>' . htmlspecialchars($TOrec['fileref']) . '</td>
            </tr>';
        $tRows[] = '<tr>
                <td valign="top" nowrap="nowrap">&nbsp; &nbsp; &nbsp; Render type:</td>
                <td>' . htmlspecialchars($TOrec['rendertype'] ? $TOrec['rendertype'] : 'Normal') . '</td>
            </tr>';
        $tRows[] = '<tr>
                <td valign="top" nowrap="nowrap">&nbsp; &nbsp; &nbsp; Language:</td>
                <td>' . htmlspecialchars($TOrec['sys_language_uid'] ? $TOrec['sys_language_uid'] : 'Default') . '</td>
            </tr>';
        $tRows[] = '<tr>
                <td valign="top" nowrap="nowrap">&nbsp; &nbsp; &nbsp; Local Proc.:</td>
                <td>' . htmlspecialchars($TOrec['localprocessing'] ? 'Yes' : '-') . '</td>
            </tr>';

        // Compile information table:
        $infoArray = '<table style="border:1px solid black; background-color: #D9D5C9; font-family: verdana,arial; font-size: 10px;" border="0" cellspacing="1" cellpadding="1">
                        ' . implode('', $tRows) . '
                        </table>';

        // Compile information:
        $id = 'templavoila-preview-' . GeneralUtility::shortMD5(microtime());
        $content = '<div style="text-align: left; position: absolute; display:none; filter: alpha(Opacity=90);" id="' . $id . '">
                        ' . $infoArray . '
                    </div>
                    <div id="' . $id . '-wrapper" style=""
                        onmouseover="
                            document.getElementById(\'' . $id . '\').style.display=\'block\';
                            document.getElementById(\'' . $id . '-wrapper\').attributes.getNamedItem(\'style\').nodeValue = \'border: 2px dashed #333366;\';
                                "
                        onmouseout="
                            document.getElementById(\'' . $id . '\').style.display=\'none\';
                            document.getElementById(\'' . $id . '-wrapper\').attributes.getNamedItem(\'style\').nodeValue = \'\';
                                ">' .
            $content .
            '</div>';

        return $content;
    }

    /**
     * Returns the right template record for the current display
     * Requires the extension "TemplaVoila"
     *
     * @param int $uid The UID of the template record
     * @param string $renderType
     * @param int $langUid
     *
     * @return mixed The record array or <code>false</code>
     */
    protected function getTemplateRecord($uid, $renderType, $langUid)
    {
        /** @var PageRepository $pageRepository */
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);
        $rec = $pageRepository->checkRecord('tx_templavoila_tmplobj', $uid);

        /** @var array $rec */
        if (is_array($rec) && isset($rec['uid'])) {
            $parentUid = (int)$rec['uid'];

            $rendertype_ref = [];
            if (isset($rec['rendertype_ref'])) {
                $rendertype_ref = $pageRepository->checkRecord('tx_templavoila_tmplobj', $rec['rendertype_ref']);
                $rendertype_ref = is_array($rendertype_ref) ? $rendertype_ref : [];
                /** @var array $rendertype_ref */
            }

            /** @var TemplateRepository $templateRepository */
            $templateRepository = GeneralUtility::makeInstance(TemplateRepository::class);

            $renderType = (string)$renderType !== '' ? (string)$renderType : '';
            $langUid = (int)$langUid > 0 ? (int)$langUid : 0;

            if ($renderType !== '') { // If print-flag try to find a proper print-record. If the lang-uid is also set, try to find a combined print/lang record, but if not found, the print rec. will take precedence.
                // Look up print-row for default language:
                $printRow = $templateRepository->findByParentAndRenderTypeAndSysLanguageUid((int)$parentUid, $renderType, 0);
                if (count($printRow) > 0) {
                    $rec = $printRow;
                } elseif (isset($rendertype_ref['uid'])) { // Look in rendertype_ref record:
                    $printRow = $templateRepository->findByParentAndRenderTypeAndSysLanguageUid((int)$rendertype_ref['uid'], $renderType, 0);
                    if (count($printRow) > 0) {
                        $rec = $printRow;
                    }
                }

                if ($langUid > 0) { // If lang_uid is set, try to look up for current language:
                    $printRow = $templateRepository->findByParentAndRenderTypeAndSysLanguageUid((int)$parentUid, $renderType, (int)$langUid);
                    if (count($printRow) > 0) {
                        $rec = $printRow;
                    } elseif (isset($rendertype_ref['uid'])) { // Look in rendertype_ref record:
                        $printRow = $templateRepository->findByParentAndRenderTypeAndSysLanguageUid((int)$rendertype_ref['uid'], $renderType, (int)$langUid);
                        if (count($printRow) > 0) {
                            $rec = $printRow;
                        }
                    }
                }
            } elseif ($langUid > 0) { // If the language uid is set, then try to find a regular record with sys_language_uid
                $printRow = $templateRepository->findByParentAndRenderTypeAndSysLanguageUid((int)$parentUid, '', (int)$langUid);
                if (count($printRow) > 0) {
                    $rec = $printRow;
                } elseif (isset($rendertype_ref['uid'])) { // Look in rendertype_ref record:
                    $printRow = $templateRepository->findByParentAndRenderTypeAndSysLanguageUid((int)$rendertype_ref['uid'], '', (int)$langUid);
                    if (count($printRow) > 0) {
                        $rec = $printRow;
                    }
                }
            }
        }

        return $rec;
    }

    /**
     * Will set header content and BodyTag for template.
     *
     * @param array $MappingInfo_head ...
     * @param array $MappingData_head_cached ...
     * @param string $BodyTag_cached ...
     * @param bool $usePageRenderer try to use the pageRenderer for script and style inclusion
     */
    private function setHeaderBodyParts($MappingInfo_head, $MappingData_head_cached, $BodyTag_cached = '', $usePageRenderer = false)
    {
        /* @var $htmlParse HtmlParser */
        $htmlParse = GeneralUtility::makeInstance(HtmlParser::class);

        $types = [
            'LINK' => 'text/css',
            'STYLE' => 'text/css',
            'SCRIPT' => 'text/javascript'
        ];
        // Traversing mapped header parts:
        if (is_array($MappingInfo_head['headElementPaths'])) {
            $extraHeaderData = [];
            foreach (array_keys($MappingInfo_head['headElementPaths']) as $kk) {
                if (isset($MappingData_head_cached['cArray']['el_' . $kk])) {
                    $tag = strtoupper($htmlParse->getFirstTagName($MappingData_head_cached['cArray']['el_' . $kk]));
                    $attr = $htmlParse->get_tag_attributes($MappingData_head_cached['cArray']['el_' . $kk]);
                    if (isset($GLOBALS['TSFE']) &&
                        $usePageRenderer &&
                        isset($attr[0]['type']) &&
                        isset($types[$tag]) &&
                        $types[$tag] == $attr[0]['type']
                    ) {
                        $name = 'templavoila#' . md5($MappingData_head_cached['cArray']['el_' . $kk]);
                        /** @var PageRenderer $pageRenderer */
                        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
                        switch ($tag) {
                            case 'LINK':
                                $rel = isset($attr[0]['rel']) ? $attr[0]['rel'] : 'stylesheet';
                                $media = isset($attr[0]['media']) ? $attr[0]['media'] : 'all';
                                $pageRenderer->addCssFile($attr[0]['href'], $rel, $media);
                                break;
                            case 'STYLE':
                                $cont = $htmlParse->removeFirstAndLastTag($MappingData_head_cached['cArray']['el_' . $kk]);
                                if ($GLOBALS['TSFE']->config['config']['inlineStyle2TempFile']) {
                                    $pageRenderer->addCssFile(PageGenerator::inline2TempFile($cont, 'css'));
                                } else {
                                    $pageRenderer->addCssInlineBlock($name, $cont);
                                }
                                break;
                            case 'SCRIPT':
                                if (isset($attr[0]['src']) && $attr[0]['src']) {
                                    $pageRenderer->addJsFile($attr[0]['src']);
                                } else {
                                    $cont = $htmlParse->removeFirstAndLastTag($MappingData_head_cached['cArray']['el_' . $kk]);
                                    $pageRenderer->addJsInlineCode($name, $cont);
                                }
                                break;
                            default:
                                // can't happen due to condition
                        }
                    } else {
                        $uKey = md5(trim($MappingData_head_cached['cArray']['el_' . $kk]));
                        $extraHeaderData['TV_' . $uKey] = chr(10) . chr(9) . trim($htmlParse->HTMLcleaner($MappingData_head_cached['cArray']['el_' . $kk], [], '1', 0, ['xhtml' => 1]));
                    }
                }
            }
            // Set 'page.headerData', use the lowest possible free index!
            // This will make sure that header data appears the very first on the page
            // but unfortunately after styles from extensions
            for ($i = 1; $i < PHP_INT_MAX; $i++) {
                if (!isset($GLOBALS['TSFE']->pSetup['headerData.'][$i])) {
                    $GLOBALS['TSFE']->pSetup['headerData.'][$i] = 'TEXT';
                    $GLOBALS['TSFE']->pSetup['headerData.'][$i . '.']['value'] = implode('', $extraHeaderData) . chr(10);
                    break;
                }
            }
            // Alternative way is to prepend it additionalHeaderData but that
            // will still put JS/CSS after any page.headerData. So this code is
            // kept commented here.
            //$GLOBALS['TSFE']->additionalHeaderData = $extraHeaderData + $GLOBALS['TSFE']->additionalHeaderData;
        }

        // Body tag:
        if ($MappingInfo_head['addBodyTag'] && $BodyTag_cached) {
            $GLOBALS['TSFE']->defaultBodyTag = $BodyTag_cached;
        }
    }

    /**
     * @return Logger
     */
    protected function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param bool $langDisabled
     * @param bool $langChildren
     * @return string
     */
    protected function resolveLanguageKey($langDisabled, $langChildren)
    {
        $languageKey = 'DEF';

        if (!$langDisabled
            && !$langChildren
            && (int)$this->frontendController->sys_language_uid > 0
            && strlen($this->frontendController->sys_language_isocode) > 0
        ) {
            $languageKey = strtoupper(trim($this->frontendController->sys_language_isocode));
        }

        return 'l' . $languageKey;
    }
}
