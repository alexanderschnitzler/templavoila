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

use Schnitzler\Templavoila\Controller\Backend\AdministrationModule\Renderer\ElementTypesRenderer;
use Schnitzler\Templavoila\Controller\Backend\Linkable;
use Schnitzler\Templavoila\Domain\Model\HtmlMarkup;
use Schnitzler\Templavoila\Helper\TagBuilderHelper;
use Schnitzler\Templavoila\Traits\LanguageService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\Core\ViewHelper\TagBuilder;

/**
 * Class Schnitzler\Templavoila\Controller\Backend\AdministrationModule\DataStructureEditor
 */
class DataStructureEditor
{
    const MODE_MAPPING = 1;

    const MODE_EDIT = 2;

    const MODE_PREVIEW = 4;

    use LanguageService;

    /**
     * @var HtmlMarkup
     */
    private $htmlMarkup;

    /**
     * @var bool
     */
    private $doMappingOfPath;

    /**
     * @var bool
     */
    private $isPreviewEnabled;

    /**
     * @var string
     */
    private $mapElPath;

    /**
     * @var string
     */
    private $DS_cmd;

    /**
     * @var string
     */
    private $DS_element;

    /**
     * @var string
     */
    private $fieldName;

    /**
     * @var int
     */
    private $defaultColPos = 0;

    /**
     * @var Linkable
     */
    private $controller;

    /**
     * @var int
     */
    private $depth = 0;

    /**
     * @var int
     */
    private $mode = 0;

    /**
     * @param Linkable $controller
     */
    public function __construct(Linkable $controller)
    {
        $this->controller = $controller;

        $this->DS_cmd = GeneralUtility::_GP('DS_cmd');
        $this->DS_element = GeneralUtility::_GP('DS_element');
        $this->fieldName = GeneralUtility::_GP('fieldName');
        $this->mapElPath = GeneralUtility::_GP('mapElPath');
        $this->doMappingOfPath = GeneralUtility::_GP('doMappingOfPath');
        $this->htmlMarkup = GeneralUtility::makeInstance(HtmlMarkup::class);
    }

    /**
     * @param int $mode
     * @param bool $value
     */
    public function setMode($mode, $value)
    {
        if ($value) {
            $this->mode |= $mode;
        } else {
            $this->mode &= ~$mode;
        }
    }

    /**
     * @param int $mode
     * @return bool
     */
    public function isModeEnabled($mode)
    {
        return ($this->mode & $mode) === $mode;
    }

