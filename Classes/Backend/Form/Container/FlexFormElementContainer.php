<?php
declare(strict_types=1);

namespace WapplerSystems\WsSlider\Backend\Form\Container;


use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\JsConfirmation;


class FlexFormElementContainer extends \TYPO3\CMS\Backend\Form\Container\FlexFormElementContainer
{
    /**
     * Entry method
     *
     * @return array As defined in initializeResultArray() of AbstractNode
     */
    public function render()
    {
        $flexFormDataStructureArray = $this->data['flexFormDataStructureArray'];
        $flexFormRowData = $this->data['flexFormRowData'];
        $flexFormFormPrefix = $this->data['flexFormFormPrefix'];
        $parameterArray = $this->data['parameterArray'];

        $languageService = $this->getLanguageService();
        $resultArray = $this->initializeResultArray();
        $showFieldName = $GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] && $this->getBackendUserAuthentication()->isAdmin();

        foreach ($flexFormDataStructureArray as $flexFormFieldName => $flexFormFieldArray) {
            if (
                // No item array found at all
                !is_array($flexFormFieldArray)
                // Not a section or container and not a list of single items
                || (!isset($flexFormFieldArray['type']) && !is_array($flexFormFieldArray['config']))
            ) {
                continue;
            }

            if (($flexFormFieldArray['type'] ?? '') === 'array') {
                // Section
                if (empty($flexFormFieldArray['section'])) {
                    $resultArray['html'] = LF . 'Section expected at ' . $flexFormFieldName . ' but not found';
                    continue;
                }

                $options = $this->data;
                $options['flexFormDataStructureArray'] = $flexFormFieldArray;
                $options['flexFormRowData'] = $flexFormRowData[$flexFormFieldName]['el'] ?? [];
                $options['flexFormFieldName'] = $flexFormFieldName;
                $options['renderType'] = 'flexFormSectionContainer';
                $sectionContainerResult = $this->nodeFactory->create($options)->render();
                $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $sectionContainerResult);
            } else {
                // Set up options for single element
                $fakeParameterArray = [
                    'fieldConf' => [
                        'label' => $languageService->sL(trim($flexFormFieldArray['label'] ?? '')),
                        'config' => $flexFormFieldArray['config'] ?? [],
                        'children' => $flexFormFieldArray['children'] ?? [],
                        'onChange' => $flexFormFieldArray['onChange'] ?? '',
                    ],
                    'fieldChangeFunc' => $parameterArray['fieldChangeFunc'],
                    'label' => $parameterArray['label'] ?? '',
                ];

                if (isset($flexFormFieldArray['description']) && !empty($flexFormFieldArray['description'])) {
                    $fakeParameterArray['fieldConf']['description'] = $flexFormFieldArray['description'];
                }

                $alertMsgOnChange = '';
                if (isset($fakeParameterArray['fieldConf']['onChange']) && $fakeParameterArray['fieldConf']['onChange'] === 'reload') {
                    if ($this->getBackendUserAuthentication()->jsConfirmation(JsConfirmation::TYPE_CHANGE)) {
                        $alertMsgOnChange = 'Modal.confirm('
                            . 'TYPO3.lang["FormEngine.refreshRequiredTitle"],'
                            . ' TYPO3.lang["FormEngine.refreshRequiredContent"]'
                            . ')'
                            . '.on('
                            . '"button.clicked",'
                            . ' function(e) { if (e.target.name == "ok") { FormEngine.saveDocument(); } Modal.dismiss(); }'
                            . ');';
                    } else {
                        $alertMsgOnChange = 'FormEngine.saveDocument();';
                    }
                }
                if ($alertMsgOnChange) {
                    $fakeParameterArray['fieldChangeFunc']['alert'] = 'require([\'TYPO3/CMS/Backend/FormEngine\', \'TYPO3/CMS/Backend/Modal\'], function (FormEngine, Modal) {' . $alertMsgOnChange . '});';
                }

                $originalFieldName = $parameterArray['itemFormElName'];
                $fakeParameterArray['itemFormElName'] = $parameterArray['itemFormElName'] . $flexFormFormPrefix . '[' . $flexFormFieldName . '][vDEF]';
                if ($fakeParameterArray['itemFormElName'] !== $originalFieldName) {
                    // If calculated itemFormElName is different from originalFieldName
                    // change the originalFieldName in TBE_EDITOR_fieldChanged. This is
                    // especially relevant for wizards writing their content back to hidden fields
                    if (!empty($fakeParameterArray['fieldChangeFunc']['TBE_EDITOR_fieldChanged'])) {
                        if (is_string($fakeParameterArray['fieldChangeFunc']['TBE_EDITOR_fieldChanged'])) {
                            $fakeParameterArray['fieldChangeFunc']['TBE_EDITOR_fieldChanged'] = str_replace($originalFieldName, $fakeParameterArray['itemFormElName'], $fakeParameterArray['fieldChangeFunc']['TBE_EDITOR_fieldChanged']);
                        } elseif (is_array($fakeParameterArray['fieldChangeFunc']['TBE_EDITOR_fieldChanged'])) {
                            $fakeParameterArray['fieldChangeFunc']['TBE_EDITOR_fieldChanged']['elementName'] = $fakeParameterArray['itemFormElName'];
                        }
                    }
                }
                $fakeParameterArray['itemFormElID'] = ($parameterArray['itemFormElID'] ?? '') . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $flexFormFieldName) . '_' . md5($fakeParameterArray['itemFormElName']);
                if (isset($flexFormRowData[$flexFormFieldName]['vDEF'])) {
                    $fakeParameterArray['itemFormElValue'] = $flexFormRowData[$flexFormFieldName]['vDEF'];
                } else {
                    $fakeParameterArray['itemFormElValue'] = $fakeParameterArray['fieldConf']['config']['default'];
                }

