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

namespace Schnitzler\Templavoila\Controller\Backend\AdministrationModule;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Schnitzler\Templavoila\Controller\Backend\AbstractModuleController;
use Schnitzler\Templavoila\Controller\Backend\Configurable;
use Schnitzler\Templavoila\Controller\Backend\Linkable;
use Schnitzler\Templavoila\Domain\Model\DataStructure;
use Schnitzler\Templavoila\Domain\Model\File;
use Schnitzler\Templavoila\Domain\Model\HtmlMarkup;
use Schnitzler\Templavoila\Domain\Repository\DataStructureRepository;
use Schnitzler\Templavoila\Domain\Repository\TemplateRepository;
use Schnitzler\Templavoila\Helper\TagBuilderHelper;
use Schnitzler\Templavoila\Helper\TemplateMappingHelper;
use Schnitzler\Templavoila\Templavoila;
use Schnitzler\Templavoila\Utility\PermissionUtility;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\AbstractButton;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class Schnitzler\Templavoila\Controller\Backend\AdministrationModule\ElementController
 */
class ElementController extends AbstractModuleController implements Configurable, Linkable
{
    /**
     * @var array
     */
    private static $head_markUpTags = [
        'title' => [],
        'script' => [],
        'style' => [],
        'link' => ['single' => 1],
        'meta' => ['single' => 1]
    ];

    /**
     * @var HtmlMarkup
     */
    private $htmlMarkup;

    /**
     * @var string
     */
    private $file = '';

    /**
     * @var int
     */
    private $dataStructureUid;

    /**
     * @var int
     */
    private $templateObjectUid;

    /**
     * @var string
     */
    private $returnUrl = '';

    /**
     * Boolean; if true DS records are file based
     *
     * @var bool
     */
    private $staticDS;