    /**
     * @param array $dataStructure Part of Data Structure (array of elements)
     * @param array $currentMappingInfo Part of Current mapping information corresponding to the $dataStruct array - used to evaluate the status of mapping for a certain point in the structure.
     * @param array $pathLevels Array of HTML paths
     * @param array $optDat Options for mapping mode control (INNER, OUTER etc...)
     * @param array $contentSplittedByMapping Content from template file splitted by current mapping info - needed to evaluate whether mapping information for a certain level actually worked on live content!
     * @param string $formPrefix Form field prefix. For each recursion of this function, two [] parts are added to this prefix
     * @param string $path HTML path. For each recursion a section (divided by "|") is added.
     * @param string $mapOK If true, the "Map" link can be shown, otherwise not. Used internally in the recursions.
     *
     * @return array
     */
    public function drawDataStructureMap(
        array $dataStructure,
        array $currentMappingInfo = [],
        array $pathLevels = [],
        array $optDat = [],
        array $contentSplittedByMapping = [],
        $formPrefix = '',
        $path = '',
        $mapOK = true
    ) {
        if (!is_array($dataStructure)) {
            return [];
        }

        $array = [
            'rows' => []
        ];

        $rowIndex = 0;
        foreach ($dataStructure as $key => $value) {
            if ($key === 'meta' || !is_array($value)) {
                continue;
            }

            $type = $this->dsTypeInfo($value);
            $array['rows'][$rowIndex]['buttons'] = [];
            $array['rows'][$rowIndex]['isInEditMode'] = $this->isModeEnabled(static::MODE_EDIT);
            $array['rows'][$rowIndex]['isInMappingMode'] = $this->isModeEnabled(static::MODE_MAPPING);
            $array['rows'][$rowIndex]['isContainer'] = in_array($type, array('sc', 'co')) ;
            $array['rows'][$rowIndex]['type'] = $type;
            $array['rows'][$rowIndex]['key'] = $key;
            $array['rows'][$rowIndex]['icon'] = [
                'identifier' => 'extensions-templavoila-datastructure-' . $type,
                'size' => Icon::SIZE_SMALL
            ];

            $translatedTitle = $value['tx_templavoila']['title'];
            $isTitleTranslated = false;

            if (strpos($value['tx_templavoila']['title'], 'LLL:') === 0) {
                $translatedTitle = static::getLanguageService()->sL($value['tx_templavoila']['title']);
                $isTitleTranslated = true;
            }

            $array['rows'][$rowIndex]['title']['text'] = GeneralUtility::fixed_lgd_cs($translatedTitle, 30);
            $array['rows'][$rowIndex]['title']['isTranslated'] = $isTitleTranslated;
            $array['rows'][$rowIndex]['padding-left'] = $this->depth * 16;
            $array['rows'][$rowIndex]['description'] = $value['tx_templavoila']['description'];
            $array['rows'][$rowIndex]['htmlPath'] = [];

            if ($this->isModeEnabled(static::MODE_MAPPING)) {
                $isMapOK = false;

                if ($currentMappingInfo[$key]['MAP_EL']) {
                    $mappingElement = str_replace('~~~', ' ', $currentMappingInfo[$key]['MAP_EL']);
                    if (isset($contentSplittedByMapping['cArray'][$key])) {
                        list($pathInformation) = $this->htmlMarkup->splitPath($currentMappingInfo[$key]['MAP_EL']);

                        $array['rows'][$rowIndex]['htmlPath']['icon'] = [
                            'identifier' => 'status-dialog-ok',
                            'size' => Icon::SIZE_SMALL
                        ];

                        $array['rows'][$rowIndex]['htmlPath']['tag'] = HtmlMarkup::getGnyfMarkup(
                            $pathInformation['el'],
                            htmlspecialchars(
                                '---' . GeneralUtility::fixed_lgd_cs(
                                    $mappingElement,
                                    -80
                                )
                            )
                        );

                        $modifier = $modifierValue = '';
                        if ($pathInformation['modifier']) {
                            $modifier = $pathInformation['modifier'];

                            $modifierValue = '';
                            if ($pathInformation['modifier'] !== 'RANGE' && $pathInformation['modifier_value']) {
                                $modifierValue = $pathInformation['modifier_value'];
                            }
                        }

                        $array['rows'][$rowIndex]['htmlPath']['text'] = $modifier . $modifierValue;
                        unset($modifier, $modifierValue);

                        $array['rows'][$rowIndex]['htmlPath']['link']['url'] = $this->controller->getModuleUrl([
                            'htmlPath' => $path . ($path ? '|' : '') . preg_replace('/\/[^ ]*$/', '', $currentMappingInfo[$key]['MAP_EL']),
                            'showPathOnly' => 1,
                            'DS_element' => $this->DS_element
                        ]);

                        $array['rows'][$rowIndex]['buttons']['remap'] = [
                            'url' => $this->controller->getModuleUrl([
                                'mapElPath' => $formPrefix . '[' . $key . ']',
                                'htmlPath' => $path,
                                'mappingToTags' => $value['tx_templavoila']['tags'],
                                'DS_element' => $this->DS_element
                            ]),
                            'title' => static::getLanguageService()->getLL('buttonRemapTitle'),
                            'label' => 'Re-Map'
                        ];

                        $array['rows'][$rowIndex]['buttons']['changeMode'] = [
                            'url' => $this->controller->getModuleUrl([
                                'mapElPath' => $formPrefix . '[' . $key . ']',
                                'htmlPath' => $path . ($path ? '|' : '') . $pathInformation['path'],
                                'doMappingOfPath' => 1,
                                'DS_element' => $this->DS_element
                            ]),
                            'title' => static::getLanguageService()->getLL('buttonChangeMode'),
                            'label' => static::getLanguageService()->getLL('buttonChangeMode')
                        ];

                        $isMapOK = true;
                    } else { // Issue warning if mapping was lost:
                        // todo integrate into view
//                        $rowCells['htmlPath'] = $this->iconFactory->getIcon('status-dialog-warning', ['title' => static::getLanguageService()->getLL('msgNoContentFound')]) . htmlspecialchars($mappingElement);
                    }
                }

                // CMD links; Content when current element is under mapping, then display control panel or message:
                if ($this->mapElPath === $formPrefix . '[' . $key . ']') {
                    if ($this->doMappingOfPath) {

                        // Creating option tags:
                        $lastLevel = end($pathLevels);
                        $tagsMapping = ElementController::explodeMappingToTagsStr($value['tx_templavoila']['tags']);

                        $mappingData = is_array($tagsMapping[$lastLevel['el']]) ? $tagsMapping[$lastLevel['el']] : $tagsMapping['*'];
                        unset($mappingData['']);
                        if (is_array($mappingData) && !count($mappingData)) {
                            $mappingData = null;
                        }

                        foreach ($optDat as $k => $v) {
                            $pathInformation = reset($this->htmlMarkup->splitPath($k));

                            $elementIsAnAttribute = $value['tx_templavoila']['type'] === 'attr' && $pathInformation['modifier'] === 'ATTR';
                            $elementIsNotAnAttribute = $value['tx_templavoila']['type'] !== 'attr' && $pathInformation['modifier'] !== 'ATTR';

                            if (!($elementIsAnAttribute || $elementIsNotAnAttribute)) {
                                continue;
                            }

                            $modifier = isset($pathInformation['modifier'])
                                ? (string) $pathInformation['modifier']
                                : '';

                            $modifierValue = isset($pathInformation['modifier_value'])
                                ? (string) $pathInformation['modifier_value']
                                : '';

                            if ($modifier === '') {
                                $modifier = 'OUTER';
                            }

                            $tag = $this->htmlMarkup->tags[$lastLevel['el']];
                            $isSingleElement = isset($tag['single']) && (int)$tag['single'] === 1;
                            $needsClosingTag = !$isSingleElement;

                            if (!($needsClosingTag || $modifier !== 'INNER')) {
                                continue;
                            }

                            $isValidAttribute = $modifier === 'ATTR' && (isset($mappingData['attr']['*']) || isset($mappingData['attr'][$modifierValue]));
                            $isNotAnAttributeButHasMappingData = $modifier !== 'ATTR' && isset($mappingData[strtolower($modifier)]);

                            if (
                                !is_array($mappingData)
                                || $isNotAnAttributeButHasMappingData
                                || $isValidAttribute
                            ) {
                                $array['rows'][$rowIndex]['mappingButtons'][] = [
                                    'url' => $this->controller->getModuleUrl([
                                        'action' => 'set',
                                        'dataMappingForm' . $formPrefix . '[' . $key . '][MAP_EL]' => $k
                                    ]),
                                    'label' => $v . ($k === $currentMappingInfo[$key]['MAP_EL'] ? ' ✓' : '')
                                ];
                            }
                        }

                        $array['rows'][$rowIndex]['select']['name'] = 'dataMappingForm' . $formPrefix . '[' . $key . '][MAP_EL]';
                        // todo: integrate into view
//                        $rowCells['cmdLinks'] = HtmlMarkup::getGnyfMarkup($pathInformation['el'], '---' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($lastLevel['path'], -80)));
//                        $rowCells['cmdLinks'] .= BackendUtility::cshItem('xMOD_tx_templavoila', 'mapping_modeset', '', '');
                    } else {

//                        $array['rows'][$rowIndex]['buttons']['cancel'] = [
//                            'url' => $this->controller->getModuleUrl([
//                                'DS_element' => $this->DS_element
//                            ]),
//                            'label' => static::getLanguageService()->getLL('buttonCancel')
//                        ];

//                        $rowCells['cmdLinks'] = $this->iconFactory->getIcon('status-dialog-notification', Icon::SIZE_SMALL) . '
//                                                <strong>' . static::getLanguageService()->getLL('msgHowToMap') . '</strong>';
//
//                        $cancelUrl = $this->controller->getModuleUrl([
//                            'DS_element' => $this->DS_element
//                        ]);
//
//                        $rowCells['cmdLinks'] .= '<br />
//                                <a class="btn btn-default btn-sm" href="' . $cancelUrl . '">' . static::getLanguageService()->getLL('buttonCancel') . '</a>';
                    }
                } elseif ($mapOK && $type !== 'no' && empty($array['rows'][$rowIndex]['buttons'])) {
                    $array['rows'][$rowIndex]['buttons'][] = [
                        'url' => $this->controller->getModuleUrl([
                                'mapElPath' => $formPrefix . '[' . $key . ']',
                                'htmlPath' => $path,
                                'mappingToTags' => $value['tx_templavoila']['tags'],
                                'DS_element' => $this->DS_element
                        ]),
                        'label' => static::getLanguageService()->getLL('buttonMap')
                    ];
                }
            }

            $array['rows'][$rowIndex]['tag']['rules'] = GeneralUtility::trimExplode(
                ',',
                strtolower($value['tx_templavoila']['tags']),
                true
            );

            if ($this->isModeEnabled(static::MODE_EDIT)) {
                $array['rows'][$rowIndex]['links']['edit'] = [
                    'url' => $this->controller->getModuleUrl([
                        'DS_element' => $formPrefix . '[' . $key . ']'
                    ]),
                    'icon' => [
                        'identifier' => 'actions-document-open',
                        'size' => Icon::SIZE_SMALL
                    ]
                ];

                $array['rows'][$rowIndex]['links']['delete'] = [
                    'url' => $this->controller->getModuleUrl([
                        'action' => 'removeDataStructureElement',
                        'DS_element_DELETE' => $formPrefix . '[' . $key . ']'
                    ]),
                    'icon' => [
                        'identifier' => 'actions-edit-delete',
                        'size' => Icon::SIZE_SMALL
                    ]
                ];
            }

            if ($this->isPreviewEnabled && !is_array($value['tx_templavoila']['sample_data'])) {
                $array['rows'][$rowIndex]['description'] = '[' . static::getLanguageService()->getLL('noSampleData') . ']';
            }

            $array['rows'][$rowIndex]['form']['edit'] = $this->drawDataStructureMap_editItem($formPrefix, $key, $value);

            if (in_array($type, array('sc', 'co'))) {
                if (!$this->mapElPath) {
                    $array['rows'][$rowIndex]['form']['create'] = [
                        'action' => $this->controller->getModuleUrl([
                            'DS_element' => $formPrefix . '[' . $key . ']',
                            'DS_cmd' => 'add'
                        ]),
                        'input' => [
                            'margin-left' => ($this->depth + 1) * 16,
                            'value' => static::getLanguageService()->getLL('mapEnterNewFieldname')
                        ]
                    ];
                }

                if (!isset($value['el'])) {
                    $value['el'] = [];
                }

                $this->depth++;
                $array['rows'][$rowIndex]['children'][] = $tRows = $this->drawDataStructureMap(
                    $value['el'],
                    is_array($currentMappingInfo[$key]['el']) ? $currentMappingInfo[$key]['el'] : [],
                    $pathLevels,
                    $optDat,
                    is_array($contentSplittedByMapping['sub'][$key]) ? $contentSplittedByMapping['sub'][$key] : [],
                    $formPrefix . '[' . $key . '][el]',
                    $path . ($path ? '|' : '') . $currentMappingInfo[$key]['MAP_EL'],
                    $isMapOK
                );
                $this->depth--;
            }

            $rowIndex++;
        }

        return $array;
    }

