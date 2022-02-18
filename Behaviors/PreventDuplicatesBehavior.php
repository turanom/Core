<?php
namespace exface\Core\Behaviors;

use exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior;
use exface\Core\Interfaces\Model\BehaviorInterface;
use exface\Core\Events\DataSheet\OnBeforeCreateDataEvent;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\ConditionGroupFactory;
use exface\Core\Exceptions\Behaviors\BehaviorConfigurationError;
use exface\Core\Exceptions\DataSheets\DataSheetDuplicatesError;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Events\DataSheet\OnBeforeUpdateDataEvent;
use exface\Core\Exceptions\Widgets\WidgetPropertyInvalidValueError;
use exface\Core\Exceptions\Behaviors\BehaviorRuntimeError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Model\ConditionGroupInterface;
use exface\Core\CommonLogic\DataSheets\DataColumn;

/**
 * Behavior to prevent a creation of a duplicate dataset on create or update Operations.
 * 
 * It works by searching data in the data source for matches on `compare_attributes` on
 * every create or update operation. If matches are found, the behavior either throws 
 * an error, ignores the new data or performs an update on the existing data row - depending
 * on the properties `on_duplicate_multi_row` and `on_duplicate_single_row`.
 * 
 * By default an error message will be thrown if a duplicate is found. 
 * 
 * ## Example
 * 
 * Here is how duplicates of `exface.Core.USER_AUTHENTICATOR` core object are prevented.
 * 
 * ```
 * {
 *  "compare_attributes": [
 *      "USER",
 *      "USER_ROLE"
 *  ],
 *  "on_duplicate_multi_row": 'update'
 *  "on_duplicate_single_row": 'error'
 * }
 * 
 * ````
 * 
 */
class PreventDuplicatesBehavior extends AbstractBehavior
{
    const ON_DUPLICATE_ERROR = 'ERROR';
    
    const ON_DUPLICATE_UPDATE = 'UPDATE';
    
    const ON_DUPLICATE_IGNORE = 'IGNORE';
    
    private $onDuplicateMultiRow = null;
    
    private $onDuplicateSingleRow = null;
    
    private $compareAttributeAliases = [];
    
    private $allowEmptyValuesForAttributeAliases = [];
    
    private $compareWithConditions = null;
    
    private $errorCode = null;
    
    private $errorMessage = null;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior::registerEventListeners()
     */
    protected function registerEventListeners() : BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->addListener(OnBeforeCreateDataEvent::getEventName(), [$this, 'handleOnBeforeCreate']);
        
        $this->getWorkbench()->eventManager()->addListener(OnBeforeUpdateDataEvent::getEventName(), [$this, 'handleOnBeforeUpdate']);
        
        return $this;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior::unregisterEventListeners()
     */
    protected function unregisterEventListeners() : BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->removeListener(OnBeforeCreateDataEvent::getEventName(), [$this, 'handleOnBeforeCreate']);
        
        $this->getWorkbench()->eventManager()->removeListener(OnBeforeUpdateDataEvent::getEventName(), [$this, 'handleOnBeforeUpdate']);
        
