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

namespace Schnitzler\Templavoila\Controller\Backend\PageModule\Renderer;

use Schnitzler\Templavoila\Container\ElementRendererContainer;
use Schnitzler\Templavoila\Controller\Backend\PageModule\MainController;
use Schnitzler\Templavoila\Controller\Backend\PageModule\Renderer\SheetRenderer\Column;
use Schnitzler\Templavoila\Controller\Backend\PageModule\Renderer\SheetRenderer\Sheet;
use Schnitzler\Templavoila\Domain\Repository\TemplateRepository;
use Schnitzler\Templavoila\Exception;
use Schnitzler\Templavoila\Helper\LanguageHelper;
use Schnitzler\Templavoila\Traits\BackendUser;
use Schnitzler\Templavoila\Traits\LanguageService;
use Schnitzler\Templavoila\Utility\PermissionUtility;
use Schnitzler\Templavoila\Utility\ReferenceIndexUtility;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class Schnitzler\Templavoila\Controller\Backend\PageModule\Renderer\SheetRenderer
 */
class SheetRenderer implements Renderable
{
    use BackendUser;
    use LanguageService;

    /**
     * @var MainController
     */
    private $controller;

    /**
     * @var array
     */
    private $contentTree;

    /**
     * @var array
     */
    private $renderPreviewObjects = [];

    /**
     * @var int
     */
    private $containedElementsPointer;

    /**
     * @var array
     */
    private $containedElements = [];

    /**
     * @var array
     */
    private $renderPreviewDataObjects = [];

    /**
     * @var int
     */
    private $previewTitleMaxLen = 50;

    /**
     * @var array
     */
    private $global_localization_status = [];

    /**
     * @var array
     */
    private $visibleContentHookObjects = [];

    /**
     * @var bool
     */
    private static $visibleContentHookObjectsPrepared = false;

    /**
     * @var TemplateRepository
     */
    private $templateRepository;

    /**
     * @param MainController $controller
     * @param array $contentTree
     */
    public function __construct(MainController $controller, array $contentTree)
    {
        $this->controller = $controller;
        $this->contentTree = $contentTree;
        $this->templateRepository = GeneralUtility::makeInstance(TemplateRepository::class);
    }