    /**
     * Creates the editing row for a Data Structure element - when DS's are build...
     *
     * @param string $formPrefix Form element prefix
     * @param string $key Key for form element
     * @param array $value Values for element
     * @param int $this->depth Indentation level
     *
     * @return array
     */
    public function drawDataStructureMap_editItem($formPrefix, $key, $value)
    {
        $return = [];
        $type = $this->dsTypeInfo($value);
        if ($this->DS_element !== $formPrefix . '[' . $key . ']') {
            if (!$this->mapElPath && (in_array($type, array('sc', 'co')))) {
                // todo: edit this
                $addEditRows = '<tr class="bgColor4">
                <td colspan="7">' .
                    '<input style="margin-left:' . (($this->depth + 1) * 16) . 'px;" type="text" name="' . md5($formPrefix . '[' . $key . ']') . '" value="[' . htmlspecialchars(static::getLanguageService()->getLL('mapEnterNewFieldname')) . ']" onfocus="if (this.value==\'[' . static::getLanguageService()->getLL('mapEnterNewFieldname') . ']\'){this.value=\'field_\';}" />' .
                    '<input type="submit" name="_" value="Add" onclick="document.location=\'' . $this->controller->getModuleUrl(['DS_element' => $formPrefix . '[' . $key . ']', 'DS_cmd' => 'add']) . '&amp;fieldName=\'+document.pageform[\'' . md5($formPrefix . '[' . $key . ']') . '\'].value; return false;" />' .
                    BackendUtility::cshItem('xMOD_tx_templavoila', 'mapping_addfield', '', '') .
                    '</td>
            </tr>';
            }

            return [];
        }

        $autokey = '';
        if ($this->DS_cmd === 'add') {
            if (trim($this->fieldName) !== '[' . htmlspecialchars(static::getLanguageService()->getLL('mapEnterNewFieldname')) . ']' && trim($this->fieldName) !== 'field_') {
                $autokey = strtolower(preg_replace('/[^a-z0-9_]/i', '', trim($this->fieldName)));
                if (isset($value['el'][$autokey])) {
                    $autokey .= '_' . substr(md5(microtime()), 0, 2);
                }
            } else {
                $autokey = 'field_' . substr(md5(microtime()), 0, 6);
            }

            $formFieldName = 'autoDS' . $formPrefix . '[' . $key . '][el][' . $autokey . ']';
            $insertDataArray = [];
        } else {
            $formFieldName = 'autoDS' . $formPrefix . '[' . $key . ']';
            $insertDataArray = $value;
        }

        $insertDataArray['tx_templavoila']['TypoScript_constants'] = ElementController::unflattenarray(
            $insertDataArray['tx_templavoila']['TypoScript_constants']
        );

        $insertDataArray['TCEforms']['config'] = ElementController::unflattenarray(
            $insertDataArray['TCEforms']['config']
        );

        /* do the preset-completition */
        $real = [$key => &$insertDataArray];

        $elementTypesRenderer = GeneralUtility::makeInstance(ElementTypesRenderer::class);
        $elementTypesRenderer->substEtypeWithRealStuff($real);

        $this->addFieldsForAllElementTypes($return, $insertDataArray, $formFieldName);
        if ($insertDataArray['type'] !== 'array') {
            $this->addFieldsForAllButContainerElements($return, $insertDataArray, $formFieldName);
        }

        /*
         * Add button
         */
        $submit = TagBuilderHelper::getSubmitButton();
        $submit->addAttribute('name', '_updateDS');
        $submit->addAttribute('class', 'btn btn-default btn-small');

        $submit->addAttribute('value', static::getLanguageService()->getLL('buttonUpdate'));
        if ($this->DS_cmd === 'add') {
            $submit->addAttribute('value', static::getLanguageService()->getLL('buttonAdd'));
        }

        $return['submit']['update'] = $submit->render();
        unset($submit);

        /*
         * Cancel button
         */
        /** @var TagBuilder $submit */
        $submit = GeneralUtility::makeInstance(TagBuilder::class);
        $submit->setTagName('a');
        $submit->addAttribute('href', $this->controller->getModuleUrl());
        $submit->addAttribute('class', 'btn btn-default btn-small');
        $submit->forceClosingTag(true);
        $submit->setContent(static::getLanguageService()->getLL('buttonCancelClose'));

        if ($this->DS_cmd === 'add') {
            $submit->setContent(static::getLanguageService()->getLL('buttonCancel'));
        }

        $return['submit']['cancel'] = $submit->render();
        unset($submit);

        $return['action'] = $this->controller->getModuleUrl([
            'action' => 'updateDataStructure',
            'DS_element' => $this->DS_element . ($this->DS_cmd === 'add' ? '[el][' . $autokey . ']' : '')
        ]);

        if ($this->DS_cmd === 'add') {
            $return['fieldname'] = $autokey . ' (new)';
        } else {
            $return['fieldname'] = $key;
        }

        $return['htmlPath'] = '';
        $return['cmdLinks'] = '';
        $return['tagRules'] = '';

        return $return;
    }