        return $this;
    }
    
    /**
     * 
     * @param OnBeforeCreateDataEvent $event
     * @throws DataSheetDuplicatesError
     * @throws BehaviorRuntimeError
     * @return void
     */
    public function handleOnBeforeCreate(OnBeforeCreateDataEvent $event)
    {
        if ($this->isDisabled()) {
            return ;
        }
        
        $eventSheet = $event->getDataSheet();
        //$eventSheet->getColumns()->set
        $object = $eventSheet->getMetaObject();        
        // Do not do anything, if the base object of the data sheet is not the object with the behavior and is not extended from it.
        if (! $object->isExactly($this->getObject())) {
            return;
        }
        
        $duplicates = $this->getDuplicates($eventSheet);
        
        if (empty($duplicates) === true) {
            return;
        }
        
        if ($eventSheet->countRows() === 1) {
            switch ($this->getOnDuplicateSingleRow()) {
                case self::ON_DUPLICATE_IGNORE:
                    $event->preventCreate();
                    return;
                case self::ON_DUPLICATE_UPDATE:
                    $row = $eventSheet->getRow(0);
                    $duplRows = $duplicates[0];
                    if (count($duplRows) !== 1) {
                        throw new BehaviorRuntimeError($this->getObject(), 'Cannot update duplicates of "' . $this->getObject()->getName() . '" (alias ' . $this->getObject()->getAliasWithNamespace() . '): multiple potential duplicates found!');
                    }
                    foreach ($eventSheet->getMetaObject()->getAttributes()->getSystem() as $systemAttr) {
                        $row[$systemAttr->getAlias()] = $duplRows[0][$systemAttr->getAlias()];
                    }
                    $event->preventCreate();
                    $eventSheet->removeRows();
                    $eventSheet->addRow($row);
                    $eventSheet->dataUpdate(false, $event->getTransaction());
                    return;
            }
        } else {
            switch ($this->getOnDuplicateMultiRow()) {
                case self::ON_DUPLICATE_IGNORE:
                    $duplRowNos = array_keys($duplicates);
                    foreach (array_reverse($duplRowNos) as $duplRowNo) {
                        // have to reverse array with indices because rows in data sheet get reindexed when one is removed
                        $eventSheet->removeRow($duplRowNo);                    
                    }
                    
                    if ($eventSheet->isEmpty()) {
                        $event->preventCreate();
                    }
                    
                    return;
                case self::ON_DUPLICATE_UPDATE:
                    //copy the dataSheet and empty it
                    $updateSheet = $eventSheet->copy();
                    $updateSheet->removeRows();
                    $duplRowNos = array_keys($duplicates);
                    foreach (array_reverse($duplRowNos) as $duplRowNo) {                    
                        // have to reverse array with indices because rows in data sheet get reindexed when one is removed
                        $row = $eventSheet->getRow($duplRowNo);
                        $duplRows = $duplicates[$duplRowNo];
                        if (count($duplRows) !== 1) {
                            throw new BehaviorRuntimeError($this->getObject(), 'Cannot update duplicates of "' . $this->getObject()->getName() . '" (alias ' . $this->getObject()->getAliasWithNamespace() . '): multiple potential duplicates found!');
                        }
                        //copy system attributes values
                        foreach ($eventSheet->getMetaObject()->getAttributes()->getSystem() as $systemAttr) {
                            $row[$systemAttr->getAlias()] = $duplRows[0][$systemAttr->getAlias()];
                        }
                        $updateSheet->addRow($row);
                        //delete row from event data sheet
                        $eventSheet->removeRow($duplRowNo);
                    }
                    //call update on update sheet
                    $updateSheet->dataUpdate(false, $event->getTransaction());
                    
                    if ($eventSheet->isEmpty()) {
                        $event->preventCreate();
                    }
                    
                    return;
            }
        }
        
        throw $this->createDuplicatesError($eventSheet, $duplicates);
    }
    
    /**
     * 
     * @param OnBeforeUpdateDataEvent $event
     * @throws DataSheetDuplicatesError
     * @return void
     */
    public function handleOnBeforeUpdate(OnBeforeUpdateDataEvent $event)
    {
        if ($this->isDisabled()) {
            return;
        }
        
        $eventSheet = $event->getDataSheet();
        $object = $eventSheet->getMetaObject();
        
        // Do not do anything, if the base object of the data sheet is not the object with the behavior and is not extended from it.
        if (! $object->isExactly($this->getObject())) {
            return;
        }
        
        // Ignore partial updates, that do not change compared attributes
        $foundCompareCols = false;
        foreach ($this->getCompareAttributeAliases() as $attrAlias) {
            if ($eventSheet->getColumns()->getByAttribute($object->getAttribute($attrAlias))) {
                $foundCompareCols = true;
                break;
            }
        }
        if ($foundCompareCols === false) {
            return;
        } 
        
        $duplicates = $this->getDuplicates($eventSheet);
        if (empty($duplicates)) {
            return;
        }
        
        throw $this->createDuplicatesError($eventSheet, $duplicates);
    }
    
    /**
     * Returns array of associative array with original row numbers for keys and arrays of potential duplicate rows as values
     * 
     * The array has the following format:
     * [
     *  <eventDataRowNumber> => [
     *      <duplicateDataRow1Array>,
     *      <duplicateDataRow2Array>,
     *      ...
     *  ]
     * ]
     * 
     * @param DataSheetInterface $eventSheet
     * @return array
     */
    protected function getDuplicates(DataSheetInterface $eventSheet) : array
    {   
        $eventDataCols = $eventSheet->getColumns();
        
        $duplicates = [];
        $compareCols = [];
        $missingCols = [];
        $missingAttrs = [];
        foreach ($this->getCompareAttributeAliases() as $attrAlias) {
            $attr = $this->getObject()->getAttribute($attrAlias);
            if ($col = $eventDataCols->getByAttribute($attr)) {
                $compareCols[] = $col;
            } else {
                $missingAttrs[] = $attr;
            }
        }
        
        $eventRows = $eventSheet->getRows();
        
        if (empty($missingAttrs) === false) {
            if ($eventSheet->hasUidColumn(true) === false) {
                throw new BehaviorRuntimeError($this->getObject(), 'Cannot check for duplicates of "' . $this->getObject()->getName() . '" (alias ' . $this->getObject()->getAliasWithNamespace() . '): not enough data!');
            } else {
                $missingAttrSheet = DataSheetFactory::createFromObject($this->getObject());
                $missingAttrSheet->getFilters()->addConditionFromColumnValues($eventSheet->getUidColumn());
                $missingCols = [];
                foreach ($missingAttrs as $attr) {
                    $missingCols[] = $missingAttrSheet->getColumns()->addFromAttribute($attr);
                }
                $missingAttrSheet->dataRead();
                
                $uidColName = $eventSheet->getUidColumnName();
                foreach ($eventRows as $rowNo => $row) {
                    foreach ($missingCols as $missingCol) {
                        $eventRows[$rowNo][$missingCol->getName()] = $missingCol->getValueByUid($row[$uidColName]);
                    }
                }
            }
        }
        
        // See if there are duplicates within the current set of data
        if (count($eventRows) > 1) {
            if ($this->hasCustomConditions()) {
                $selfCheckSheet = DataSheetFactory::createFromObject($eventSheet->getMetaObject());
                $selfCheckSheet->addRows($eventRows);
                $selfCheckFilters = ConditionGroupFactory::createForDataSheet($selfCheckSheet, $this->getCompareWithConditions()->getOperator());
                foreach ($this->getCompareWithConditions()->getConditions() as $cond) {
                    if ($selfCheckSheet->getColumns()->getByExpression($cond->getExpression())) {
                        $selfCheckFilters->addCondition($cond);
                    }
                }
                $selfCheckRows = $selfCheckSheet->extract($selfCheckFilters)->getRows();
            } else {
                $selfCheckRows = $eventRows;
            }
            $duplicates = $this->findDuplicatesInRows($eventRows, $selfCheckRows, array_merge($compareCols, $missingCols), ($eventSheet->hasUidColumn() ? $eventSheet->getUidColumn() : null));
        }
        
        // Create a data sheet to search for possible duplicates
        $checkSheet = $eventSheet->copy();
        $checkSheet->removeRows();
        $checkSheet->getFilters()->removeAll();
        
        // Add columns even for attributes that are not present in the original event sheet
        foreach ($missingAttrs as $attr) {
            $checkSheet->getColumns()->addFromAttribute($attr);
        }
        
        // Add system attributes in case we are going to update
        $checkSheet->getColumns()->addFromSystemAttributes();
        
        // Add custom filters if defined
        if (null !== $customFilters = $this->getCompareWithConditions()) {
            $checkSheet->getFilters()->addNestedGroup($customFilters);
        }
        
        // To get possible duplicates transform every row in event data sheet into a filter for 
        // the check sheet
        $orFilterGroup = ConditionGroupFactory::createEmpty($this->getWorkbench(), EXF_LOGICAL_OR, $checkSheet->getMetaObject());
        foreach ($eventRows as $rowNo => $row) {
            $rowFilterGrp = ConditionGroupFactory::createEmpty($this->getWorkbench(), EXF_LOGICAL_AND, $checkSheet->getMetaObject());
            foreach (array_merge($compareCols, $missingCols) as $col) {
                if (! array_key_exists($col->getName(), $row)) {
                    throw new BehaviorRuntimeError($this->getObject(), 'Cannot check for duplicates for ' . $this->getObject()->__toString() . ': no input data found for attribute "' . $col->getAttributeAlias() . '"!');
                }
                $value = $row[$col->getName()];
                
                if (($value === null || $value === '') && $col->getAttribute()->isRequired()) {
                    throw new BehaviorRuntimeError($this->getObject(), 'Cannot check for duplicates for ' . $this->getObject()->__toString() . ': missing required value for attribute "' . $col->getAttributeAlias() . ' in row "' . $rowNo . '"!');
                }
                $rowFilterGrp->addConditionFromString($col->getAttributeAlias(), ($value === '' || $value === null ? EXF_LOGICAL_NULL : $value), ComparatorDataType::EQUALS);
            }
            $orFilterGroup->addNestedGroup($rowFilterGrp);
        }
        $checkSheet->getFilters()->addNestedGroup($orFilterGroup);        
        
        // Read the data with the applied filters
        $checkSheet->dataRead();
        
        $checkRows = $checkSheet->getRows();
        
        if (empty($checkRows)) {
            return [];
        }
        
        $duplicates = array_merge_recursive($duplicates, $this->findDuplicatesInRows($eventRows, $checkRows, $compareCols, ($eventSheet->hasUidColumn() ? $eventSheet->getUidColumn() : null)));
        
        return $duplicates;
    }
    
    protected function findDuplicatesInRows(array $eventRows, array $checkRows, array $compareCols, DataColumn $uidCol = null) : array
    {
        $duplicates = [];
        $eventRowCnt = count($eventRows);
        for ($eventRowNo = 0; $eventRowNo < $eventRowCnt; $eventRowNo++) {
            // For each row loaded from data source
            foreach ($checkRows as $chRow) {
                $isDuplicate = true;
                // Compare all the relevant columns: if any value differs, it is NOT a duplicate
                foreach ($compareCols as $col) {
                    $dataType = $col->getDataType();
                    $key = $col->getName();
                    if ($dataType->parse($eventRows[$eventRowNo][$key]) != $dataType->parse($chRow[$key])) {
                        $isDuplicate = false;
                        break;
                    }
                }
                
                // If the data source row has matching columns, check if the UID also matches: if so,
                // it is the same row and, thus, NOT a duplicate
                if ($uidCol !== null) {
                    $dataType = $uidCol->getDataType();
                    $key = $uidCol->getName();
                    if ($dataType->parse($eventRows[$eventRowNo][$key]) == $dataType->parse($chRow[$key])) {
                        $isDuplicate = false;
                        // Don't bread here as other $checkRows may still be duplicates!!!
                    }
                }
                
                // If it is still a potential duplicate, it really is one
                if ($isDuplicate === true) {
                    $duplicates[$eventRowNo][] = $chRow;
                    break;
                }
            }
        }
        return $duplicates;
    }
    
    /**
     * 
     * @param DataSheetInterface $dataSheet
     * @param array[] $duplicates
     * @return DataSheetDuplicatesError
     */
    protected function createDuplicatesError(DataSheetInterface $dataSheet, array $duplicates) : DataSheetDuplicatesError
    {
        $object = $dataSheet->getMetaObject();
        $labelAttributeAlias = $object->getLabelAttributeAlias();
        $rows = $dataSheet->getRows();
        $errorRowDescriptor = '';
        $errorMessage = '';
        
        foreach (array_keys($duplicates) as $duplRowNo) {
            $row = $rows[$duplRowNo];
            if ($labelAttributeAlias !== null && $row[$labelAttributeAlias] !== null){
                $errorRowDescriptor .= "'{$row[$labelAttributeAlias]}', ";
            } else {
                $errorRowDescriptor .= strval($duplRowNo + 1) . ", ";
            }
        }
        $errorRowDescriptor = substr($errorRowDescriptor, 0, -2);
        
        try {
            $errorMessage = $this->translate('BEHAVIOR.PREVENTDUPLICATEBEHAVIOR.CREATE_DUPLICATES_FORBIDDEN_ERROR', ['%row%' => $errorRowDescriptor, '%object%' => '"' . $object->getName() . '" (' . $object->getAliasWithNamespace() . ')']);
            $ex = new DataSheetDuplicatesError($dataSheet, $errorMessage, $this->getDuplicateErrorCode());
            $ex->setUseExceptionMessageAsTitle(true);
        } catch (\Exception $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            $ex = new DataSheetDuplicatesError($dataSheet, 'Cannot update/create data, as it contains duplicates of already existing data!', $this->getDuplicateErrorCode());
        }
        
        return $ex;
    }
    
    /**
     * The attributes determining if a dataset is a duplicate.
     *
     * @uxon-property compare_attributes
     * @uxon-type metamodel:attribute[]
     * @uxon-template [""]
     *
     * @param string[]|UxonObject $arrayOrUxon
     * @return PreventDuplicatesBehavior
     */
    public function setCompareAttributes($arrayOrUxon) : PreventDuplicatesBehavior
    {
        if ($arrayOrUxon instanceof UxonObject) {
            $this->compareAttributeAliases = $arrayOrUxon->toArray();
        } elseif (is_array($arrayOrUxon)) {
            $this->compareAttributeAliases = $arrayOrUxon;
        }
        return $this;
    }
    
    /**
     *
     * @throws BehaviorConfigurationError
     * @return string[]
     */
    protected function getCompareAttributeAliases() : array
    {
        if (empty($this->compareAttributeAliases)) {
            throw new BehaviorConfigurationError($this->getObject(), "No attributes were set in '{$this->getAlias()}' of the object '{$this->getObject()->getAlias()}' to determine if a dataset is a duplicate or not! Set atleast one attribute via the 'compare_attributes' uxon property!");
        }
        return $this->compareAttributeAliases;
    }
    
    
    protected function getCompareWithConditions() : ?ConditionGroupInterface
    {
        if ($this->compareWithConditions instanceof UxonObject) {
            $this->compareWithConditions = ConditionGroupFactory::createFromUxon($this->getWorkbench(), $this->compareWithConditions, $this->getObject());
        }
        return $this->compareWithConditions;
    }
    
    /**
     * Custom filters to use to look for potential duplicates
     * 
     * @uxon-property compare_with_conditions
     * @uxon-type \exface\Core\CommonLogic\Model\ConditionGroup
     * @uxon-template {"operator": "AND", "conditions": [{"expression": "", "comparator": "", "value": ""}]}
     * 
     * @param UxonObject $value
     * @return PreventDuplicatesBehavior
     */
    protected function setCompareWithConditions(UxonObject $value) : PreventDuplicatesBehavior
    {
        $this->compareWithConditions = $value;
        return $this;
    }
    
    /**
     * 
     * @return bool
     */
    protected function hasCustomConditions() : bool
    {
        return $this->compareWithConditions !== null;
    }
    
    /**
     * Set what should happen if a duplicate is found in a multi row create operation.
     * To ignore duplicates set it to ´ignore´.
     * To update the existing duplicate data row instead of creating a new one set it to ´update´.
     * To show an error when duplicate is found set it to ´error´, that is the default behavior.
     * 
     * @uxon-property on_duplicate_multi_row
     * @uxon-type [error,ignore,update]
     * @uxon-default error
     * 
     * @param string $value
     * @return PreventDuplicatesBehavior
     */
    public function setOnDuplicateMultiRow (string $value) : PreventDuplicatesBehavior
    {
        $value = mb_strtoupper($value);
        if (defined(__CLASS__ . '::ON_DUPLICATE_' . $value)) {
            $this->onDuplicateMultiRow = $value;
        } else {
            throw new WidgetPropertyInvalidValueError('Invalid behavior on duplicates "' . $value . '". Only ERROR, IGNORE and UPDATE are allowed!', '6TA2Y6A');
        }
        return $this;
    }
    
    /**
     * 
     * @return string
     */
    protected function getOnDuplicateMultiRow() : string
    {
        if ($this->onDuplicateMultiRow !== null) {
            return $this->onDuplicateMultiRow;
        }
        return self::ON_DUPLICATE_ERROR;
    }
    
    /**
     * Set what should happen if a duplicate is found in a single row create operation.
     * To ignore duplicates set it to ´ignore´.
     * To update the existing duplicate data row instead of creating a new one set it to ´update´.
     * To show an error when duplicate is found set it to ´error´, that is the default behavior.
     * 
     * @uxon-property on_duplicate_single_row
     * @uxon-type [error,ignore,update]
     * @uxon-default error
     * 
     * @param string $value
     * @return PreventDuplicatesBehavior
     */
    public function setOnDuplicateSingleRow(string $value) : PreventDuplicatesBehavior
    {
        $value = mb_strtoupper($value);
        if (defined(__CLASS__ . '::ON_DUPLICATE_' . $value)) {
            $this->onDuplicateSingleRow = $value;
        } else {
            throw new WidgetPropertyInvalidValueError('Invalid behavior on duplicates "' . $value . '". Only ERROR, IGNORE and UPDATE are allowed!', '6TA2Y6A');
        }
        return $this;
    }
    
    /**
     * 
     * @return string
     */
    protected function getOnDuplicateSingleRow() : string
    {
        if ($this->onDuplicateSingleRow !== null) {
            return $this->onDuplicateSingleRow;
        }
        return self::ON_DUPLICATE_ERROR;
    }
    
    /**
     * @uxon-property duplicate_error_code
     * @uxon-type string
     * 
     * @param string $code
     * @return PreventDuplicatesBehavior
     */
    public function setDuplicateErrorCode (string $code) : PreventDuplicatesBehavior
    {
        $this->errorCode = $code;
        return $this;
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getDuplicateErrorCode() : ?string
    {
        return $this->errorCode;
    }
    
    /**
     * 
     * @param string $messageId
     * @param array $placeholderValues
     * @param float $pluralNumber
     * @return string
     */
    protected function translate(string $messageId, array $placeholderValues = null, float $pluralNumber = null) : string
    {
        return $this->getWorkbench()->getCoreApp()->getTranslator()->translate($messageId, $placeholderValues, $pluralNumber);
    }
}