                $options = $this->data;
                // Set either flexFormFieldName or flexFormContainerFieldName, depending on if we are a "regular" field or a flex container section field
                if (empty($options['flexFormFieldName'])) {
                    $options['flexFormFieldName'] = $flexFormFieldName;
                } else {
                    $options['flexFormContainerFieldName'] = $flexFormFieldName;
                }
                $options['parameterArray'] = $fakeParameterArray;
                $options['elementBaseName'] = $this->data['elementBaseName'] . $flexFormFormPrefix . '[' . $flexFormFieldName . '][vDEF]';

                if (!empty($flexFormFieldArray['config']['renderType'])) {
                    $options['renderType'] = $flexFormFieldArray['config']['renderType'];
                } else {
                    // Fallback to type if no renderType is given
                    $options['renderType'] = $flexFormFieldArray['config']['type'];
                }
                $childResult = $this->nodeFactory->create($options)->render();

                if (!empty($childResult['html'])) {
                    // Possible line breaks in the label through xml: \n => <br/>, usage of nl2br() not possible, so it's done through str_replace (?!)
                    $processedTitle = str_replace('\\n', '<br />', htmlspecialchars($fakeParameterArray['fieldConf']['label']));
                    $html = [];
                    $html[] = '<div class="form-section">';
                    $html[] = '<div class="form-group t3js-formengine-palette-field t3js-formengine-validation-marker">';
                    $html[] = '<label class="t3js-formengine-label">';
                    $html[] = BackendUtility::wrapInHelp($parameterArray['_cshKey'] ?? '', $flexFormFieldName, $processedTitle);
                    $html[] = $showFieldName ? ('<code>[' . htmlspecialchars($flexFormFieldName) . ']</code>') : '';
                    $html[] = '</label>';
                    switch ($options['renderType']) {
                        case 'selectSingleWithTypoScriptPlaceholder':
                        case 'inputWithTypoScriptPlaceholder':

                            $databaseRow = $this->data['databaseRow'];
                            $sliderRenderer = $databaseRow['tx_wsslider_renderer'][0] ?? '';

                            $helpTextArray = BackendUtility::helpTextArray($parameterArray['_cshKey'], $sliderRenderer . '.' . $flexFormFieldName);
                            if (isset($helpTextArray['description']) && $helpTextArray['description'] !== '') {
                                $html[] = '<label class="t3js-formengine-sublabel">' . $helpTextArray['description'] . '</label>';
                            }
                    }
                    $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
                    $html[] = $childResult['html'];
                    $html[] = '</div>';
                    $html[] = '</div>';
                    $html[] = '</div>';
                    $resultArray['html'] .= implode(LF, $html);
                    $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $childResult, false);
                }
            }
        }

        return $resultArray;
    }

}