    /**
     * @param array $conf
     *
     * @return string
     */
    private function dsTypeInfo($conf)
    {
        if ($conf['type'] === 'array' && !empty($conf['section'])) {
            return 'sc';
        }
        if ($conf['tx_templavoila']['type'] === 'section') {
            return 'sc';
        }

        if ($conf['tx_templavoila']['type'] === 'array') {
            if (!$conf['section']) {
                return 'co';
            }

            return 'sc';
        }

        if ($conf['tx_templavoila']['type'] === 'attr') {
            return 'at';
        }

        if ($conf['tx_templavoila']['type'] === 'no_map') {
            return 'no';
        }

        return 'el';
    }

    /**
     * Makes a context-free xml-string from an array.
     *
     * @param array $array
     * @param string $pfx
     *
     * @return string
     * todo: check if can be replaced with ArrayUtility::flatten()
     */
    private function flattenarray($array, $pfx = '')
    {
        if (!is_array($array)) {
            if (is_string($array)) {
                return $array;
            } else {
                return '';
            }
        }

        return str_replace("<>\n", '', str_replace('</>', '', GeneralUtility::array2xml($array, '', -1, '', 0, ['useCDATA' => 1])));
    }

    /**
     * @param array $array
     * @param array $insertDataArray
     * @param string $formFieldName
     */
    private function addFieldsForAllElementTypes(array &$array, array $insertDataArray, $formFieldName)
    {
        /*
         * type select field
         */
        $array['input']['type'] = [
            'label' => 'Type',
            'html' => $this->renderTypeSelectField($formFieldName, $insertDataArray['tx_templavoila']['type'])
        ];

        /*
         * title
         */
        $textField = TagBuilderHelper::getTextField();
        $textField->addAttribute('name', $formFieldName . '[tx_templavoila][title]');
        $textField->addAttribute('value', $insertDataArray['tx_templavoila']['title']);
        $textField->addAttribute('class', 'form-control');

        $array['input']['title'] = [
            'label' => static::getLanguageService()->getLL('renderDSO_title'),
            'html' => $textField->render()
        ];
        unset($textField);

        /*
         * description (mapping instructions)
         */
        $textField = TagBuilderHelper::getTextField();
        $textField->addAttribute('name', $formFieldName . '[tx_templavoila][description]');
        $textField->addAttribute('value', $insertDataArray['tx_templavoila']['description']);
        $textField->addAttribute('class', 'form-control');

        $array['input']['description'] = [
            'label' => static::getLanguageService()->getLL('renderDSO_mappingInstructions'),
            'html' => $textField->render()
        ];
        unset($textField);

        /*
         * tags (mapping rules)
         */
        $textField = TagBuilderHelper::getTextField();
        $textField->addAttribute('name', $formFieldName . '[tx_templavoila][tags]');
        $textField->addAttribute('value', $insertDataArray['tx_templavoila']['tags']);
        $textField->addAttribute('class', 'form-control');

        $array['input']['tags'] = [
            'label' => 'Mapping rules:',
            'html' => $textField->render()
        ];
        unset($textField);

        $radio1 = TagBuilderHelper::getRadio();
        $radio1->addAttribute('name', $formFieldName . '[tx_templavoila][preview]');
        $radio1->addAttribute('value', '');

        if ($insertDataArray['tx_templavoila']['preview'] !== 'disable') {
            $radio1->addAttribute('checked', 'checked');
        }

        $radio2 = TagBuilderHelper::getRadio();
        $radio2->addAttribute('name', $formFieldName . '[tx_templavoila][preview]');
        $radio2->addAttribute('value', 'disable');

        if ($insertDataArray['tx_templavoila']['preview'] === 'disable') {
            $radio2->addAttribute('checked', 'checked');
        }

        $array['input']['preview'] = [
            'label' => static::getLanguageService()->getLL('mapEnablePreview'),
            'radio' => [
                'enable' => [
                    'label' => static::getLanguageService()->getLL('mapEnablePreview.enable'),
                    'html' => $radio1->render()
                ],
                'disable' => [
                    'label' => static::getLanguageService()->getLL('mapEnablePreview.disable'),
                    'html' => $radio2->render()
                ]
            ]
        ];
        unset($radio1, $radio2);
    }

