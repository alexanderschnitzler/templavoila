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

namespace Schnitzler\Templavoila\Controller\Backend\AdministrationModule\Renderer;

use Schnitzler\Templavoila\Controller\Backend\AdministrationModule\ElementController;
use Schnitzler\Templavoila\Templavoila;
use Schnitzler\Templavoila\Traits\BackendUser;
use Schnitzler\Templavoila\Traits\LanguageService;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class Schnitzler\Templavoila\Controller\Backend\AdministrationModule\Renderer\ElementTypesRenderer
 */
class ElementTypesRenderer implements SingletonInterface
{
    use BackendUser;
    use LanguageService;

    /**
     * When mapping HTML files to DS the field types are selected amount some presets - this function converts these presets into the actual settings needed in the DS
     * Typically called like: ->substEtypeWithRealStuff($storeDataStruct['ROOT']['el'],$contentSplittedByMapping['sub']['ROOT']);
     * Notice: this function is used to preview XML also. In this case it is always called with $scope=0, so XML for 'ce' type will not contain wrap with TYPO3SEARCH_xxx. Currently there is no way to avoid it.
     *
     * @param array $elArray Data Structure, passed by reference!
     * @param array $v_sub Actual template content splitted by Data Structure
     * @param int $scope Scope as defined in tx_templavoila_datastructure.scope
     *
     * @see renderFile()
     */
    public function substEtypeWithRealStuff(&$elArray, $v_sub = [], $scope = 0)
    {
        if ($v_sub === null) {
            $v_sub = [];
        }

        // Traverse array
        foreach ($elArray as $key => $value) {
            // this MUST not ever enter the XMLs (it will break TV)
            if ($elArray[$key]['type'] === 'section' || $elArray[$key]['section']) {
                $elArray[$key]['type'] = 'array';
                $elArray[$key]['section'] = '1';
            } else {
                $elArray[$key]['section'] = '0';
            }

            // put these into array-form for preset-completition
            if (!is_array($elArray[$key]['tx_templavoila']['TypoScript_constants'])) {
                $elArray[$key]['tx_templavoila']['TypoScript_constants'] = ElementController::unflattenarray($elArray[$key]['tx_templavoila']['TypoScript_constants']);
            }
            if (!is_array($elArray[$key]['TCEforms']['config'])) {
                $elArray[$key]['TCEforms']['config'] = ElementController::unflattenarray($elArray[$key]['TCEforms']['config']);
            }

            /* ---------------------------------------------------------------------- */
            // this is too much different to preserve any previous information
            $reset = isset($elArray[$key]['tx_templavoila']['eType_before']) &&
                ($elArray[$key]['tx_templavoila']['eType_before'] !=
                    $elArray[$key]['tx_templavoila']['eType']);

            unset($elArray[$key]['tx_templavoila']['eType_before']);
            //    unset($elArray[$key]['tx_templavoila']['proc']);

            /* ---------------------------------------------------------------------- */
            if (is_array($elArray[$key]['tx_templavoila']['sample_data'])) {
                foreach ($elArray[$key]['tx_templavoila']['sample_data'] as $tmpKey => $tmpValue) {
                    $elArray[$key]['tx_templavoila']['sample_data'][$tmpKey] = htmlspecialchars($tmpValue);
                }
            } else {
                $elArray[$key]['tx_templavoila']['sample_data'] = htmlspecialchars($elArray[$key]['tx_templavoila']['sample_data']);
            }

            /* ---------------------------------------------------------------------- */
            if ($elArray[$key]['type'] === 'array') { // If array, then unset:
                unset($elArray[$key]['tx_templavoila']['sample_data']);
            } else { // Only non-arrays can have configuration (that is elements and attributes)

                // Getting some information about the HTML content (eg. images width/height if applicable)
                $contentInfo = $this->substEtypeWithRealStuff_contentInfo(trim($v_sub['cArray'][$key]));

                // Based on the eType (the preset type) we make configuration settings.
                // If a user function was registered, use that instead of our own handlers:
                if (isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Templavoila::EXTKEY]['cm1']['eTypesConfGen'][$elArray[$key]['tx_templavoila']['eType']])) {
                    $_params = [
                        'key' => $key,
                        'elArray' => &$elArray,
                        'contentInfo' => $contentInfo,
                    ];

                    $bef = $elArray[$key]['tx_templavoila']['TypoScript'];

                    GeneralUtility::callUserFunction($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Templavoila::EXTKEY]['cm1']['eTypesConfGen'][$elArray[$key]['tx_templavoila']['eType']], $_params, $this, '');

                    if (!$reset && trim($bef)) {
                        $elArray[$key]['tx_templavoila']['TypoScript'] = $bef;
                    }
                } else {
                    $eTypes = static::defaultEtypes();
                    $eType = $elArray[$key]['tx_templavoila']['eType'];
                    $elArray[$key]['TCEforms']['label'] = $elArray[$key]['tx_templavoila']['title'];
                    switch ($eType) {
                        case 'text':
                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['TCEforms']['config']['type'] !== 'text')) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                            }

                            if ($reset) {
                                $elArray[$key]['tx_templavoila']['proc']['HSC'] = 1;
                                unset($elArray[$key]['tx_templavoila']['proc']['int']);
                            }
                            break;
                        case 'rte':
                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['TCEforms']['config']['type'] !== 'text')) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                            }

                            /* preserve previous config, if explicitly set */
                            if (!$elArray[$key]['TCEforms']['defaultExtras']) {
                                $elArray[$key]['TCEforms']['defaultExtras'] = $eTypes['eType'][$eType]['TCEforms']['defaultExtras'];
                            }

                            if ($reset) {
                                unset($elArray[$key]['tx_templavoila']['proc']);
                            }

                            /* preserve previous config, if of the right kind */
                            if ($reset || !trim($elArray[$key]['tx_templavoila']['TypoScript'])) {
                                $elArray[$key]['tx_templavoila']['TypoScript'] = $eTypes['eType'][$eType]['Typoscript'];
                            }
                            break;
                        case 'image':
                        case 'imagefixed':
                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['TCEforms']['config']['type'] !== 'group')) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                            }

                            $maxW = $contentInfo['img']['width'] ? $contentInfo['img']['width'] : $eTypes['eType'][$eType]['maxWdefault'];
                            $maxH = $contentInfo['img']['height'] ? $contentInfo['img']['height'] : $eTypes['eType'][$eType]['maxHdefault'];
                            $typoScriptImageObject = ($elArray[$key]['type'] === 'attr') ? 'IMG_RESOURCE' : 'IMAGE';

                            if ($reset) {
                                unset($elArray[$key]['tx_templavoila']['proc']);
                            }

                            /* preserve previous config, if of the right kind */
                            if ($reset || !trim($elArray[$key]['tx_templavoila']['TypoScript'])) {
                                $elArray[$key]['tx_templavoila']['TypoScript'] = $eTypes['eType'][$eType]['Typoscript'];
                                $elArray[$key]['tx_templavoila']['TypoScript'] = strtr($elArray[$key]['tx_templavoila']['TypoScript'], [
                                    'IMAGE' => $typoScriptImageObject,
                                    'MAXW' => $maxW,
                                    'MAXH' => $maxH
                                ]);
                            }
                            break;
                        case 'file':
                            if ($reset || trim($elArray[$key]['tx_templavoila']['TypoScript']) === '') {
                                $elArray[$key]['tx_templavoila']['TypoScript'] = str_replace(
                                    '###fieldname###',
                                    $key,
                                    $eTypes['eType'][$eType]['Typoscript']
                                );
                            }

                            if ($reset) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                                $elArray[$key]['TCEforms']['config']['foreign_match_fields']['fieldname'] = $key;

                                $elArray[$key]['tx_templavoila']['proc']['int'] = 0;
                                $elArray[$key]['tx_templavoila']['proc']['HSC'] = 0;
                            }
                            break;
                        case 'link':
                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['TCEforms']['config']['type'] !== 'input')) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                            }

                            /* preserve previous config, if of the right kind */
                            if ($reset || !trim($elArray[$key]['tx_templavoila']['TypoScript'])) {
                                $elArray[$key]['tx_templavoila']['TypoScript'] = $eTypes['eType'][$eType]['Typoscript'];
                                if ($elArray[$key]['type'] === 'attr') {
                                    $elArray[$key]['tx_templavoila']['TypoScript'] .= chr(10) . '10.typolink.returnLast = url';
                                    /* preserve previous config, if explicitly set */
                                    if (!isset($elArray[$key]['TCEforms']['proc']['HSC'])) {
                                        $elArray[$key]['tx_templavoila']['proc']['HSC'] = 1;
                                    }
                                }
                            }
                            if ($reset) {
                                unset($elArray[$key]['tx_templavoila']['proc']['int']);
                            }
                            break;
                        case 'ce':
                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['TCEforms']['config']['type'] !== 'group')) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                            }

                            /* preserve previous config, if of the right kind */
                            if ($reset || !trim($elArray[$key]['tx_templavoila']['TypoScript'])) {
                                $elArray[$key]['tx_templavoila']['TypoScript'] = $eTypes['eType'][$eType]['Typoscript'];
                                if ($scope == 1) {
                                    $elArray[$key]['tx_templavoila']['TypoScript'] .= chr(10) . '10.wrap = <!--TYPO3SEARCH_begin--> | <!--TYPO3SEARCH_end-->';
                                }
                            }
                            if ($reset) {
                                unset($elArray[$key]['tx_templavoila']['proc']);
                                $elArray[$key]['tx_templavoila']['enableDragDrop'] = 1;
                            }
                            break;
                        case 'int':
                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['TCEforms']['config']['type'] !== 'input')) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                            }
                            if ($reset) {
                                $elArray[$key]['tx_templavoila']['proc']['int'] = 1;
                                unset($elArray[$key]['tx_templavoila']['proc']['HSC']);
                            }
                            break;
                        case 'select':
                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['TCEforms']['config']['type'] !== 'select')) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                            }
                            if ($reset) {
                                unset($elArray[$key]['tx_templavoila']['proc']);
                            }
                            break;
                        case 'check':
                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['TCEforms']['config']['type'] !== 'check')) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                            }
                            if ($reset) {
                                unset($elArray[$key]['tx_templavoila']['proc']);
                            }
                            break;
                        case 'input':
                        case 'input_h':
                        case 'input_g':
                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['TCEforms']['config']['type'] !== 'input')) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                            }

                            if ($reset || !trim($elArray[$key]['tx_templavoila']['TypoScript'])) {
                                $elArray[$key]['tx_templavoila']['TypoScript'] = $eTypes['eType'][$eType]['Typoscript'];
                            }

                            if ($eType === 'input_h') { // Text-Header
                                // Finding link-fields on same level and set the image to be linked by that TypoLink:
                                $elArrayKeys = array_keys($elArray);
                                foreach ($elArrayKeys as $theKey) {
                                    if ($elArray[$theKey]['tx_templavoila']['eType'] === 'link') {
                                        $elArray[$key]['tx_templavoila']['TypoScript'] .= chr(10) . '10.typolink.parameter.field = ' . $theKey;
                                    }
                                }
                                if ($reset) {
                                    $elArray[$key]['tx_templavoila']['proc']['HSC'] = 1;
                                }
                            } elseif ($eType === 'input_g') { // Graphical-Header

                                $maxW = $contentInfo['img']['width'] ? $contentInfo['img']['width'] : $eTypes['eType'][$eType]['maxWdefault'];
                                $maxH = $contentInfo['img']['height'] ? $contentInfo['img']['height'] : $eTypes['eType'][$eType]['maxHdefault'];

                                $elArray[$key]['tx_templavoila']['TypoScript'] = strtr($elArray[$key]['tx_templavoila']['TypoScript'], [
                                    'MAXW' => $maxW,
                                    'MAXH' => $maxH
                                ]);
                                if ($reset) {
                                    unset($elArray[$key]['tx_templavoila']['proc']['HSC']);
                                }
                            } else { // Normal output.
                                if ($reset) {
                                    $elArray[$key]['tx_templavoila']['proc']['HSC'] = 1;
                                }
                            }

                            if ($reset) {
                                unset($elArray[$key]['tx_templavoila']['proc']['int']);
                                unset($elArray[$key]['tx_templavoila']['TypoScript']);
                            }
                            break;
                        case 'TypoScriptObject':
                            unset(
                            $elArray[$key]['tx_templavoila']['TypoScript_constants'],
                            $elArray[$key]['tx_templavoila']['TypoScript'],
                            $elArray[$key]['TCEforms']['config']
                            );

                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['tx_templavoila']['TypoScriptObjPath'] == '')) {
                                $elArray[$key]['tx_templavoila']['TypoScriptObjPath'] =
                                    ($elArray[$key]['tx_templavoila']['eType_EXTRA']['objPath'] ?
                                        $elArray[$key]['tx_templavoila']['eType_EXTRA']['objPath'] :
                                        ($elArray[$key]['tx_templavoila']['TypoScriptObjPath'] ?
                                            $elArray[$key]['tx_templavoila']['TypoScriptObjPath'] : ''));
                            }

                            if ($reset) {
                                unset($elArray[$key]['tx_templavoila']['proc']);
                            }
                            break;
                        case 'none':
                            unset($elArray[$key]['TCEforms']['config']);
                            if ($reset) {
                                unset($elArray[$key]['tx_templavoila']['proc']);
                            }
                            break;
                        default:
                            /* preserve previous config, if of the right kind */
                            if ($reset || ($elArray[$key]['TCEforms']['config']['type'] !== 'text')) {
                                $elArray[$key]['TCEforms']['config'] = $eTypes['eType'][$eType]['TCEforms']['config'];
                            }
                            if ($reset || !trim($elArray[$key]['tx_templavoila']['TypoScript'])) {
                                $elArray[$key]['tx_templavoila']['TypoScript'] = $eTypes['eType'][$eType]['Typoscript'];
                            }
                            if ($reset) {
                                unset($elArray[$key]['tx_templavoila']['proc']['int']);
                                $elArray[$key]['tx_templavoila']['proc']['HSC'] = 1;
                            }
                            break;
                    }
                } // End switch else
                if ($elArray[$key]['tx_templavoila']['eType'] !== 'TypoScriptObject') {
                    if (isset($elArray[$key]['tx_templavoila']['TypoScriptObjPath'])) {
                        unset($elArray[$key]['tx_templavoila']['TypoScriptObjPath']);
                    }
                    if (isset($elArray[$key]['tx_templavoila']['eType_EXTRA']['objPath'])) {
                        unset($elArray[$key]['tx_templavoila']['eType_EXTRA']['objPath']);
                    }
                } elseif (isset($elArray[$key]['tx_templavoila']['eType_EXTRA']['objPath'])) {
                    unset($elArray[$key]['tx_templavoila']['eType_EXTRA']['objPath']);
                    if (count($elArray[$key]['tx_templavoila']['eType_EXTRA']) == 0) {
                        unset($elArray[$key]['tx_templavoila']['eType_EXTRA']);
                    }
                }

                // Setting TCEforms title for element if configuration is found:
                if (!is_array($elArray[$key]['TCEforms']['config'])) {
                    unset($elArray[$key]['TCEforms']);
                }
            }

            // Apart from converting eType to configuration, we also clean up other aspects:
            if (!$elArray[$key]['type']) {
                unset($elArray[$key]['type']);
            }
            if (!$elArray[$key]['section']) {
                unset($elArray[$key]['type'], $elArray[$key]['section']);
            } else {
                unset($elArray[$key]['tx_templavoila']['TypoScript_constants']);
                unset($elArray[$key]['tx_templavoila']['TypoScript']);
                unset($elArray[$key]['tx_templavoila']['proc']);
                unset($elArray[$key]['TCEforms']);
            }

            if (!$elArray[$key]['tx_templavoila']['description']) {
                unset($elArray[$key]['tx_templavoila']['description']);
            }
            if (!$elArray[$key]['tx_templavoila']['tags']) {
                unset($elArray[$key]['tx_templavoila']['tags']);
            }
            if (!$elArray[$key]['tx_templavoila']['TypoScript_constants']) {
                unset($elArray[$key]['tx_templavoila']['TypoScript_constants']);
            }
            if (!$elArray[$key]['TCEforms']['defaultExtras']) {
                unset($elArray[$key]['TCEforms']['defaultExtras']);
            }

            // Run this function recursively if needed:
            if (is_array($elArray[$key]['el'])) {
                $this->substEtypeWithRealStuff($elArray[$key]['el'], $v_sub['sub'][$key], $scope);
            }
        } // End loop
    }

    /**
     * Analyzes the input content for various stuff which can be used to generate the DS.
     * Basically this tries to intelligently guess some settings.
     *
     * @param string $content HTML Content string
     *
     * @return array Configuration
     *
     * @see substEtypeWithRealStuff()
     */
    public function substEtypeWithRealStuff_contentInfo($content)
    {
        if ($content) {
            if (strpos($content, '<img') === 0) {
                $attrib = GeneralUtility::get_tag_attributes($content);
                if ((!$attrib['width'] || !$attrib['height']) && $attrib['src']) {
                    $pathWithNoDots = GeneralUtility::resolveBackPath($attrib['src']);
                    $filePath = GeneralUtility::getFileAbsFileName($pathWithNoDots);
                    if ($filePath && @is_file($filePath)) {
                        $imgInfo = @getimagesize($filePath);

                        if (!$attrib['width']) {
                            $attrib['width'] = $imgInfo[0];
                        }
                        if (!$attrib['height']) {
                            $attrib['height'] = $imgInfo[1];
                        }
                    }
                }

                return ['img' => $attrib];
            }
        }

        return [];
    }

    /**
     * Defined eTypes for field creation
     *
     * @return array Array with default eTypes
     */
    public static function defaultEtypes()
    {
        // formFields: input, input_h, input_g, text, rte, link, int, image, imagefixed, select, ce
        // typoscriptElements: TypoScriptObject, none
        // misc: custom

        $eTypes = [
            'defaultTypes_formFields' => 'input,input_h,input_g,text,rte,link,int,file,image,imagefixed,select,check,ce',
            'defaultTypes_typoscriptElements' => 'TypoScriptObject,none',
            'defaultTypes_misc' => 'custom',
            'eType' => [],
        ];

        /*  Formfields */

        // input
        $eTypes['eType']['input']['TCEforms']['config'] = [
            'type' => 'input',
            'size' => '48',
            'eval' => 'trim',
        ];
        $eTypes['eType']['input']['label'] = static::getLanguageService()->getLL('mapPresets_plainInput');

        // input_h
        $eTypes['eType']['input_h']['TCEforms']['config'] = $eTypes['eType']['input']['TCEforms']['config'];
        $eTypes['eType']['input_h']['label'] = static::getLanguageService()->getLL('mapPresets_headerField');
        $eTypes['eType']['input_h']['Typoscript'] = '
10 = TEXT
10.current = 1';

        // input_g
        $eTypes['eType']['input_g']['TCEforms']['config'] = $eTypes['eType']['input']['TCEforms']['config'];
        $eTypes['eType']['input_g']['label'] = static::getLanguageService()->getLL('mapPresets_gHederField');
        $eTypes['eType']['input_g']['Typoscript'] = '
10 = IMAGE
10.file = GIFBUILDER
10.file {
XY = MAXW,MAXH
backColor = #999999
10 = TEXT
    10.text.current = 1
    10.text.case = upper
    10.fontColor = #FFCC00
    10.fontFile = typo3/sysext/install/Resources/Private/Font/vera.ttf
    10.niceText = 0
    10.offset = 0,14
    10.fontSize = 14
}';
        $eTypes['eType']['image']['maxWdefault'] = 160;
        $eTypes['eType']['image']['maxHdefault'] = 20;

        // text
        $eTypes['eType']['text']['TCEforms']['config'] = [
            'type' => 'text',
            'cols' => '48',
            'rows' => '5',
        ];
        $eTypes['eType']['text']['label'] = static::getLanguageService()->getLL('mapPresets_textarea');

        // rte
        $eTypes['eType']['rte']['TCEforms']['config'] = [
            'type' => 'text',
            'cols' => '48',
            'rows' => '5',
            'softref' => (isset($GLOBALS['TCA']['tt_content']['columns']['bodytext']['config']['softref']) ?
                    $GLOBALS['TCA']['tt_content']['columns']['bodytext']['config']['softref'] :
                    'typolink_tag,images,email[subst],url'),
        ];
        $eTypes['eType']['rte']['TCEforms']['defaultExtras'] = 'richtext:rte_transform[flag=rte_enabled|mode=ts_css]';
        $eTypes['eType']['rte']['label'] = static::getLanguageService()->getLL('mapPresets_rte');
        $eTypes['eType']['rte']['Typoscript'] = '
10 = TEXT
10.current = 1
10.parseFunc = < lib.parseFunc_RTE';

        // link
        $eTypes['eType']['link']['TCEforms']['config'] = [
            'type' => 'input',
            'size' => '15',
            'max' => '256',
            'checkbox' => '',
            'eval' => 'trim',
            'wizards' => [
                'link' => [
                    'type' => 'popup',
                    'title' => 'Link',
                    'icon' => 'actions-wizard-link',
                    'module' => [
                        'name' => 'wizard_element_browser',
                        'urlParameters' => [
                            'mode' => 'wizard'
                        ]
                    ],
                    'JSopenParams' => 'height=300,width=500,status=0,menubar=0,scrollbars=1'
                ]
            ]
        ];
        $eTypes['eType']['link']['label'] = static::getLanguageService()->getLL('mapPresets_linkField');
        $eTypes['eType']['link']['Typoscript'] = '
10 = TEXT
10.typolink.parameter.current = 1';

        // int
        $eTypes['eType']['int']['TCEforms']['config'] = [
            'type' => 'input',
            'size' => '4',
            'max' => '4',
            'eval' => 'int',
            'checkbox' => '0',
            'range' => [
                'upper' => '999',
                'lower' => '25'
            ],
            'default' => 0
        ];
        $eTypes['eType']['int']['label'] = static::getLanguageService()->getLL('mapPresets_integer');

        // file
        $eTypes['eType']['file']['label'] = static::getLanguageService()->getLL('mapPresets_file');
        $eTypes['eType']['file']['TCEforms']['config'] = ExtensionManagementUtility::getFileFieldTCAConfig('file');
        $eTypes['eType']['file']['Typoscript'] = '
10 = FILES
10 {
    references {
        table =
        uid =
        fieldName = ###fieldname###
    }

    renderObj = IMAGE
    renderObj {
        file.import.data = file:current:publicUrl
        altText.data = file:current:title
    }
}';

        // image
        $eTypes['eType']['image']['TCEforms']['config'] = [
            'type' => 'group',
            'internal_type' => 'file',
            'allowed' => 'gif,png,jpg,jpeg',
            'max_size' => '1000',
            'uploadfolder' => 'uploads/tx_templavoila',
            'show_thumbs' => '1',
            'size' => '1',
            'maxitems' => '1',
            'minitems' => '0'
        ];
        $eTypes['eType']['image']['label'] = static::getLanguageService()->getLL('mapPresets_image');
        $eTypes['eType']['image']['Typoscript'] = '
10 = IMAGE
10.file.import = uploads/tx_templavoila/
10.file.import.current = 1
10.file.import.listNum = 0
10.file.maxW = MAXW';
        $eTypes['eType']['image']['maxWdefault'] = 200;
        $eTypes['eType']['image']['maxHdefault'] = 150;

        // imagefixed
        $eTypes['eType']['imagefixed']['TCEforms']['config'] = $eTypes['eType']['image']['TCEforms']['config'];
        $eTypes['eType']['imagefixed']['label'] = static::getLanguageService()->getLL('mapPresets_imageFixed');
        $eTypes['eType']['imagefixed']['Typoscript'] = '
10 = IMAGE
10.file.XY = MAXW,MAXH
10.file.import = uploads/tx_templavoila/
10.file.import.current = 1
10.file.import.listNum = 0
10.file.maxW = MAXW
10.file.minW = MAXW
10.file.maxH = MAXH
10.file.minH = MAXH';
        $eTypes['eType']['imagefixed']['maxWdefault'] = 200;
        $eTypes['eType']['imagefixed']['maxHdefault'] = 150;

        // select
        $eTypes['eType']['select']['TCEforms']['config'] = [
            'type' => 'select',
            'items' => [
                ['', ''],
                ['Value 1', 'Value 1'],
                ['Value 2', 'Value 2'],
                ['Value 3', 'Value 3'],
            ],
            'default' => '0'
        ];
        $eTypes['eType']['select']['label'] = static::getLanguageService()->getLL('mapPresets_select');

        // check
        $eTypes['eType']['check']['TCEforms']['config'] = [
            'type' => 'check',
            'default' => 0,
        ];
        $eTypes['eType']['check']['label'] = static::getLanguageService()->getLL('mapPresets_check');

        // ce
        $eTypes['eType']['ce']['TCEforms']['config'] = [
            'type' => 'group',
            'internal_type' => 'db',
            'allowed' => 'tt_content',
            'size' => '5',
            'maxitems' => '200',
            'minitems' => '0',
            'multiple' => '1',
            'show_thumbs' => '1',
        ];
        $eTypes['eType']['ce']['label'] = static::getLanguageService()->getLL('mapPresets_ce');
        $eTypes['eType']['ce']['Typoscript'] = '
10= RECORDS
10.source.current=1
10.tables = tt_content';

        /* Typoscript Elements */
        $eTypes['eType']['TypoScriptObject']['label'] = static::getLanguageService()->getLL('mapPresets_TSobjectPath');
        $eTypes['eType']['none']['label'] = static::getLanguageService()->getLL('mapPresets_none');

        /* Misc */
        $eTypes['eType']['custom']['label'] = static::getLanguageService()->getLL('mapPresets_customTCA');

        // merge with tsConfig
        $config = static::getBackendUser()->getTSConfigProp('templavoila.eTypes');
        if (is_array($config)) {
            $config = GeneralUtility::removeDotsFromTS($config);
            ArrayUtility::mergeRecursiveWithOverrule($eTypes, $config);
        }

        // Hook todo: this is breaking, introduce alternative to this hook
//        if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][Templavoila::EXTKEY]['eTypes'])) {
//            $params = [
//                'eType' => &$eTypes['eType'],
//                'defaultTypes_formFields' => &$eTypes['defaultTypes_formFields'],
//                'defaultTypes_typoscriptElements' => &$eTypes['defaultTypes_typoscriptElements'],
//                'defaultTypes_misc' => &$eTypes['defaultTypes_misc']
//            ];
//            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][Templavoila::EXTKEY]['eTypes'] as $hook) {
//                GeneralUtility::callUserFunction($hook, $params, $this);
//            }
//        }

        return $eTypes;
    }
}
