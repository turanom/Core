<?php
namespace exface\Core\Facades\AbstractAjaxFacade\Elements;

use exface\Core\Widgets\DataColumn;
use exface\Core\Widgets\DataImporter;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Widgets\InputSelect;
use exface\Core\Widgets\InputCombo;
use exface\Core\Exceptions\Facades\FacadeLogicError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Widgets\InputHidden;
use exface\Core\Widgets\InputNumber;
use exface\Core\Widgets\InputCheckBox;
use exface\Core\Widgets\Display;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\BooleanDataType;

/**
 * Common methods for facade elements based on the jExcel library.
 * 
 * Make sure to include jExcel in the dependecies of the facade - e.g. via composer:
 * 
 * ```
 * {
 *  "require": {
 *      "npm-asset/jexcel" : "^3.2.0"
 *  }
 * }
 * 
 * ```
 * 
 * If your facade is based on the `AbstractAjaxFacade`, add these configuration options
 * to the facade config file. Make sure, each config option points to an existing
 * inlcude file!
 * 
 * ```
 *  "LIBS.JEXCEL.JS": "npm-asset/jexcel/dist/jexcel.min.js",
 *  "LIBS.JEXCEL.JS_JSUITES": "npm-asset/jsuites/dist/jsuites.js",
 *  "LIBS.JEXCEL.CSS": "npm-asset/jexcel/dist/jexcel.min.css",
 *	"LIBS.JEXCEL.CSS_JSUITES": "npm-asset/jsuites/dist/jsuites.css",
 *	
 * ```
 * 
 * @method DataImporter getWidget()
 * 
 * @author Andrej Kabachnik
 *
 */
trait JExcelTrait 
{
    
    /**
     * 
     * @return string[]
     */
    protected function buildHtmlHeadTagsForJExcel() : array
    {
        $facade = $this->getFacade();
        return [
            '<script type="text/javascript" src="' . $facade->buildUrlToSource('LIBS.JEXCEL.JS') . '"></script>',
            '<script type="text/javascript" src="' . $facade->buildUrlToSource('LIBS.JEXCEL.JS_JSUITES') . '"></script>',
            '<link href="' . $facade->buildUrlToSource('LIBS.JEXCEL.CSS') . '" rel="stylesheet" media="screen">',
            '<link href="' . $facade->buildUrlToSource('LIBS.JEXCEL.CSS_JSUITES') . '" rel="stylesheet" media="screen">'
        ];
        
    }
    
    
    protected function buildHtmlJExcel() : string
    {
        return <<<JS

<div id="{$this->getId()}"></div>

JS;
    }
      
    /**
     * 
     * @return string
     */
    protected function buildJsJExcelInit() : string
    {
        $colNames = [];
        foreach ($this->getWidget()->getColumns() as $col) {
            $colNames[$col->getCaption()] = $col->getDataColumnName();
        }
        $colNamesJson = json_encode($colNames);
        
        return <<<JS

    $('#{$this->getId()}')
    .data('_exfColumnNames', {$colNamesJson})
    .jexcel({
        data: [ [] ],
        allowRenameColumn: false,
        {$this->buildJsJExcelColumns()}
        {$this->buildJsJExcelMinSpareRows()}
    });

JS;
    }
     
    /**
     * 
     * @return string
     */
    protected function buildJsJExcelColumns() : string
    {
        $columns = [];
        foreach ($this->getWidget()->getColumns() as $col) {
            $columns[] = $this->buildJsJExcelColumn($col);
        }
            
        return "
        columns: [ " . implode(',', $columns) . " ],";
    }
    
    /**
     * 
     * @param DataColumn $col
     * @return string
     */
    protected function buildJsJExcelColumn(DataColumn $col) : string
    {
        $cellWidget = $col->getCellWidget();
        $options = '';
        switch (true) {
            case $col->isHidden() === true:
            case $cellWidget instanceof InputHidden:
                $type = "hidden";
                break;
            case $cellWidget instanceof InputNumber:
            case $cellWidget instanceof Display && $cellWidget->getValueDataType() instanceof NumberDataType:
                $type = "numeric";
                break;
            case $cellWidget instanceof InputCheckBox:
            case $cellWidget instanceof Display && $cellWidget->getValueDataType() instanceof BooleanDataType:
                $type = "checkbox";
                break;
            case $cellWidget instanceof InputCombo:
                $type = 'autocomplete';
                $options .= $this->buildJsJExcelColumnDropdownOptions($cellWidget);
                break;
            default:
                $type = "text";
        }
        
        if ($col->isEditable() === false) {
            $options .= 'readOnly: true,';
        }
        
        $width = $col->getWidth();
        if ($width->isFacadeSpecific() === true) {
            if (StringDataType::endsWith($width->getValue(), 'px') === true) {
                $widthJs = str_replace('px', '', $width->getValue());
            } else {
                $widthJs = '80';
            }
        } else {
            $widthJs= '80';
        }
        
        if ($widthJs) {
            $js .= "width: {$widthJs},";
        }
        
        return <<<JS

            {
                title: "{$col->getCaption()}",
                type: "{$type}",
                width: {$widthJs},
                {$options}
            }
JS;
        
        return '{' . rtrim($js, ","). '}';
    }
        