    /**
     * @param array $array
     * @param array $insertDataArray
     * @param string $formFieldName
     */
    private function addFieldsForAllButContainerElements(array &$array, array $insertDataArray, $formFieldName)
    {
        $textarea = TagBuilderHelper::getTextarea();
        $textarea->addAttribute('name', $formFieldName . '[tx_templavoila][sample_data][]');
        $textarea->addAttribute('class', 'form-control');
        $textarea->addAttribute('rows', '10');
        $textarea->setContent(htmlspecialchars($insertDataArray['tx_templavoila']['sample_data'][0]));

        $array['input']['sample_data'] = [
            'label' => static::getLanguageService()->getLL('mapSampleData'),
            'html' => $textarea->render()
        ];
        unset($textarea);

        $array['input']['elementType'] = [
            'label' => static::getLanguageService()->getLL('mapElementPreset'),
            'html' => $this->renderElementTypeSelectField(
                $formFieldName . '[tx_templavoila][eType]',
                $insertDataArray['tx_templavoila']['eType'],
                (int)($insertDataArray['tx_templavoila']['oldStyleColumnNumber'] ?: $this->defaultColPos)
            )
        ];

        $hidden = TagBuilderHelper::getHiddenField();
        $hidden->addAttribute('name', $formFieldName . '[tx_templavoila][eType_before]');
        $hidden->addAttribute('value', $insertDataArray['tx_templavoila']['eType']);
        $hidden->addAttribute('class', 'form-control');

        $array['input']['elementTypeBefore'] = [
            'html' => $hidden->render()
        ];
        unset($hidden);

        if ($insertDataArray['tx_templavoila']['eType'] !== 'TypoScriptObject') {
            $textarea = TagBuilderHelper::getTextarea();
            $textarea->addAttribute('name', $formFieldName . '[tx_templavoila][TypoScript_constants]');
            $textarea->addAttribute('class', 'form-control');
            $textarea->addAttribute('rows', '10');
            $textarea->setContent(htmlspecialchars($this->flattenarray($insertDataArray['tx_templavoila']['TypoScript_constants'])));

            $array['input']['TypoScript_constants'] = [
                'label' => static::getLanguageService()->getLL('mapTSconstants'),
                'html' => $textarea->render()
            ];
            unset($textarea);

            $textarea = TagBuilderHelper::getTextarea();
            $textarea->addAttribute('name', $formFieldName . '[tx_templavoila][TypoScript]');
            $textarea->addAttribute('class', 'form-control');
            $textarea->addAttribute('rows', '10');
            $textarea->setContent(htmlspecialchars($this->flattenarray($insertDataArray['tx_templavoila']['TypoScript'])));

            $array['input']['TypoScript'] = [
                'label' => static::getLanguageService()->getLL('mapTScode'),
                'html' => $textarea->render()
            ];
            unset($textarea);

            $textField = TagBuilderHelper::getTextarea();
            $textField->addAttribute('name', $formFieldName . '[TCEforms][label]');
            $textField->addAttribute('value', $this->flattenarray($insertDataArray['TCEforms']['label']));
            $textField->addAttribute('class', 'form-control');

            $array['input']['TCEforms']['label'] = [
                'label' => static::getLanguageService()->getLL('mapTCElabel'),
                'html' => $textField->render()
            ];
            unset($textField);

            $textarea = TagBuilderHelper::getTextarea();
            $textarea->addAttribute('name', $formFieldName . '[TCEforms][config]');
            $textarea->addAttribute('class', 'form-control');
            $textarea->addAttribute('rows', '10');
            $textarea->setContent(htmlspecialchars($this->flattenarray($insertDataArray['TCEforms']['config'])));

            $array['input']['TCEforms']['config'] = [
                'label' => static::getLanguageService()->getLL('mapTCEconf'),
                'html' => $textarea->render()
            ];
            unset($textarea);

            $textField = TagBuilderHelper::getTextarea();
            $textField->addAttribute('name', $formFieldName . '[TCEforms][defaultExtras]');
            $textField->addAttribute('value', $this->flattenarray($insertDataArray['TCEforms']['defaultExtras']));
            $textField->addAttribute('class', 'form-control');

            $array['input']['TCEforms']['defaultExtras'] = [
                'label' => static::getLanguageService()->getLL('mapTCEextras'),
                'html' => $textField->render()
            ];
            unset($textField);
        } else {
            $currentValue = '';
            if (isset($insertDataArray['tx_templavoila']['TypoScriptObjPath'])) {
                $currentValue = ['objPath' => $insertDataArray['tx_templavoila']['TypoScriptObjPath']];
            } elseif (isset($insertDataArray['tx_templavoila']['eType_EXTRA'])) {
                $currentValue = $insertDataArray['tx_templavoila']['eType_EXTRA'];
            }

            $textField = TagBuilderHelper::getTextField();
            $textField->addAttribute('name', $formFieldName . '[tx_templavoila][TypoScriptObjPath]');
            $textField->addAttribute('value', isset($currentValue['objPath']) ? $currentValue['objPath'] : 'lib.');
            $textField->addAttribute('class', 'form-control');

            $array['input']['TypoScriptObjPath'] = [
                'label' => static::getLanguageService()->getLL('mapObjectPath'),
                'html' => $textField->render()
            ];
            unset($textField);
        }

        $array['input']['proc'] = [
            'label' => static::getLanguageService()->getLL('mapPostProcesses'),
            'int' => [
                'label' => static::getLanguageService()->getLL('mapPPcastInteger'),
                'html' => $this->renderIntCheckbox(
                    $formFieldName . '[tx_templavoila][proc][int]',
                    (int)$insertDataArray['tx_templavoila']['proc']['int']
                )
            ],
            'hsc' => [
                'label' => static::getLanguageService()->getLL('mapPPhsc'),
                'html' => $this->renderHscCheckbox(
                    $formFieldName . '[tx_templavoila][proc][HSC]',
                    (int)$insertDataArray['tx_templavoila']['proc']['HSC']
                )
            ]
        ];

        $textarea = TagBuilderHelper::getTextarea();
        $textarea->addAttribute('name', $formFieldName . '[tx_templavoila][proc][stdWrap]');
        $textarea->addAttribute('rows', '10');
        $textarea->addAttribute('class', 'form-control');
        $textarea->setContent($insertDataArray['tx_templavoila']['proc']['stdWrap']);

        $array['input']['proc']['stdWrap'] = [
            'label' => static::getLanguageService()->getLL('mapCustomStdWrap'),
            'html' => $textarea->render()
        ];

        if ($insertDataArray['tx_templavoila']['eType'] === 'ce') {
            if (!isset($insertDataArray['tx_templavoila']['oldStyleColumnNumber'])) {
                $insertDataArray['tx_templavoila']['oldStyleColumnNumber'] = $this->defaultColPos++;
            }

            $textField = TagBuilderHelper::getTextField();
            $textField->addAttribute('name', $formFieldName . '[tx_templavoila][oldStyleColumnNumber]');
            $textField->addAttribute('value', (string)(int)$insertDataArray['tx_templavoila']['oldStyleColumnNumber']);
            $textField->addAttribute('class', 'form-control');

            $array['input']['oldStyleColumnNumber'] = [
                'label' => static::getLanguageService()->getLL('mapOldStyleColumnNumber'),
                'html' => $textField->render()
            ];
            unset($textField);

            $array['input']['enableDragDrop'] = [
                'label' => static::getLanguageService()->getLL('mapEnableDragDrop'),
                'html' => $this->renderDragAndDropCheckbox(
                    $formFieldName . '[tx_templavoila][enableDragDrop]',
                    (int)$insertDataArray['tx_templavoila']['enableDragDrop']
                )
            ];
        }
    }