    public function __construct()
    {
        parent::__construct();
        static::getLanguageService()->includeLLFile('EXT:templavoila/Resources/Private/Language/AdministrationModule/MainController/locallang.xlf');
        static::getLanguageService()->includeLLFile('EXT:templavoila/Resources/Private/Language/AdministrationModule/ElementController/locallang.xlf');

        $extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][Templavoila::EXTKEY]);
        $this->staticDS = (bool)$extConf['staticDS.']['enable'];
    }

    /**
     * Central Request Dispatcher
     *
     * @param ServerRequestInterface $request PSR7 Request Object
     * @param ResponseInterface $response PSR7 Response Object
     *
     * @return ResponseInterface
     *
     * @throws \InvalidArgumentException In case an action is not callable
     */
    public function processRequest(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->templateObjectUid = (int)$request->getQueryParams()['templateObjectUid'];
        $this->dataStructureUid = (int)$request->getQueryParams()['dataStructureUid'];
        $this->file = File::filename($request->getQueryParams()['file']);

        if (!file_exists($this->file) || !is_file($this->file)) {
            $this->moduleTemplate->addFlashMessage(
                '$this->file is not a file',
                static::getLanguageService()->getLL('error'),
                FlashMessage::ERROR
            );

            $response->getBody()->write($this->moduleTemplate->renderContent());
            return $response;
        }

        if (($returnUrl = $request->getQueryParams()['returnUrl']) !== null) {
            $this->returnUrl = $returnUrl;

            $backButton = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeLinkButton()
                ->setTitle(static::getLanguageService()->sL('LLL:EXT:lang/Resources/Private/Language/locallang.xlf:button.cancel'))
                ->setHref($returnUrl)
                ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-document-close', Icon::SIZE_SMALL))
            ;

            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton($backButton);
        }

        return parent::processRequest($request, $response);
    }

    /**
     * @param ServerRequest $request
     * @param Response $response
     *
     * @return ResponseInterface
     */
    public function index(ServerRequest $request, Response $response)
    {
        $displayPath = GeneralUtility::_GP('htmlPath');
        $mapElPath = GeneralUtility::_GP('mapElPath');

        $templateMapperView = $this->getStandaloneView('Backend/AdministrationModule/TemplateMapper');
        $templateMapperView->assign('isInEditMode', true);

        /** @var DataStructureEditor $dataStructureEditor */
        $dataStructureEditor = GeneralUtility::makeInstance(
            DataStructureEditor::class,
            $this
        );
        $dataStructureEditor->setMode(DataStructureEditor::MODE_EDIT, true);

        /** @var TemplateMapper $templateMapper */
        $templateMapper = GeneralUtility::makeInstance(
            TemplateMapper::class,
            $this,
            $templateMapperView,
            $dataStructureEditor
        );

        $view = $this->getStandaloneView('Backend/AdministrationModule/Element/Edit');

        try {
            $templateObjectRecord = $this->getTemplateObjectRecord($this->templateObjectUid);
            $dataStructureRecord = [];
            if ($this->staticDS) {
                $dataStructureRecord['dataprot'] = GeneralUtility::getUrl(GeneralUtility::getFileAbsFileName($templateObjectRecord['datastructure']));
            } else {
                $dataStructureRecord = BackendUtility::getRecordWSOL('tx_templavoila_datastructure', $templateObjectRecord['datastructure']);
            }

            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton($this->getPreviewButton(), ButtonBar::BUTTON_POSITION_LEFT, 1);
            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton($this->getSaveButton(), ButtonBar::BUTTON_POSITION_LEFT, 2);
            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton($this->getSaveAsButton(), ButtonBar::BUTTON_POSITION_LEFT, 2);
            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton($this->getResetButton(), ButtonBar::BUTTON_POSITION_LEFT, 2);
            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton($this->getClearButton(), ButtonBar::BUTTON_POSITION_LEFT, 3);
            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton($this->getShowXmlButton(), ButtonBar::BUTTON_POSITION_RIGHT, 1);
        } catch (\Exception $e) {
            $templateObjectRecord = [];
            $dataStructureRecord = [];

            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton($this->getPreviewButton(), ButtonBar::BUTTON_POSITION_LEFT, 1);
            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton($this->getSaveAsButton(), ButtonBar::BUTTON_POSITION_LEFT, 2);
        }

        list($mapping, $structure) = $this->getMappingAndStructureFromSession();

        // Header:
        $relFilePath = substr($this->file, strlen(PATH_site));

        $view->assign('isStatic', $this->staticDS);
        $view->assign('templateFile', [
            'path' => htmlspecialchars($relFilePath),
            'onclick' => 'return top.openUrlInWindow(\'' . GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . $relFilePath . '\',\'FileView\');'
        ]);
        $view->assign('dataStructureFile', [
            'path' => htmlspecialchars($templateObjectRecord['datastructure']),
            'onclick' => 'return top.openUrlInWindow(\'' . GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . $templateObjectRecord['datastructure'] . '\',\'FileView\');'
        ]);
        $view->assign('templateObject', [
            'title' => $templateObjectRecord ? htmlspecialchars(static::getLanguageService()->sL($templateObjectRecord['title'])) : static::getLanguageService()->getLL('mappingNEW')
        ]);
        $view->assign('dataStructure', [
            'title' => $dataStructureRecord ? htmlspecialchars(static::getLanguageService()->sL($dataStructureRecord['title'])) : static::getLanguageService()->getLL('mappingNEW')
        ]);

        $view->assign('accessibleStorageFolders', PermissionUtility::getAccessibleStorageFolders());
        $view->assign('action', $this->getModuleUrl());
        $view->assign('content', $templateMapper->renderTemplateMapper($this->file, $displayPath, $structure, $mapping['MappingInfo']));

        $this->moduleTemplate->setTitle(static::getLanguageService()->getLL('title'));
        $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Backend/Modal');
        $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Templavoila/AdministrationModule');
        $this->moduleTemplate->getPageRenderer()->addInlineSetting(
            'TemplaVoila:AdministrationModule',
            'ModuleUrl',
            $this->getModuleUrl([
                'mapElPath' => $mapElPath,
                'htmlPath' => '',
                'doMappingOfPath' => 1
            ])
        );
        $this->moduleTemplate->setContent($view->render());
        $response->getBody()->write($this->moduleTemplate->renderContent());
        return $response;
    }

    /**
     * @param ServerRequest $request
     * @param Response $response
     *
     * @return ResponseInterface
     */
    public function saveAs(ServerRequest $request, Response $response)
    {
        $view = $this->getStandaloneView('Backend/AdministrationModule/Element/SaveAs');

        $rows = static::getDatabaseConnection()->exec_SELECTgetRows(
            'tx_templavoila_tmplobj.*,tx_templavoila_datastructure.scope',
            'tx_templavoila_tmplobj LEFT JOIN tx_templavoila_datastructure ON tx_templavoila_datastructure.uid=tx_templavoila_tmplobj.datastructure',
            'tx_templavoila_tmplobj.datastructure>0 ' .
            BackendUtility::deleteClause('tx_templavoila_tmplobj') .
            BackendUtility::versioningPlaceholderClause('tx_templavoila_tmplobj'),
            '',
            'tx_templavoila_datastructure.scope, tx_templavoila_tmplobj.pid, tx_templavoila_tmplobj.title'
        );

        $optionGroups = [];
        foreach ($rows as $row) {
            BackendUtility::workspaceOL('tx_templavoila_tmplobj', $row);

            if (!isset($optionGroups[(int)$row['pid']])) {
                $optionGroups[(int)$row['pid']] = [
                    'label' => htmlspecialchars(' (PID: ' . $row['pid'] . ')'),
                    'options' => []
                ];
            }

            $optionGroups[(int)$row['pid']]['options'][] = [
                'value' => (int)$row['uid'],
                'label' => htmlspecialchars(static::getLanguageService()->sL($row['title']) . ' (UID:' . $row['uid'] . ')')
            ];
        }

        $select = TagBuilderHelper::getSelect();
        $select->addAttribute('name', 'templateObjectUid');
        $select->addAttribute('class', 'form-control');

        $option = TagBuilderHelper::getOption();
        $option->addAttribute('value', '0');
        $select->setContent($option->render());

        foreach ($optionGroups as $optionGroup) {
            /** @var array[] $optionGroup */
            $optionGroupTag = TagBuilderHelper::getOptionGroup();
            $optionGroupTag->addAttribute('label', $optionGroup['label']);

            foreach ($optionGroup['options'] as $option) {
                $optionTag = TagBuilderHelper::getOption();
                $optionTag->addAttribute('value', $option['value']);
                $optionTag->setContent($option['label']);

                $optionGroupTag->setContent($optionGroupTag->getContent() . $optionTag->render());
            }

            $select->setContent($select->getContent() . $optionGroupTag->render());
        }

        $view->assign('templateObjectSelectBox', $select->render());
        unset($select, $optionGroupTag, $optionGroup, $optionTag, $option);

        $select = TagBuilderHelper::getSelect();
        $select->addAttribute('name', 'scope');
        $select->addAttribute('class', 'form-control');

        $option1 = TagBuilderHelper::getOption();
        $option1->addAttribute('value', (string)DataStructure::SCOPE_PAGE);
        $option1->setContent('Page Template');

        $option2 = TagBuilderHelper::getOption();
        $option2->addAttribute('value', (string)DataStructure::SCOPE_FCE);
        $option2->setContent('Content Element');

        $option3 = TagBuilderHelper::getOption();
        $option3->addAttribute('value', (string)DataStructure::SCOPE_UNKNOWN);
        $option3->setContent('Undefined');

        $select->setContent(
            $option1->render() .
            $option2->render() .
            $option3->render()
        );

        $view->assign('templateTypeSelectBox', $select->render());
        unset($select, $option1, $option2, $option3);

        $createUrl = $this->getModuleUrl([
            'action' => 'create'
        ]);

        $updateUrl = $this->getModuleUrl();

        $view->assign(
            'storageFolders',
            PermissionUtility::getAccessibleStorageFolders()
        );
        $view->assign('action', [
            'create' => $createUrl,
            'update' => $updateUrl
        ]);

        $this->moduleTemplate->setContent($view->render());
        $response->getBody()->write($this->moduleTemplate->renderContent());
        return $response;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function reset(ServerRequestInterface $request, ResponseInterface $response)
    {
        try {
            $templateObjectRecord = $this->getTemplateObjectRecord($this->templateObjectUid);
        } catch (\Exception $e) {
            $this->moduleTemplate->addFlashMessage(
                $e->getMessage(),
                static::getLanguageService()->getLL('error'),
                FlashMessage::ERROR
            );

            $response->getBody()->write($this->moduleTemplate->renderContent());
            return $response;
        }

        $dataStructureRecord = [];
        if ($this->staticDS) {
            $dataStructureRecord['dataprot'] = GeneralUtility::getUrl(GeneralUtility::getFileAbsFileName($templateObjectRecord['datastructure']));
        } else {
            $dataStructureRecord = BackendUtility::getRecordWSOL('tx_templavoila_datastructure', $templateObjectRecord['datastructure']);
        }
        $dataStructure = GeneralUtility::xml2array($dataStructureRecord['dataprot']);
        $templateMapping = unserialize($templateObjectRecord['templatemapping']);

        $sessionData = [
            'displayFile' => $this->file,
            'TO' => $this->templateObjectUid,
            'DS' => $this->dataStructureUid,
            'currentMappingInfo_head' => $templateMapping['MappingInfo_head'],
            'currentMappingInfo' => $templateMapping['MappingInfo'],
            'dataStruct' => $dataStructure,
            'autoDS' => $dataStructure
        ];

        $sessionKey = static::getModuleName() . '_mappingInfo:' . $this->templateObjectUid;
        static::getBackendUser()->setAndSaveSessionData($sessionKey, $sessionData);

        return $response->withHeader('Location', $this->getModuleUrl());
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function clear(ServerRequestInterface $request, ResponseInterface $response)
    {
        $sessionData = [
            'file' => $this->file,
            'TO' => $this->templateObjectUid,
            'DS' => $this->dataStructureUid
        ];

        static::getBackendUser()->setAndSaveSessionData($this->getSessionKey(), $sessionData);

        return $response->withHeader('Location', $this->getModuleUrl());
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response)
    {
        $post = (array)$request->getParsedBody();

        list($mapping, $structure) = $this->getMappingAndStructureFromSession();
        $mapping = $this->prepareMappingDataToBeStored($mapping);
        $structure = $this->prepareStructureDataToBeStored($structure, $mapping, (int)$post['scope']);

        if ((int)$post['scope'] === DataStructure::SCOPE_PAGE) {
            $structure['meta']['langDisable'] = '1';
        }

        $this->streamlineStructureRecursive($structure);

        /** @var DataStructureRepository $dataStructureRepository */
        $dataStructureRepository = GeneralUtility::makeInstance(DataStructureRepository::class);
        $dataStructureUid = $dataStructureRepository->create([
            'pid' => (int)$post['pid'],
            'title' => $post['title'],
            'scope' => (int)$post['scope'],
            'dataprot' => GeneralUtility::array2xml_cs(
                $structure,
                'T3DataStructure',
                ['useCDATA' => 1]
            )
        ]);

        if ($dataStructureUid <= 0) {
            $this->moduleTemplate->addFlashMessage(
                static::getLanguageService()->getLL('errorTONotCreated'),
                static::getLanguageService()->getLL('error'),
                FlashMessage::ERROR
            );

            return $response->withHeader('Location', $this->getModuleUrl([
                'action' => 'saveAs'
            ]));
        }

        /** @var TemplateRepository $templateRepository */
        $templateRepository = GeneralUtility::makeInstance(TemplateRepository::class);
        $templateObjectUid = $templateRepository->create(
            [
                'pid' => (int)$post['pid'],
                'title' => $post['title'] . ' [Template]',
                'datastructure' => $dataStructureUid,
                'fileref' => substr($this->file, strlen(PATH_site)),
                'templatemapping' => serialize($mapping),
                'fileref_mtime' => @filemtime($this->file),
                'fileref_md5' => @md5_file($this->file)
            ]
        );

        if ($templateObjectUid <= 0) {
            $this->moduleTemplate->addFlashMessage(
                sprintf(
                    static::getLanguageService()->getLL('errorTONotSaved'),
                    $dataStructureUid
                ),
                static::getLanguageService()->getLL('error'),
                FlashMessage::ERROR
            );

            return $response->withHeader('Location', $this->getModuleUrl([
                'action' => 'saveAs'
            ]));
        }

        $this->moduleTemplate->addFlashMessage(
            sprintf(
                static::getLanguageService()->getLL('msgDSTOSaved'),
                $dataStructureUid,
                $templateObjectUid,
                (int)$post['pid']
            ),
            '',
            FlashMessage::OK
        );

        return $response->withHeader('Location', $this->getModuleUrl([
            'id' => (int)$post['pid'],
            'action' => 'reset',
            'dataStructureUid' => $dataStructureUid,
            'templateObjectUid' => $templateObjectUid
        ]));
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response)
    {
        list($mapping, $structure) = $this->getMappingAndStructureFromSession();

        $templateObjectRecord = BackendUtility::getRecordWSOL('tx_templavoila_tmplobj', $this->templateObjectUid);
        if ($this->staticDS) {
            $datastructureRecord['uid'] = $templateObjectRecord['datastructure'];
        } else {
            $datastructureRecord = BackendUtility::getRecordWSOL('tx_templavoila_datastructure', $templateObjectRecord['datastructure']);
        }

        $mapping = $this->prepareMappingDataToBeStored($mapping);
        $structure = $this->prepareStructureDataToBeStored($structure, $mapping, $datastructureRecord['scope']);

        $this->streamlineStructureRecursive($structure);

        if ((int)$templateObjectRecord['uid'] > 0 && (int)$datastructureRecord['uid'] > 0) {

            /** @var DataStructureRepository $dataStructureRepository */
            $dataStructureRepository = GeneralUtility::makeInstance(DataStructureRepository::class);
            $dataStructureRepository->update(
                $datastructureRecord['uid'],
                [
                    'dataprot' => GeneralUtility::array2xml_cs(
                        $structure,
                        'T3DataStructure', ['useCDATA' => 1]
                    )
                ]
            );

            $templateObjectUid = BackendUtility::wsMapId('tx_templavoila_tmplobj', $templateObjectRecord['uid']);

            /** @var TemplateRepository $templateRepository */
            $templateRepository = GeneralUtility::makeInstance(TemplateRepository::class);
            $templateRepository->update(
                $templateObjectUid,
                [
                    'fileref' => substr($this->file, strlen(PATH_site)),
                    'templatemapping' => serialize($mapping),
                    'fileref_mtime' => @filemtime($this->file),
                    'fileref_md5' => @md5_file($this->file)
                ]
            );

            $this->getModuleTemplate()->addFlashMessage(
                sprintf(static::getLanguageService()->getLL('msgDSTOUpdated'), $datastructureRecord['uid'], $templateObjectRecord['uid']),
                '',
                FlashMessage::OK
            );
        }

        return $response->withHeader('Location', $this->getModuleUrl(['action' => 'reset']));
    }

    /**
     * @param array $structure
     * @param array $mapping
     * @param array $mapping
     */
    private function prepareStructureDataToBeStored(array $structure, array $mapping, $scope)
    {
        if (is_array($structure['ROOT']['el'])) {
            /** @var Renderer\ElementTypesRenderer $elementTypesRenderer */
            $elementTypesRenderer = GeneralUtility::makeInstance(Renderer\ElementTypesRenderer::class, $this);
            $elementTypesRenderer->substEtypeWithRealStuff($structure['ROOT']['el'], $mapping['MappingData_cached'], $scope);
        }

        return $structure;
    }

    /**
     * @param array $mapping
     */
    private function prepareMappingDataToBeStored(array $mapping)
    {
        /** @var HtmlParser $htmlParser */
        $htmlParser = GeneralUtility::makeInstance(HtmlParser::class);
        /** @var HtmlMarkup $htmlMarkup */
        $htmlMarkup = GeneralUtility::makeInstance(HtmlMarkup::class);

        $relPathFix = dirname(substr($this->file, strlen(PATH_site))) . '/';
        $fileContent = GeneralUtility::getUrl($this->file);
        $fileContent = $htmlParser->prefixResourcePath($relPathFix, $fileContent);

        $contentSplittedByMapping = $htmlMarkup->splitContentToMappingInfo($fileContent, $mapping['MappingInfo']);
        $mapping['MappingData_cached'] = $contentSplittedByMapping['sub']['ROOT'];

        if (count($mapping['MappingInfo_head']) > 0) {
            list($htmlHeader) = $htmlMarkup->htmlParse->getAllParts(
                $htmlParser->splitIntoBlock('head', $fileContent),
                true,
                false
            );

            $h_currentMappingInfo = [];
            $currentMappingInfo_head = $mapping['MappingInfo_head'];
            if (is_array($currentMappingInfo_head['headElementPaths'])) {
                foreach ($currentMappingInfo_head['headElementPaths'] as $kk => $vv) {
                    $h_currentMappingInfo['el_' . $kk]['MAP_EL'] = $vv;
                }
            }

            $contentSplittedByMapping = $htmlMarkup->splitContentToMappingInfo($htmlHeader, $h_currentMappingInfo);
            $mapping['MappingData_head_cached'] = $contentSplittedByMapping;

            $matches = [];
            preg_match('/<body[^>]*>/i', $fileContent, $matches);
            $mapping['BodyTag_cached'] = $currentMappingInfo_head['addBodyTag'] ? reset($matches) : '';
        }

        return $mapping;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function set(ServerRequestInterface $request, ResponseInterface $response)
    {
        $inputData = $request->getQueryParams()['dataMappingForm'];

        list($mapping, , $sessionData) = $this->getMappingAndStructureFromSession();

        if (is_array($inputData)) {
            ArrayUtility::mergeRecursiveWithOverrule($mapping['MappingInfo'], $inputData);
            $sessionData['currentMappingInfo'] = $mapping['MappingInfo'];
            static::getBackendUser()->setAndSaveSessionData($this->getSessionKey(), $sessionData);
        }

        return $response->withHeader('Location', $this->getModuleUrl());
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function removeDataStructureElement(ServerRequestInterface $request, ResponseInterface $response)
    {
        $DS_element_DELETE = $request->getQueryParams()['DS_element_DELETE'];

        list(, $dataStructure, $sessionData) = $this->getMappingAndStructureFromSession();
        $tree = explode('][', trim(trim($DS_element_DELETE), '[]'));

        if (count($tree) <= 1) {
            $dataStructure['ROOT'] = [
                'tx_templavoila' => [
                    'type' => 'array',
                    'title' => 'ROOT',
                    'description' => static::getLanguageService()->getLL('rootDescription')
                ],
                'type' => 'array',
                'el' => []
            ];
        } else {
            $this->unsetArrayPath($dataStructure, $tree);
        }

        $sessionData['dataStruct'] = $sessionData['autoDS'] = $dataStructure;
        static::getBackendUser()->setAndSaveSessionData($this->getSessionKey(), $sessionData);

        return $response->withHeader('Location', $this->getModuleUrl());
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function updateDataStructure(ServerRequestInterface $request, ResponseInterface $response)
    {
        $DS_element = $request->getQueryParams()['DS_element'];

        list(, $dataStructure, $sessionData) = $this->getMappingAndStructureFromSession();

        $inDS = GeneralUtility::_GP('autoDS');
        if (is_array($inDS)) {
            $this->convertTceFormStringToArrayRecursive($inDS);

            ArrayUtility::mergeRecursiveWithOverrule($dataStructure, $inDS);

            $this->streamlineStructureRecursive($dataStructure);

            $sessionData['dataStruct'] = $sessionData['autoDS'] = $dataStructure;
            static::getBackendUser()->setAndSaveSessionData($this->getSessionKey(), $sessionData);
        }

        return $response->withHeader('Location', $this->getModuleUrl([
            'DS_element' => $DS_element
        ]));
    }

    /**
     * @param array $element
     */
    protected function convertTceFormStringToArrayRecursive(array &$element)
    {
        foreach ($element as $key => &$value) {
            if ($key === 'meta') {
                continue;
            }

            if (isset($value['el']) && is_array($value['el'])) {
                $this->convertTceFormStringToArrayRecursive($value['el']);
            }

            if (isset($value['TCEforms']['config'])) {
                $config = GeneralUtility::xml2array(
                    '<root>' .
                    $value['TCEforms']['config'] .
                    '</root>'
                );

                $value['TCEforms']['config'] = is_array($config) ? $config : [];
            }
        }
    }

    /**
     * @param array $element
     */
    protected function streamlineStructureRecursive(array &$element)
    {
        foreach ($element as $key => &$value) {
            if ($key === 'meta') {
                continue;
            }

            if (isset($value['el']) && is_array($value['el'])) {
                $this->streamlineStructureRecursive($value['el']);
            }

            if (isset($value['tx_templavoila']['type'])) {
                unset($value['type'], $value['section']);

                if ($value['tx_templavoila']['type'] === 'section') {
                    $value['section'] = '1';

                    /*
                     * Whenever a section is created/updated and it does not have any children,
                     * an empty container is created as well, to put the section elements into.
                     *
                     * This is necessary as the processing of sections changed in TYPO3 7.
                     */
                    if (!isset($value['el']) || (is_array($value['el']) && count($value['el']) === 0)) {
                        $value['el']['field_container'] = [
                            'type' => 'array',
                            'tx_templavoila' => [
                                'type' => 'array',
                                'title' => 'Container'
                            ],
                            'el' => []
                        ];
                    }
                }

                if ($value['tx_templavoila']['type'] === 'array' || $value['tx_templavoila']['type'] === 'section') {
                    $value['type'] = 'array';
                }
            }
        }
    }

    /**
     * Makes an array from a context-free xml-string.
     *
     * @param string $string
     *
     * @return array
     */
    public static function unflattenarray($string)
    {
        if (!is_string($string) || !trim($string)) {
            if (is_array($string)) {
                return $string;
            } else {
                return [];
            }
        }

        return GeneralUtility::xml2array('<grouped>' . $string . '</grouped>');
    }

    /*******************************
     *
     * Various helper functions
     *
     *******************************/

    /**
     * Returns Data Structure from the $datString
     *
     * @param string $datString XML content which is parsed into an array, which is returned.
     * @param string $file Absolute filename from which to read the XML data. Will override any input in $datString
     *
     * @return array
     * todo make static
     */
    public static function getDataStructFromDSO($datString, $file = '')
    {
        if ($file) {
            $dataStruct = GeneralUtility::xml2array(GeneralUtility::getUrl($file));
        } else {
            $dataStruct = GeneralUtility::xml2array($datString);
        }

        return is_array($dataStruct) ? $dataStruct : [];
    }

    /**
     * Converts a list of mapping rules to an array
     *
     * @param string $mappingToTags Mapping rules in a list
     * @param bool $unsetAll If set, then the ALL rule (key "*") will be unset.
     *
     * @return array Mapping rules in a multidimensional array.
     * // todo make static helper method
     */
    public static function explodeMappingToTagsStr($mappingToTags, $unsetAll = false)
    {
        $elements = GeneralUtility::trimExplode(',', strtolower($mappingToTags));
        $output = [];
        foreach ($elements as $v) {
            $subparts = GeneralUtility::trimExplode(':', $v);
            $output[$subparts[0]][$subparts[1]][($subparts[2] ? $subparts[2] : '*')] = 1;
        }
        if ($unsetAll) {
            unset($output['*']);
        }

        return $output;
    }

    /**
     * @return string
     */
    public static function getModuleName()
    {
        return 'tv_mod_admin_element';
    }

    /**
     * @return array
     */
    public function getDefaultSettings()
    {
        return [
            'displayMode' => 'source',
            'showDSxml' => ''
        ];
    }

    /**
     * @param array $params
     *
     * @return string
     */
    public function getModuleUrl(array $params = [])
    {
        $defaultParams = [
            'id' => $this->getId(),
            'file' => $this->file,
            'dataStructureUid' => $this->dataStructureUid,
            'templateObjectUid' => $this->templateObjectUid,
            'returnUrl' => $this->returnUrl
        ];

        if (count($params) > 0) {
            ArrayUtility::mergeRecursiveWithOverrule($defaultParams, $params);
        }

        return BackendUtility::getModuleUrl(
            static::getModuleName(),
            $defaultParams
        );
    }

    /**
     * General purpose unsetting of elements in a multidimensional array
     *
     * @param array &$dataStruct Array from which to remove elements (passed by reference!)
     * @param array $ref An array where the values in the specified order points to the position in the array to unset.
     */
    private function unsetArrayPath(&$dataStruct, $ref)
    {
        $key = array_shift($ref);

        if (!count($ref)) {
            unset($dataStruct[$key]);
        } elseif (is_array($dataStruct[$key])) {
            $this->unsetArrayPath($dataStruct[$key], $ref);
        }
    }

    /**
     * @param int $uid
     */
    private function getTemplateObjectRecord($uid)
    {
        if ((int)$uid <= 0) {
            throw new \InvalidArgumentException(
                static::getLanguageService()->getLL('errorNoUidFound'),
                1479981093
            );
        }

        $row = BackendUtility::getRecordWSOL('tx_templavoila_tmplobj', $uid);

        if (!is_array($row)) {
            throw new \LogicException(
                static::getLanguageService()->getLL('errorNoTOfound'),
                1479981243
            );
        }

        return $row;
    }

    /**
     * @param array $currentMappingInfo_head
     * @param mixed $html_header
     *
     * @return mixed
     * todo: why this got lost?
     */
    private function buildCachedMappingInfo_head($currentMappingInfo_head, $html_header)
    {
        $h_currentMappingInfo = [];
        if (is_array($currentMappingInfo_head['headElementPaths'])) {
            foreach ($currentMappingInfo_head['headElementPaths'] as $kk => $vv) {
                $h_currentMappingInfo['el_' . $kk]['MAP_EL'] = $vv;
            }
        }

        return $this->htmlMarkup->splitContentToMappingInfo($html_header, $h_currentMappingInfo);
    }

    /**
     * @return AbstractButton
     */
    private function getPreviewButton()
    {
        return $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeLinkButton()
            ->setTitle('preview')
            ->setShowLabelText(true)
            ->setHref($this->getModuleUrl([
                'preview' => 'source'
            ]))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-document-view', Icon::SIZE_SMALL))
            ;
    }

    /**
     * @return AbstractButton
     */
    private function getSaveButton()
    {
        return $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeLinkButton()
            ->setTitle('save')
            ->setShowLabelText(true)
            ->setHref($this->getModuleUrl([
                'action' => 'save'
            ]))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-document-save', Icon::SIZE_SMALL))
            ;
    }

    /**
     * @return AbstractButton
     */
    private function getSaveAsButton()
    {
        return $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeLinkButton()
            ->setTitle('save as')
            ->setShowLabelText(true)
            ->setHref($this->getModuleUrl([
                'action' => 'saveAs'
            ]))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-document-save', Icon::SIZE_SMALL))
            ;
    }

    /**
     * @return AbstractButton
     */
    private function getResetButton()
    {
        return $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeLinkButton()
            ->setTitle('reset')
            ->setShowLabelText(true)
            ->setHref($this->getModuleUrl([
                'action' => 'reset'
            ]))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-document-synchronize', Icon::SIZE_SMALL))
            ;
    }

    /**
     * @return AbstractButton
     */
    private function getClearButton()
    {
        return $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeLinkButton()
            ->setTitle('clear')
            ->setShowLabelText(true)
            ->setHref($this->getModuleUrl([
                'action' => 'clear'
            ]))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-edit-restore', Icon::SIZE_SMALL))
            ;
    }

    /**
     * @return AbstractButton
     */
    private function getShowXmlButton()
    {
        return $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeLinkButton()
            ->setTitle('show xml')
            ->setShowLabelText(true)
            ->setHref($this->getModuleUrl([
                'action' => 'xml'
            ]))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-document-view', Icon::SIZE_SMALL))
            ;
    }

    /**
     * @return array[]
     */
    private function getMappingAndStructureFromSession()
    {
        $sessionData = static::getBackendUser()->getSessionData($this->getSessionKey());

        $dataStructure = is_array($sessionData['autoDS']) ? $sessionData['autoDS'] : [
            'meta' => [
                'langDisable' => '1'
            ],
            'ROOT' => [
                'tx_templavoila' => [
                    'type' => 'array',
                    'title' => 'ROOT',
                    'description' => static::getLanguageService()->getLL('rootDescription')
                ],
                'type' => 'array',
                'el' => []
            ]
        ];

        $currentMappingInfoHead = is_array($sessionData['currentMappingInfo_head']) ? $sessionData['currentMappingInfo_head'] : [];
        $currentMappingInfoBody = is_array($sessionData['currentMappingInfo']) ? $sessionData['currentMappingInfo'] : [];
        TemplateMappingHelper::removeElementsThatDoNotExistInDataStructure($currentMappingInfoBody, $dataStructure);

        $templateMapping = [
            'MappingInfo' => $currentMappingInfoBody,
            'MappingInfo_head' => $currentMappingInfoHead
        ];

        reset($templateMapping);
        reset($dataStructure);

        return [$templateMapping, $dataStructure, $sessionData];
    }

    /**
     * @return string
     */
    private function getSessionKey()
    {
        return static::getModuleName() . '_mappingInfo:' . $this->templateObjectUid;
    }
}