    /**
     * Renders the whole content element tree:
     *
     * > renderGrid
     *   > renderColumn
     *   > renderColumn
     *     > renderSheets
     *       > renderSheet
     *         > renderGrid
     *           > renderColumn
     *           > ...
     *   > renderColumn
     *   > ...
     *
     * @return string
     */
    public function render()
    {
        try {
            $sheet = new Sheet(
                new Column([], [], $this->controller->getCurrentLanguageKey()),
                $this->contentTree,
                'sDEF'
            );

            $pid = $sheet->getTable() === 'pages' ? $sheet->getUid() : $sheet->getPid();

            return $this->renderGrid($sheet, PermissionUtility::getCompiledPermissions($pid));
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Rendering the sheet tabs if applicable for the content Tree Array
     *
     * @param Column $column
     * @param array $contentTreeArr DataStructure info array (the whole tree)
     * @param string $languageKey Language key for the display
     * @param array $parentPointer Flexform Pointer to parent element
     * @param array $parentDsMeta Meta array from parent DS (passing information about parent containers localization mode)
     *
     * @return string HTML
     *
     * @see renderSheet()
     */
    public function renderSheets(Column $column, $contentTreeArr, $parentPointer = [], $parentDsMeta = [])
    {
        // If more than one sheet is available, render a dynamic sheet tab menu, otherwise just render the single sheet framework
        if (is_array($contentTreeArr['sub']) && (count($contentTreeArr['sub']) > 1 || !isset($contentTreeArr['sub']['sDEF']))) {
            $parts = [];
            foreach (array_keys($contentTreeArr['sub']) as $sheetKey) {
                $this->containedElementsPointer++;
                $this->containedElements[$this->containedElementsPointer] = 0;

                $frContent = $this->renderSheet(new Sheet($column, $contentTreeArr, $sheetKey), $parentPointer, $parentDsMeta);

                $parts[] = [
                    'label' => ($contentTreeArr['meta'][$sheetKey]['title'] ? $contentTreeArr['meta'][$sheetKey]['title'] : $sheetKey), //.' ['.$this->containedElements[$this->containedElementsPointer].']',
                    'description' => $contentTreeArr['meta'][$sheetKey]['description'],
                    'linkTitle' => $contentTreeArr['meta'][$sheetKey]['short'],
                    'content' => $frContent,
                ];

                $this->containedElementsPointer--;
            }

            return $this->controller->getModuleTemplate()->getDynamicTabMenu($parts, 'TEMPLAVOILA:pagemodule:' . $this->controller->getApiService()->flexform_getStringFromPointer($parentPointer));
        } else {
            return $this->renderSheet(new Sheet($column, $contentTreeArr, 'sDEF'), $parentPointer, $parentDsMeta);
        }
    }

    /**
     * @param Column $column
     * @param array $parentPointer
     * @param array $parentDsMeta
     */
    public function renderColumn(Column $column, array $parentPointer, array $parentDsMeta)
    {
        $content = '';

        $canEditContent = static::getBackendUser()->isPSet($this->controller->calcPerms, 'pages', 'editcontent');
        $canCreateNew = $canEditContent
            && !$column->hasMaxItemsReached();

        if ($canCreateNew && !PermissionUtility::isInTranslatorMode()) {
            $content .= '<div 
                class="t3-page-ce t3js-page-ce t3js-page-ce-sortable" 
                data-pointer-table="' . $parentPointer['table'] . '" 
                data-pointer-uid="' . (int)$parentPointer['uid'] . '" 
                data-pointer-sheet="' . $parentPointer['sheet'] . '" 
                data-pointer-sLang="' . $parentPointer['sLang'] . '" 
                data-pointer-field="' . $parentPointer['field'] . '" 
                data-pointer-vLang="' . $parentPointer['vLang'] . '" 
                data-pointer-position="' . (int)$parentPointer['position'] . '"
            >' . $this->controller->link_bottomControls($parentPointer, $canCreateNew) .
                (
                    $column->isDragAndDropAllowed()
                    ? '<div class="t3-page-ce-dropzone-available t3js-page-ce-dropzone-available"></div>'
                    : ''
                ) .
                '</div>';
            // todo: this belongs into the fluid template
        }

        foreach ($column as $position => $element) {
            if ((!$element['el']['isHidden'] || $this->controller->getSetting('tt_content_showHidden') !== '0') && $this->displayElement($element)) {

                // When "onlyLocalized" display mode is set and an alternative language gets displayed
                if ((int)$element['el']['sys_language_uid'] === 0
                    && $this->controller->getSetting('langDisplayMode') === 'onlyLocalized'
                    && $this->controller->getCurrentLanguageUid() > 0
                    && is_array($element['localizationInfo'][$this->controller->getCurrentLanguageUid()])
                    && ($localizedUid = $element['localizationInfo'][$this->controller->getCurrentLanguageUid()]['localization_uid'])
                ) {
                    $localizedRecord = BackendUtility::getRecordWSOL('tt_content', $localizedUid, '*');
                    $tree = $this->controller->getApiService()->getContentTree('tt_content', $localizedRecord);
                    $element = $tree['tree'];
                }
                $this->containedElements[$this->containedElementsPointer]++;

                // Modify the flexform pointer so it points to the position of the curren sub element:
                $parentPointer['position'] = $position;

                try {
                    $content .= $this->renderSheets($column, $element, $parentPointer, $parentDsMeta);
                } catch (Exception $e) {
                }
            } else {
                // Modify the flexform pointer so it points to the position of the curren sub element:
                $parentPointer['position'] = $position;
            }
        }

        return $content;
    }

    /**
     * @param Sheet $sheet
     */
    private function getTitleBarLeftIcons(Sheet $sheet)
    {
        $uid = $sheet->getUid();
        $pid = $sheet->getPid();
        $table = $sheet->getTable();

        $sheetData = $sheet->getRawData();

        if (isset($sheetData['el']['iconTag'])) {
            $recordIcon = $sheetData['el']['iconTag'];
        } else {
            // $recordIcon = '<img' . IconUtility::skinImg('', $sheetData['el']['icon'], '') . ' border="0" title="' . htmlspecialchars('[' . $table . ':' . $uid . ']') . '" alt="" />';
            $recordIcon = ''; // todo: fix me
        }

        $wrapClickMenuOnIcon = !PermissionUtility::isInTranslatorMode();
        $menuCommands = [];
        if (!static::getBackendUser()->isAdmin()) {
            $compiledPermissions = PermissionUtility::getCompiledPermissions($pid);
            $canCreateContent = static::getBackendUser()->isPSet($compiledPermissions, 'pages', 'new');
            $canEditContent = static::getBackendUser()->isPSet($compiledPermissions, 'pages', 'editcontent');

            if (!$canCreateContent) {
                $menuCommands[] = 'new';
            }

            if (!$canEditContent) {
                $menuCommands = array_merge(
                    $menuCommands,
                    [
                        'copy',
                        'cut',
                        'delete',
                        'edit',
                    ]
                );
            }

            if (count($menuCommands) === 0) {
                $wrapClickMenuOnIcon = false;
            }
        }

        $return = $recordIcon;
        if ($wrapClickMenuOnIcon) {
            $return = BackendUtility::wrapClickMenuOnIcon(
                $recordIcon,
                $table,
                $uid,
                true,
                '',
                implode(',', $menuCommands)
            );
        }

        $toolTip = BackendUtility::getRecordToolTip($sheetData['el'], $sheetData['el']['table']);
        $return .= $this->getRecordStatHookValue($table, $uid);
        return '<span  ' . $toolTip . '><span class="t3-page-ce-icon">' . $return . '</span></span>';
    }

    /**
     * @param array $data
     * @param bool $elementBelongsToCurrentPage
     * @param array $parentPointer
     */
    public function getTitleBarRightIcons(Sheet $sheet, $elementBelongsToCurrentPage, array $parentPointer = [])
    {
        $uid = $sheet->getUid();
        $pid = $sheet->getPid();
        $table = $sheet->getTable();

        $sheetData = $sheet->getRawData()['el'];

        $canEditElement = static::getBackendUser()->isPSet(PermissionUtility::getCompiledPermissions($pid), 'pages', 'editcontent');
        $canEditContent = static::getBackendUser()->isPSet($this->controller->calcPerms, 'pages', 'editcontent');
        $internalAccess = static::getBackendUser()->recordEditAccessInternals('tt_content', $sheet->getPreviewDataRow());
        $elementPointer = $table . ':' . $uid;
        $linkCopy = $this->controller->getClipboard()->element_getSelectButtons($parentPointer, 'copy,ref');

        if ($canEditContent) {
            $iconMakeLocal = $this->controller->getModuleTemplate()->getIconFactory()->getIcon('extensions-templavoila-makelocalcopy', Icon::SIZE_SMALL);
            $linkMakeLocal = !$elementBelongsToCurrentPage && !in_array('makeLocal', $this->controller->getBlindIcons()) ? $this->controller->link_makeLocal($iconMakeLocal, $parentPointer) : '';
            $linkCut = $this->controller->getClipboard()->element_getSelectButtons($parentPointer, 'cut');
            if ($this->controller->modTSconfig['properties']['enableDeleteIconForLocalElements'] < 2 ||
                !$elementBelongsToCurrentPage ||
                $this->controller->getElementRegister()[$uid] > 1
            ) {
                $linkUnlink = !in_array('unlink', $this->controller->getBlindIcons()) ? $this->controller->link_unlink($parentPointer) : '';
            } else {
                $linkUnlink = '';
            }
        } else {
            $linkMakeLocal = $linkCut = $linkUnlink = '';
        }

        if ($canEditElement && $internalAccess) {
            if (($elementBelongsToCurrentPage || $this->controller->modTSconfig['properties']['enableEditIconForRefElements']) && !in_array('edit', $this->controller->getBlindIcons())) {
                $iconEdit = $this->controller->getModuleTemplate()->getIconFactory()->getIcon('actions-document-open', Icon::SIZE_SMALL);
                $linkEdit = $this->controller->link_edit($iconEdit, $table, $uid, false, $pid, 'btn btn-default');
            } else {
                $linkEdit = '';
            }
            $linkHide = !in_array('hide', $this->controller->getBlindIcons()) ? $this->controller->icon_hide($sheetData) : '';

            if ($canEditContent && $this->controller->modTSconfig['properties']['enableDeleteIconForLocalElements'] && $elementBelongsToCurrentPage) {
                $hasForeignReferences = ReferenceIndexUtility::hasElementForeignReferences($sheetData, $pid);
                $linkDelete = !in_array('delete', $this->controller->getBlindIcons()) ? $this->controller->link_unlink($parentPointer, true, $hasForeignReferences) : '';
            } else {
                $linkDelete = '';
            }
        } else {
            $linkDelete = $linkEdit = $linkHide = '';
        }

        return $linkEdit . $linkHide . $linkCopy . $linkCut . $linkMakeLocal . $linkUnlink . $linkDelete;
    }

    /**
     * Renders the display framework of a single sheet. Calls itself recursively
     *
     * @param Sheet $sheet
     * @param array $contentTreeArr DataStructure info array (the whole tree)
     * @param array $parentPointer Flexform pointer to parent element
     * @param array $parentDsMeta Meta array from parent DS (passing information about parent containers localization mode)
     *
     * @return string HTML
     *
     * @see renderSheet()
     */
    public function renderSheet(Sheet $sheet, $parentPointer = [], $parentDsMeta = [])
    {
        $pid = $sheet->getTable() === 'pages' ? $sheet->getUid() : $sheet->getPid();
        $elementBelongsToCurrentPage = $sheet->belongsToPage($this->controller->getPid());

        if (!$elementBelongsToCurrentPage && $sheet->getOriginalUid() > 0) {
            $record = BackendUtility::getMovePlaceholder('tt_content', $sheet->getUid());
            if (is_array($record) && (int)$record['t3ver_move_id'] === $sheet->getUid()) {
                $elementBelongsToCurrentPage = $this->controller->getPid() === (int)$record['pid'];
                $pid = (int)$record['pid'];
            }
        }

        $this->controller->setCurrentElementParentPointer($parentPointer);

        // Create warning messages if neccessary:
        $warnings = '';

        if (!$this->controller->modTSconfig['properties']['disableReferencedElementNotification'] && !$elementBelongsToCurrentPage) {
            $warnings .= $this->controller->getModuleTemplate()->icons(ModuleTemplate::STATUS_ICON_NOTIFICATION) . ' <em>' . htmlspecialchars(sprintf(static::getLanguageService()->getLL('info_elementfromotherpage'), $sheet->getUid(), $sheet->getPid())) . '</em><br />';
        }

        if (!$this->controller->modTSconfig['properties']['disableElementMoreThanOnceWarning'] && $this->controller->getElementRegister()[$sheet->getUid()] > 1 && $this->controller->getLanguageParadigm() !== 'free') {
            $warnings .= $this->controller->getModuleTemplate()->icons(ModuleTemplate::STATUS_ICON_WARNING) . ' <em>' . htmlspecialchars(sprintf(static::getLanguageService()->getLL('warning_elementusedmorethanonce'), $this->controller->getElementRegister()[$sheet->getUid()], $sheet->getUid())) . '</em><br />';
        }

        // Displaying warning for container content (in default sheet - a limitation) elements if localization is enabled:
        if (
            !$this->controller->modTSconfig['properties']['disableContainerElementLocalizationWarning']
            && $this->controller->getLanguageParadigm() !== 'free'
            && $sheet->isContainerElement()
            && $sheet->isFlexibleContentElement()
            && $sheet->isLocalizable()
        ) {
            if ($sheet->hasLocalizableChildren()
                && !$this->controller->modTSconfig['properties']['disableContainerElementLocalizationWarning_warningOnly']
            ) {
                $warnings .= $this->controller->getModuleTemplate()->icons(ModuleTemplate::STATUS_ICON_WARNING) . ' <em>' . static::getLanguageService()->getLL('warning_containerInheritance') . '</em><br />';
            } else {
                $warnings .= $this->controller->getModuleTemplate()->icons(ModuleTemplate::STATUS_ICON_ERROR) . ' <em>' . static::getLanguageService()->getLL('warning_containerSeparate') . '</em><br />';
            }
        }

        // Preview made:
        $previewContent = $this->render_previewData($sheet);

        // Wrap workspace notification colors:
        if ($sheet->getOriginalUid() > 0) {
            $previewContent = '<div class="ver-element">' . ($previewContent ? $previewContent : '<em>[New version]</em>') . '</div>';
        }

        $canEditContent = static::getBackendUser()->isPSet($this->controller->calcPerms, 'pages', 'editcontent');
        $canCreateNew = $canEditContent
            && !$sheet->getColumn()->hasMaxItemsReached();

        $canDragDrop = $canEditContent
            && $sheet->getColumn()->isDragAndDropAllowed()
            && (string)$this->controller->modTSconfig['properties']['enableDragDrop'] !== '0';

        $contentElementView = $this->controller->getStandaloneView('Backend/PageModule/Renderer/SheetRenderer/ContentElement');
        $contentElementView->assignMultiple([
            'languageLabel' => LanguageHelper::getLanguageTitle($this->controller->getId(), $sheet->getSysLanguageUid()),
            'languageFlagIconIdentifier' => LanguageHelper::getLanguageFlagIconIdentifier($this->controller->getId(), $sheet->getSysLanguageUid()),
            'isInTranslatorMode' => PermissionUtility::isInTranslatorMode(),
            'hash' => md5($this->controller->getApiService()->flexform_getStringFromPointer($this->controller->getCurrentElementParentPointer()) . $sheet->getUid()),
            'title' => $sheet->getTitle(),
            'titleBarLeftButtons' => $this->getTitleBarLeftIcons($sheet),
            'titleBarRightButtons' => $this->getTitleBarRightIcons($sheet, $elementBelongsToCurrentPage, $parentPointer),
            'warnings' => $warnings,
            'content' => $this->renderGrid($sheet, PermissionUtility::getCompiledPermissions($pid)),
            'previewContent' => $previewContent,
            'localizationInfoTable' => $this->render_localizationInfoTable($sheet, $parentPointer, $parentDsMeta),
            'isSortable' => !PermissionUtility::isInTranslatorMode() && $canDragDrop,
            'bottomControls' => $canCreateNew && !PermissionUtility::isInTranslatorMode() ? $this->controller->link_bottomControls($parentPointer, $canCreateNew) : '',
            'pointer' => $parentPointer,
            'contentType' => $sheet->getContentType(),
            'isFlexibleContentElement' => $sheet->isFlexibleContentElement(),
            'dataStructureUid' => $sheet->isFlexibleContentElement() && isset($sheet->getPreviewDataRow()['tx_templavoila_ds'])
                ? $sheet->getPreviewDataRow()['tx_templavoila_ds']
                : 0,
            'uid' => $sheet->getUid()
        ]);

        return $contentElementView->render();
    }

    /**
     * Renders the sub elements of the given elementContentTree array. This function basically
     * renders the "new" and "paste" buttons for the parent element and then traverses through
     * the sub elements (if any exist). The sub element's (preview-) content will be rendered
     * by render_framework_singleSheet().
     *
     * Calls render_framework_allSheets() and therefore generates a recursion.
     *
     * @param Sheet $sheet
     * @param int $calcPerms Defined the access rights for the enclosing parent
     *
     * @throws RuntimeException
     *
     * @return string HTML output (a table) of the sub elements and some "insert new" and "paste" buttons
     *
     * @see renderSheets(), renderSheet()
     */
    public function renderGrid(Sheet $sheet, $calcPerms = 0)
    {
        $elementContentTreeArr = $sheet->getRawData();

        $beTemplate = '';

        $canEditContent = static::getBackendUser()->isPSet($calcPerms, 'pages', 'editcontent');

        // Define l/v keys for current language:
        $langChildren = $sheet->hasLocalizableChildren();
        $langDisable = !$sheet->isLocalizable();

        $lKey = $sheet->getLanguageKey();
        $vKey = $sheet->getValueKey();
        if ($sheet->getTable() === 'pages' && !$langDisable && $langChildren) {
            if ($this->disablePageStructureInheritance($elementContentTreeArr, $sheet->getSheetKey(), $lKey, $vKey)) {
                $lKey = $sheet->getLanguageKey(false);
                $vKey = $sheet->getValueKey(false);
            } else {
                if (!static::getBackendUser()->isAdmin()) {
                    $this->controller->getModuleTemplate()->addFlashMessage(
                        static::getLanguageService()->getLL('page_structure_inherited_detail'),
                        static::getLanguageService()->getLL('page_structure_inherited'),
                        FlashMessage::INFO
                    );
                }
            }
        }

        $sheets = $sheet->getSheets($sheet->getSheetKey());
        if (count($sheets) === 0
            || !isset($sheets[$lKey])
            || !is_array($sheets[$lKey])
        ) {
            return '';
        }

        $templateUid = $sheet->getTemplateUid() > 0
            ? $sheet->getTemplateUid()
            : $this->controller->getApiService()->getContentTree_fetchPageTemplateObject($this->controller->getRecord())['uid'];
        $template = $this->templateRepository->getTemplateByUid($templateUid);

        $columns = [];
        $columnsCount = 0;

        foreach ($sheets[$lKey] as $fieldID => $fieldValuesContent) {
            $previewDataSheets = $sheet->getPreviewDataSheets($sheet->getSheetKey());
            try {
                $newValue = $template->getLocalDataprotValueByXpath('//' . $fieldID . '/tx_templavoila/preview');
                $previewDataSheets[$fieldID]['tx_templavoila']['preview'] = $newValue;
            } catch (\Exception $e) {
                // ignore
            }

            if (!is_array($fieldValuesContent[$vKey])) {
                continue;
            }

            if (isset($fieldValuesContent['tx_templavoila']['preview'])
                && $fieldValuesContent['tx_templavoila']['preview'] === 'disable') {
                continue;
            }

            if (($previewDataSheets[$fieldID]['isMapped']
                || $previewDataSheets[$fieldID]['type'] === 'no_map'
                ) === false) {
                continue;
            }

            $column = new SheetRenderer\Column(
                $fieldValuesContent[$vKey],
                $previewDataSheets[$fieldID],
                $sheet->getColumn()->getLanguageKey()
            );

            // Create flexform pointer pointing to "before the first sub element":
            $subElementPointer = [
                'table' => $sheet->getTable(),
                'uid' => $sheet->getUid(),
                'sheet' => $sheet->getSheetKey(),
                'sLang' => $lKey,
                'field' => $fieldID,
                'vLang' => $vKey,
                'position' => 0
            ];

            $columns[$fieldID] = [
                'title' => static::getLanguageService()->sL($column->getTitle()),
                'content' => $this->renderColumn(
                    $column,
                    $subElementPointer,
                    is_array($elementContentTreeArr['ds_meta']) ? $elementContentTreeArr['ds_meta'] : []
                )
            ];
            $columnsCount++;
        }

        foreach ($columns as &$column) {
            $column['relativeWidth'] = 100 / $columnsCount;
        }
        unset($column);

        $templateName = $template->hasBackendGridTemplateName() ? $template->getBackendGridTemplateName() : 'Backend/Grid/Default';
        $contentElementView = $this->controller->getStandaloneView($templateName);
        $contentElementView->assign('columns', $columns);
        $contentElementView->assign('table', $sheet->getTable());
        $contentElementView->assign('uid', $sheet->getUid());

        return $contentElementView->render();
    }

    /**
     * Renders a little table containing previews of translated version of the current content element.
     *
     * @param Sheet $sheet
     * @param array $parentPointer Flexform pointer pointing to the current element (from the parent's perspective)
     * @param array $parentDsMeta Meta array from parent DS (passing information about parent containers localization mode)
     *
     * @return string HTML
     *
     * @see renderSheet()
     */
    public function render_localizationInfoTable(Sheet $sheet, $parentPointer, $parentDsMeta = [])
    {
        $localizationInfo = $sheet->getRawData()['localizationInfo'];

        // LOCALIZATION information for content elements (non Flexible Content Elements)
        $output = '';
        if ($sheet->getTable() === 'tt_content' && $sheet->getSysLanguageUid() <= 0) {

            // Traverse the available languages of the page (not default and [All])
            $tRows = [];
            foreach (LanguageHelper::getPageLanguages($this->controller->getId()) as $sys_language_uid => $sLInfo) {
                if (($this->controller->getCurrentLanguageUid() !== $sys_language_uid) && $this->controller->getSetting('langDisplayMode') !== 'default') {
                    continue;
                }
                if ($sys_language_uid > 0) {
                    $l10nInfo = '';
                    $flagLink_begin = $flagLink_end = '';

                    switch ((string) $localizationInfo[$sys_language_uid]['mode']) {
                        case 'exists':
                            $olrow = BackendUtility::getRecordWSOL('tt_content', $localizationInfo[$sys_language_uid]['localization_uid']);

                            $localizedRecordInfo = [
                                'uid' => $olrow['uid'],
                                'row' => $olrow,
                                'content' => $this->render_previewContent($olrow)
                            ];

                            // Put together the records icon including content sensitive menu link wrapped around it:
                            $recordIcon_l10n = $this->controller->getModuleTemplate()->getIconFactory()->getIconForRecord('tt_content', $localizedRecordInfo['row'], Icon::SIZE_SMALL);
                            if (!PermissionUtility::isInTranslatorMode()) {
                                $recordIcon_l10n = BackendUtility::wrapClickMenuOnIcon($recordIcon_l10n, 'tt_content', $localizedRecordInfo['uid'], true, '', 'new,copy,cut,pasteinto,pasteafter');
                            }
                            $l10nInfo =
                                '<a name="c' . md5($this->controller->getApiService()->flexform_getStringFromPointer($this->controller->getCurrentElementParentPointer()) . $localizedRecordInfo['row']['uid']) . '"></a>' .
                                '<a name="c' . md5($this->controller->getApiService()->flexform_getStringFromPointer($this->controller->getCurrentElementParentPointer()) . $localizedRecordInfo['row']['l18n_parent'] . $localizedRecordInfo['row']['sys_language_uid']) . '"></a>' .
                                $this->getRecordStatHookValue('tt_content', $localizedRecordInfo['row']['uid']) .
                                $recordIcon_l10n .
                                htmlspecialchars(GeneralUtility::fixed_lgd_cs(strip_tags(BackendUtility::getRecordTitle('tt_content', $localizedRecordInfo['row'])), $this->previewTitleMaxLen));

                            $l10nInfo .= '<br/>' . $localizedRecordInfo['content'];

                            list($flagLink_begin, $flagLink_end) = explode('|*|', $this->controller->link_edit('|*|', 'tt_content', $localizedRecordInfo['uid'], true));
                            if (PermissionUtility::isInTranslatorMode()) {
                                $l10nInfo .= '<br/>' . $flagLink_begin . '<em>' . static::getLanguageService()->getLL('clickToEditTranslation') . '</em>' . $flagLink_end;
                            }

                            // Wrap workspace notification colors:
                            if ($olrow['_ORIG_uid']) {
                                $l10nInfo = '<div class="ver-element">' . $l10nInfo . '</div>';
                            }

                            $this->global_localization_status[$sys_language_uid][] = [
                                'status' => 'exist',
                                'parent_uid' => $sheet->getUid(),
                                'localized_uid' => $localizedRecordInfo['row']['uid'],
                                'sys_language' => $sheet->getSysLanguageUid()
                            ];
                            break;
                        case 'localize':

                            if (isset($this->controller->modTSconfig['properties']['hideCopyForTranslation'])) {
                                $showLocalizationLinks = 0;
                            } else {
                                if ($this->controller->getLanguageParadigm() === 'free') {
                                    $showLocalizationLinks = !$parentDsMeta['langDisable']; // For this paradigm, show localization links only if localization is enabled for DS (regardless of Inheritance and Separate)
                                } else {
                                    $showLocalizationLinks = ($parentDsMeta['langDisable'] || $parentDsMeta['langChildren']); // Adding $parentDsMeta['langDisable'] here means that the "Create a copy for translation" link is shown only if the parent container element has localization mode set to "Disabled" or "Inheritance" - and not "Separate"!
                                }
                            }

                            // Assuming that only elements which have the default language set are candidates for localization. In case the language is [ALL] then it is assumed that the element should stay "international".
                            if ($sheet->getSysLanguageUid() === 0 && $showLocalizationLinks) {

                                // Copy for language:
                                if ($this->controller->getLanguageParadigm() === 'free') {
                                    $sourcePointerString = $this->controller->getApiService()->flexform_getStringFromPointer($parentPointer);

                                    $href = BackendUtility::getModuleUrl(
                                        'tv_mod_pagemodule_contentcontroller',
                                        [
                                            'action' => 'localize',
                                            'returnUrl' => $this->controller->getReturnUrl(),
                                            'record' => $sourcePointerString,
                                            'language' => strtoupper($sLInfo['language_isocode'])
                                        ]
                                    );
                                } else {
                                    $params = '&cmd[tt_content][' . $sheet->getUid() . '][localize]=' . $sys_language_uid;
                                    $href = BackendUtility::getLinkToDataHandlerAction($params, GeneralUtility::getIndpEnv('REQUEST_URI') . '#c' . md5($this->controller->getApiService()->flexform_getStringFromPointer($parentPointer) . $sheet->getUid() . $sys_language_uid)) . "'; return false;";
                                }

                                $linkLabel = static::getLanguageService()->getLL('createcopyfortranslation', true) . ' (' . htmlspecialchars($sLInfo['title']) . ')';

//                                $l10nInfo = '<a class="tpm-clipCopyTranslation" href="#" onclick="' . htmlspecialchars($onClick) . '">' . $localizeIcon . '</a>';
                                $l10nInfo .= ' <em><a href="' . $href . '">' . $linkLabel . '</a></em>';
                                $flagLink_begin = '<a href="' . $href . '">';
                                $flagLink_end = '</a>';

                                $this->global_localization_status[$sys_language_uid][] = [
                                    'status' => 'localize',
                                    'parent_uid' => $sheet->getUid(),
                                    'sys_language' => $sheet->getSysLanguageUid()
                                ];
                            }
                            break;
                        case 'localizedFlexform':
                            // Here we want to show the "Localized FlexForm" information (and link to edit record) _only_ if there are other fields than group-fields for content elements: It only makes sense for a translator to deal with the record if that is the case.
                            // Change of strategy (27/11): Because there does not have to be content fields; could be in sections or arrays and if thats the case you still want to localize them! There has to be another way...
                            // if (count($contentTreeArr['contentFields']['sDEF']))    {
                            list($flagLink_begin, $flagLink_end) = explode('|*|', $this->controller->link_edit('|*|', 'tt_content', $sheet->getUid(), true));
                            $l10nInfo = $flagLink_begin . '<em>[' . static::getLanguageService()->getLL('performTranslation') . ']</em>' . $flagLink_end;
                            $this->global_localization_status[$sys_language_uid][] = [
                                'status' => 'flex',
                                'parent_uid' => $sheet->getUid(),
                                'sys_language' => $sheet->getSysLanguageUid()
                            ];
                            // }
                            break;
                    }

                    if ($l10nInfo && static::getBackendUser()->checkLanguageAccess($sys_language_uid)) {
                        $tRows[] = '
                            <tr class="bgColor4">
                                <td width="1%">' . $flagLink_begin . $this->controller->getModuleTemplate()->getIconFactory()->getIcon($sLInfo['flagIconIdentifier'], Icon::SIZE_SMALL) . $flagLink_end . '</td>
                                <td width="99%">' . $l10nInfo . '</td>
                            </tr>';
                    }
                }
            }

            $output = count($tRows) ? '
                <table border="0" cellpadding="0" cellspacing="1" width="100%" class="lrPadding tpm-localisation-info-table">
                    <tr class="bgColor4-20">
                        <td colspan="2">' . static::getLanguageService()->getLL('element_localizations', true) . ':</td>
                    </tr>
                    ' . implode('', $tRows) . '
                </table>
            ' : '';
        }

        return $output;
    }

    /**
     * Rendering the preview of content for Page module.
     *
     * @param Sheet $sheet
     *
     * @return string HTML content
     */
    public function render_previewData(Sheet $sheet)
    {
        if ($sheet->isPreviewDisabled()) {
            return '&nbsp;';
        }

        $row = $sheet->getPreviewDataRow();

        // @todo: Fix $this->currentElementBelongsToCurrentPage
        $this->currentElementBelongsToCurrentPage = $sheet->getTable() === 'pages' || $sheet->getPid() === $this->controller->getPid();

        // General preview of the row:
        $previewContent = count($row) > 0 && $sheet->getTable() === 'tt_content' ? $this->render_previewContent($row) : '';

        // Define l/v keys for current language:
        $lKey = $sheet->getLanguageKey();
        $vKey = $sheet->getValueKey();

        foreach ($sheet->getPreviewDataSheets($sheet->getSheetKey()) as $fieldData) {
            if (isset($fieldData['tx_templavoila']['preview']) && $fieldData['tx_templavoila']['preview'] === 'disable') {
                continue;
            }

            $TCEformsConfiguration = $fieldData['TCEforms']['config'];
            $TCEformsLabel = $this->localizedFFLabel($fieldData['TCEforms']['label'], true); // title for non-section elements

            if ($fieldData['type'] === 'array') { // Making preview for array/section parts of a FlexForm structure:;
                if (is_array($fieldData['childElements'][$lKey])) {
                    $subData = $this->render_previewSubData($fieldData['childElements'][$lKey], $sheet->getTable(), $row['uid'], $vKey);
                    $previewContent .= $this->controller->link_edit($subData, $sheet->getTable(), $row['uid']);
                } else {
                    // no child elements found here
                }
            } else { // Preview of flexform fields on top-level:
                $fieldValue = $fieldData['data'][$lKey][$vKey];

                if ($TCEformsConfiguration['type'] === 'group') {
                    if ($TCEformsConfiguration['internal_type'] === 'file') {
                        // Render preview for images:
                        $thumbnail = BackendUtility::thumbCode(['dummyFieldName' => $fieldValue], '', 'dummyFieldName', '', '', $TCEformsConfiguration['uploadfolder']);
                        $previewContent .= '<strong>' . $TCEformsLabel . '</strong> ' . $thumbnail . '<br />';
                    } elseif ($TCEformsConfiguration['internal_type'] === 'db') {
                        if (!$this->renderPreviewDataObjects) {
                            $this->renderPreviewDataObjects = $this->controller->hooks_prepareObjectsArray('renderPreviewDataClass');
                        }
                        if (isset($this->renderPreviewDataObjects[$TCEformsConfiguration['allowed']])
                            && method_exists($this->renderPreviewDataObjects[$TCEformsConfiguration['allowed']], 'render_previewData_typeDb')
                        ) {
                            $previewContent .= $this->renderPreviewDataObjects[$TCEformsConfiguration['allowed']]->render_previewData_typeDb($fieldValue, $fieldData, $row['uid'], $sheet->getTable(), $this);
                        }
                    }
                } else {
                    if ($TCEformsConfiguration['type'] !== '') {
                        // Render for everything else:
                        $previewContent .= '<strong>' . $TCEformsLabel . '</strong> ' . (!$fieldValue ? '' : $this->controller->link_edit(htmlspecialchars(GeneralUtility::fixed_lgd_cs(strip_tags($fieldValue), 200)), $sheet->getTable(), $row['uid'])) . '<br />';
                    }
                }
            }
        }

        return $previewContent;
    }

    /**
     * Merge the datastructure and the related content into a proper tree-structure
     *
     * @param array $fieldData
     * @param string $table
     * @param int $uid
     * @param string $vKey
     *
     * @return string
     */
    public function render_previewSubData($fieldData, $table, $uid, $vKey)
    {
        if (!is_array($fieldData)) {
            return '';
        }

        $result = '';
        foreach ($fieldData as $fieldValue) {
            if (isset($fieldValue['config']['tx_templavoila']['preview']) && $fieldValue['config']['tx_templavoila']['preview'] === 'disable') {
                continue;
            }

            if ($fieldValue['config']['type'] === 'array') {
                if (isset($fieldValue['data']['el'])) {
                    if ($fieldValue['config']['section']) {
                        $result .= '<strong>';
                        $label = ($fieldValue['config']['TCEforms']['label'] ? $fieldValue['config']['TCEforms']['label'] : $fieldValue['config']['tx_templavoila']['title']);
                        $result .= $this->localizedFFLabel($label, true);
                        $result .= '</strong>';
                        $result .= '<ul>';
                        foreach ($fieldValue['data']['el'] as $sub) {
                            $data = $this->render_previewSubData($sub, $table, $uid, $vKey);
                            if ($data) {
                                $result .= '<li>' . $data . '</li>';
                            }
                        }
                        $result .= '</ul>';
                    } else {
                        $result .= $this->render_previewSubData($fieldValue['data']['el'], $table, $uid, $vKey);
                    }
                }
            } else {
                $label = $data = '';
                if (isset($fieldValue['config']['TCEforms']['config']['type']) && $fieldValue['config']['TCEforms']['config']['type'] === 'group') {
                    if ($fieldValue['config']['TCEforms']['config']['internal_type'] === 'file') {
                        // Render preview for images:
                        $thumbnail = BackendUtility::thumbCode(['dummyFieldName' => $fieldValue['data'][$vKey]], '', 'dummyFieldName', '', '', $fieldValue['config']['TCEforms']['config']['uploadfolder']);
                        if (isset($fieldValue['config']['TCEforms']['label'])) {
                            $label = $this->localizedFFLabel($fieldValue['config']['TCEforms']['label'], true);
                        }
                        $data = $thumbnail;
                    }
                } else {
                    if (isset($fieldValue['config']['TCEforms']['config']['type']) && $fieldValue['config']['TCEforms']['config']['type'] !== '') {
                        // Render for everything else:
                        if (isset($fieldValue['config']['TCEforms']['label'])) {
                            $label = $this->localizedFFLabel($fieldValue['config']['TCEforms']['label'], true);
                        }
                        $data = (!$fieldValue['data'][$vKey] ? '' : $this->controller->link_edit(htmlspecialchars(GeneralUtility::fixed_lgd_cs(strip_tags($fieldValue['data'][$vKey]), 200)), $table, $uid));
                    } else {
                        // @todo no idea what we should to here
                    }
                }

                if ($label && $data) {
                    $result .= '<strong>' . $label . '</strong> ' . $data . '<br />';
                }
            }
        }

        return $result;
    }

    /**
     * Returns an HTMLized preview of a certain content element. If you'd like to register a new content type, you can easily use the hook
     * provided at the beginning of the function.
     *
     * @param array $row The row of tt_content containing the content element record.
     *
     * @return string HTML preview content
     *
     * @see getContentTree(), render_localizationInfoTable()
     */
    public function render_previewContent($row)
    {
        $output = '';
        $hookObjectsArr = $this->controller->hooks_prepareObjectsArray('renderPreviewContentClass');
        $alreadyRendered = false;
        // Hook: renderPreviewContent_preProcess. Set 'alreadyRendered' to true if you provided a preview content for the current cType !
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'renderPreviewContent_preProcess')) {
                $output .= $hookObj->renderPreviewContent_preProcess($row, 'tt_content', $alreadyRendered, $this);
            }
        }