    /**
     * @param string $formFieldName
     * @param string $currentType
     * @return string
     */
    private function renderTypeSelectField($formFieldName, $currentType)
    {
        $currentType = (string) $currentType;

        $select = TagBuilderHelper::getSelect();
        $select->addAttribute('name', $formFieldName . '[tx_templavoila][type]');
        $select->addAttribute('title', 'Mapping Type');
        $select->addAttribute('class', 'form-control');

        $optionGroup1 = TagBuilderHelper::getOptionGroup();
        $optionGroup1->addAttribute('label', static::getLanguageService()->getLL('mapElContainers'));

        $option1 = TagBuilderHelper::getOption();
        $option1->addAttribute('value', 'section');
        $option1->setContent(static::getLanguageService()->getLL('mapSection'));

        if ($currentType === 'section') {
            $option1->addAttribute('selected', 'selected');
        }

        $option2 = TagBuilderHelper::getOption();
        $option2->addAttribute('value', 'array');
        $option2->setContent(static::getLanguageService()->getLL('mapContainer'));

        if ($currentType === 'array') {
            $option2->addAttribute('selected', 'selected');
        }

        $optionGroup1->setContent(
            $option1->render() .
            $option2->render()
        );
        unset($option1, $option2);

        $optionGroup2 = TagBuilderHelper::getOptionGroup();
        $optionGroup2->addAttribute('label', static::getLanguageService()->getLL('mapElElements'));

        $option1 = TagBuilderHelper::getOption();
        $option1->addAttribute('value', 'el');
        $option1->setContent(static::getLanguageService()->getLL('mapElement'));

        if ($currentType === 'el' || $currentType === '') {
            $option1->addAttribute('selected', 'selected');
        }

        $option2 = TagBuilderHelper::getOption();
        $option2->addAttribute('value', 'attr');
        $option2->setContent(static::getLanguageService()->getLL('mapAttribute'));

        if ($currentType === 'attr') {
            $option2->addAttribute('selected', 'selected');
        }

        $optionGroup2->setContent(
            $option1->render() .
            $option2->render()
        );
        unset($option1, $option2);

        $optionGroup3 = TagBuilderHelper::getOptionGroup();
        $optionGroup3->addAttribute('label', static::getLanguageService()->getLL('mapPresetGroups_other'));

        $option = TagBuilderHelper::getOption();
        $option->addAttribute('value', 'no_map');
        $option->setContent(static::getLanguageService()->getLL('mapNotMapped'));

        if ($currentType === 'no_map') {
            $option->addAttribute('selected', 'selected');
        }

        $optionGroup3->setContent($option->render());
        unset($option);

        $select->setContent(
            $optionGroup1->render() .
            $optionGroup2->render() .
            $optionGroup3->render()
        );

        return $select->render();
    }