    protected function buildJsJExcelColumnDropdownOptions(InputSelect $cellWidget) : string
    {
        if ($cellWidget->isBoundToAttribute() === false) {
            throw new FacadeLogicError('TODO');
        }
        
        if ($cellWidget->getLazyLoading() === false) {
            if ($cellWidget->getAttribute()->isRelation()) {
                $rel = $cellWidget->getAttribute()->getRelation();
                
                $srcSheet = DataSheetFactory::createFromObject($rel->getRightObject());
                $srcLabelAttr = $srcSheet->getMetaObject()->getLabelAttribute();
                $srcIdAttr = $srcSheet->getMetaObject()->getUidAttribute();
                $srcLabelCol = $srcSheet->getColumns()->addFromAttribute($srcLabelAttr);
                $srcIdCol = $srcSheet->getColumns()->addFromAttribute($srcIdAttr);
                $srcLabelName = $srcLabelCol->getName();
                $srcIdName = $srcIdCol->getName();
                
                $srcSheet->dataRead();
                $srcData = [];
                foreach ($srcSheet->getRows() as $row) {
                    $srcData[] = ['id' => $row[$srcIdName], 'name' => $row[$srcLabelName]];
                }
                $srcJson = json_encode($srcData);
            } else {
                // TODO non-lazy distinct value list
                $srcJson = '[]';
            }
        } else {
            // TODO lazy loading
            $srcJson = '[]';
        }
        
        return "source: {$srcJson},";
    }
        
    protected function getMinSpareRows() : int
    {
        return 1;
    }
     
    /**
     * 
     * @return string
     */
    protected function buildJsJExcelMinSpareRows() : string
    {
        return 'minSpareRows: ' .  $this->getMinSpareRows() . ',';
    }
        
    /**
     *
     * {@inheritdoc}
     * @see AbstractJqueryElement::buildJsDataGetter()
     */
    public function buildJsDataGetter(ActionInterface $action = null)
    {
        $data = "$('#{$this->getId()}').jexcel('getData', false)";
        return <<<JS
        
    {
        oId: '{$this->getWidget()->getMetaObject()->getId()}',
        rows: {$this->buildJsConvertArrayToData($data)}
    }
    
JS;
    }
     
    /**
     * 
     * {@inheritdoc}
     * @see AbstractJqueryElement::buildJsDataSetter()
     */
    public function buildJsDataSetter(string $jsData)
    {
        // The '!' in front of the IFFE is required because it would not get executed stand alone
        // resulting in a "SyntaxError: Function statements require a function name" instead.
        return <<<JS
!function() {    
    var oData = {$jsData};    
    var aData = [];
    if (oData !== undefined && Array.isArray(oData.rows)) {
        aData = {$this->buildJsConvertDataToArray('response.rows')}
    }
    if (aData.length > 0) {
        $('#{$this->getId()}').jexcel('setData', aData);
    }
}()

JS;
    }
        
    protected function buildJsConvertArrayToData(string $arrayOfArraysJs) : string
    {
        return <<<JS
function() {
    var aDataArray = {$arrayOfArraysJs};
    var aData = [];
    var jExcel = $('#{$this->getId()}');
    var oColNames = jExcel.data('_exfColumnNames');
    aDataArray.forEach(function(aRow, i){
        var oRow = {};
        var sHeaderName;
        var sColName;
        aRow.forEach(function(val, iColIdx){
            try {
                sHeaderName = jExcel.jexcel('getHeader', iColIdx);
            } catch (e) {
                sHeaderName = '';
            }
            sColName = oColNames[sHeaderName];
            if (sColName) {
                oRow[sColName] = val;
            }
        });
        aData.push(oRow);
    });
    
    for (var i = 0; i < {$this->getMinSpareRows()}; i++) {
        aData.pop();
    }

    return aData;
}()

JS;
    }
    
    protected function buildJsConvertDataToArray(string $arrayOfObjectsJs) : string
    {
        return <<<JS
        
function() {
    var aDataRows = {$arrayOfObjectsJs};
    var aData = [];
    var jExcel = $('#{$this->getId()}');
    var oColNames = jExcel.data('_exfColumnNames');
    var aColHeaders = jExcel.jexcel('getHeaders').split(',');
    var oColIdxCache = {};
    aDataRows.forEach(function(oRow, i){
        var oRowIndexed = {};
        var aRow = [];
        var sHeaderName, iColIdx, iLastIdx;
        for (var sColName in oRow) {
            iColIdx = oColIdxCache[sColName];
            if (iColIdx !== undefined) {
                oRowIndexed[iColIdx] = oRow[sColName];
            }

            sHeaderName = Object.keys(oColNames).find(key => oColNames[key] === sColName);
            if (! sHeaderName) continue;
            iColIdx = aColHeaders.indexOf(sHeaderName);
            if (iColIdx >= 0) {
                oRowIndexed[iColIdx] = oRow[sColName];
                oColIdxCache[sColName] = iColIdx;
            }
        }
        
        iLastIdx = -1;
        Object.keys(oRowIndexed).sort().forEach(function(iIdx) {
            while (iIdx > iLastIdx + 1) {
                aRow.push(null);
                iLastIdx++;
            }
            aRow.push(oRowIndexed[iIdx]);
            iLastIdx++;
        });
        
        aData.push(aRow);
    });
    
    return aData;
}()

JS;
    }
    
}