        if (!$alreadyRendered) {
            if (!$this->renderPreviewObjects) {
                $this->renderPreviewObjects = $this->controller->hooks_prepareObjectsArray('renderPreviewContent');
            }

            try {
                $elementRendererContainer = ElementRendererContainer::getInstance();
                $renderer = $elementRendererContainer->get($row['CType']);

                if ($renderer instanceof AbstractContentElementRenderer) {
                    $renderer->setRow($row);
                    $renderer->setTable('tt_content');
                    $renderer->setOutput($output);
                    $renderer->setAlreadyRendered($alreadyRendered);
                    $renderer->setRef($this->controller);
                    $output .= $renderer->render();
                } else {
                    GeneralUtility::deprecationLog(sprintf(
                        'Hook "%s::%s" is deprecated from 7.6.0 on, will be removed in 8.0.0',
                        get_class($renderer),
                        'render_previewContent'
                    ));
                    $output .= call_user_func_array([$renderer, 'render_previewContent'], [$row, 'tt_content', $output, $alreadyRendered, &$this->controller]);
                }
            } catch (\Exception $e) {
                // nothing is left to render the preview - happens if someone broke the configuration
            }
        }

        return $output;
    }

    /**
     * @param string $table
     * @param int $id
     *
     * @return string
     */
    public function getRecordStatHookValue($table, $id)
    {
        // Call stats information hook
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['GLOBAL']['recStatInfoHooks'])) {
            $stat = '';
            $_params = [$table, $id];
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['GLOBAL']['recStatInfoHooks'] as $_funcRef) {
                $stat .= GeneralUtility::callUserFunction($_funcRef, $_params, $this);
            }

            return $stat;
        }

        return '';
    }

    /**
     * Returns label, localized and converted to current charset. Label must be from FlexForm (= always in UTF-8).
     *
     * @param string $label Label
     * @param bool $hsc <code>true</code> if HSC required
     *
     * @return string Converted label
     */
    public function localizedFFLabel($label, $hsc)
    {
        if (substr($label, 0, 4) === 'LLL:') {
            $label = static::getLanguageService()->sL($label);
        }
        $result = htmlspecialchars($label, $hsc);

        return $result;
    }

    /**
     * Defines if an element is to be displayed in the TV page module (could be filtered out by language settings)
     *
     * @param array $subElementArr Sub element array
     *
     * @return bool Display or not
     */
    public function displayElement($subElementArr)
    {
        // Don't display when "selectedLanguage" is choosen
        $displayElement = $this->controller->getSetting('langDisplayMode') === 'selectedLanguage' ? 0 : 1;
        // Set to true when current language is not an alteranative (in this case display all elements)
        $displayElement |= ($this->controller->getCurrentLanguageUid() <= 0);
        // When language of CE is ALL or default display it.
        $displayElement |= ($subElementArr['el']['sys_language_uid'] <= 0);
        // Display elements which have their language set to the currently displayed language.
        $displayElement |= ($this->controller->getCurrentLanguageUid() === (int)$subElementArr['el']['sys_language_uid']);

        if (!static::$visibleContentHookObjectsPrepared) {
            $this->visibleContentHookObjects = $this->controller->hooks_prepareObjectsArray('visibleContentClass');
            static::$visibleContentHookObjectsPrepared = true;
        }
        foreach ($this->visibleContentHookObjects as $hookObj) {
            if (method_exists($hookObj, 'displayElement')) {
                $hookObj->displayElement($subElementArr, $displayElement, $this);
            }
        }

        return (bool)$displayElement;
    }

    /**
     * @param array $elementContentTreeArr
     * @param string $sheet
     * @param string $lKey
     * @param string $vKey
     *
     * @return bool
     */
    protected function disablePageStructureInheritance($elementContentTreeArr, $sheet, $lKey, $vKey)
    {
        $disable = false;
        if (static::getBackendUser()->isAdmin()) {
            //if page DS and the checkbox is not set use always langDisable in inheritance mode
            $disable = $this->controller->getSetting('disablePageStructureInheritance') !== '1';
        } else {
            $hasLocalizedValues = false;
            $adminOnly = $this->controller->modTSconfig['properties']['adminOnlyPageStructureInheritance'];
            if ($adminOnly === 'strict') {
                $disable = true;
            } else {
                if ($adminOnly === 'fallback' && isset($elementContentTreeArr['sub'][$sheet][$lKey])) {
                    foreach ($elementContentTreeArr['previewData']['sheets'][$sheet] as $fieldData) {
                        $hasLocalizedValues |= isset($fieldData['data'][$lKey][$vKey])
                            && ($fieldData['data'][$lKey][$vKey] !== null)
                            && ($fieldData['isMapped'] === true)
                            && (!isset($fieldData['TCEforms']['displayCond']) || $fieldData['TCEforms']['displayCond'] !== 'HIDE_L10N_SIBLINGS');
                    }
                } else {
                    if ($adminOnly === 'false') {
                        $disable = $this->controller->getSetting('disablePageStructureInheritance') !== '1';
                    }
                }
            }
            // we disable it if the path wasn't already created (by an admin)
            $disable |= !$hasLocalizedValues;
        }

        return $disable;
    }
}