    /**
     * @param string $name
     * @param string $value
     * @param int $oldStyleColumnNumber
     *
     * @return string
     */
    private function renderElementTypeSelectField($name, $value, $oldStyleColumnNumber)
    {
        $elementTypes = ElementTypesRenderer::defaultEtypes();
        $eTypes_formFields = GeneralUtility::trimExplode(',', $elementTypes['defaultTypes_formFields']);
        $eTypes_typoscriptElements = GeneralUtility::trimExplode(',', $elementTypes['defaultTypes_typoscriptElements']);
        $eTypes_misc = GeneralUtility::trimExplode(',', $elementTypes['defaultTypes_misc']);

        $optionGroup1 = TagBuilderHelper::getOptionGroup();
        $optionGroup1->addAttribute('label', static::getLanguageService()->getLL('mapPresetGroups_tceFields'));

        foreach ($eTypes_formFields as $elementType) {
            $label = $elementType === 'ce'
                ? sprintf($elementTypes['eType'][$elementType]['label'], $oldStyleColumnNumber)
                : $elementTypes['eType'][$elementType]['label'];

            $option = TagBuilderHelper::getOption();
            $option->addAttribute('value', $elementType);
            $option->setContent($label);

            if ($value === $elementType) {
                $option->addAttribute('selected', 'selected');
            }

            $optionGroup1->setContent(
                $optionGroup1->getContent() .
                $option->render()
            );
        }

        $optionGroup2 = TagBuilderHelper::getOptionGroup();
        $optionGroup2->addAttribute('label', static::getLanguageService()->getLL('mapPresetGroups_ts'));

        foreach ($eTypes_typoscriptElements as $elementType) {
            $option = TagBuilderHelper::getOption();
            $option->addAttribute('value', $elementType);
            $option->setContent(htmlspecialchars($elementTypes['eType'][$elementType]['label']));

            if ($value === $elementType) {
                $option->addAttribute('selected', 'selected');
            }

            $optionGroup2->setContent(
                $optionGroup2->getContent() .
                $option->render()
            );
        }

        $optionGroup3 = TagBuilderHelper::getOptionGroup();
        $optionGroup3->addAttribute('label', static::getLanguageService()->getLL('mapPresetGroups_other'));

        foreach ($eTypes_misc as $elementType) {
            $option = TagBuilderHelper::getOption();
            $option->addAttribute('value', $elementType);
            $option->setContent(htmlspecialchars($elementTypes['eType'][$elementType]['label']));

            if ($value === $elementType) {
                $option->addAttribute('selected', 'selected');
            }

            $optionGroup3->setContent(
                $optionGroup3->getContent() .
                $option->render()
            );
        }

        $select = TagBuilderHelper::getSelect();
        $select->addAttribute('name', $name);
        $select->addAttribute('class', 'form-control');
        $select->setContent(
            $optionGroup1->render() .
            $optionGroup2->render() .
            $optionGroup3->render()
        );

        return $select->render();
    }

    /**
     * @param string $name
     * @param int $value
     *
     * @return string
     */
    private function renderIntCheckbox($name, $value)
    {
        $checkbox = TagBuilderHelper::getCheckbox();
        $checkbox->addAttribute('class', 'checkbox');
        $checkbox->addAttribute('data-id', 'tv_proc_int');
        $checkbox->addAttribute('value', '1');

        if ($value === 1) {
            $checkbox->addAttribute('checked', 'checked');
        }

        $hidden = TagBuilderHelper::getHiddenField();
        $hidden->addAttribute('name', $name);
        $hidden->addAttribute('value', (string)$value);
        $hidden->addAttribute('id', 'tv_proc_int');

        return $checkbox->render() . $hidden->render();
    }

    /**
     * @param string $name
     * @param int $value
     *
     * @return string
     */
    private function renderHscCheckbox($name, $value)
    {
        $checkbox = TagBuilderHelper::getCheckbox();
        $checkbox->addAttribute('class', 'checkbox');
        $checkbox->addAttribute('data-id', 'tv_proc_hsc');
        $checkbox->addAttribute('value', '1');

        if ($value === 1) {
            $checkbox->addAttribute('checked', 'checked');
        }

        $hidden = TagBuilderHelper::getHiddenField();
        $hidden->addAttribute('name', $name);
        $hidden->addAttribute('value', (string)$value);
        $hidden->addAttribute('id', 'tv_proc_hsc');

        return $checkbox->render() . $hidden->render();
    }

    /**
     * @param string $name
     * @param int $value
     *
     * @return string
     */
    private function renderDragAndDropCheckbox($name, $value)
    {
        $checkbox = TagBuilderHelper::getCheckbox();
        $checkbox->addAttribute('value', '1');
        $checkbox->addAttribute('data-id', 'tv_enabledragdrop');

        if ($value === 1) {
            $checkbox->addAttribute('checked', 'checked');
        }

        $hidden = TagBuilderHelper::getHiddenField();
        $hidden->addAttribute('name', $name);
        $hidden->addAttribute('value', (string)$value);
        $hidden->addAttribute('id', 'tv_enabledragdrop');

        return $checkbox->render() . $hidden->render();
    }
}
