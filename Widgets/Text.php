<?php
namespace exface\Core\Widgets;

use exface\Core\Interfaces\Widgets\iShowText;
use exface\Core\Widgets\Traits\iCanBeAlignedTrait;
use exface\Core\Interfaces\Widgets\iCanWrapText;

/**
 * Displays multiline text with an optional title (created from the caption of the widget) and some simple formatting.
 * 
 * In contrast to the more generic `Display` widget, `Text` allows line breaks and will wrap long values. It also
 * allows some simple formatting like `style`, `size` and `align`.
 *
 * @author Andrej Kabachnik
 *        
 */
class Text extends Display implements iShowText, iCanWrapText
{
    use iCanBeAlignedTrait {
        getAlign as getAlignDefault;
    }

    private $size = null;

    private $style = null;
    
    private $multiLine = true;

    public function getText()
    {
        return $this->getValue();
    }

    /**
     * Sets the text to be shown explicitly.
     * 
     * This property has the same effect as setting `value`. It also supports formulas.
     * 
     * @uxon-property text
     * @uxon-type string|metamodel:formula
     * @uxon-translatable true
     * 
     * @param string $value
     * @return \exface\Core\Widgets\Text
     */
    public function setText($value)
    {
        $this->setValue($this->evaluatePropertyExpression($value));
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iShowText::getSize()
     */
    public function getSize()
    {
        return $this->size;
    }
    
    /**
     * Sets the style of the text: normal, big, small.
     * 
     * @uxon-property style
     * @uxon-type [normal,big,small]
     * @uxon-default normal
     * 
     * @see \exface\Core\Interfaces\Widgets\iShowText::setSize()
     */
    public function setSize($value)
    {
        $this->size = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iShowText::getStyle()
     */
    public function getStyle()
    {
        return $this->style;
    }
    
    /**
     * Sets the style of the text: normal, bold, underline, strikethrough, italic.
     * 
     * @uxon-property style
     * @uxon-type [normal,bold,underline,strikethrough,italic]
     * @uxon-default normal
     * 
     * @see \exface\Core\Interfaces\Widgets\iShowText::setStyle()
     */
    public function setStyle($value)
    {
        $this->style = strtolower($value);
        return $this;
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\Widgets\AbstractWidget::exportUxonObject()
     */
    public function exportUxonObject()
    {
        $uxon = parent::exportUxonObject();
        if (! is_null($this->size)) {
            $uxon->setProperty('size', $this->size);
        }
        if (! is_null($this->style)) {
            $uxon->setProperty('style', $this->style);
        }
        if (! is_null($this->align)) {
            $uxon->setProperty('align', $this->align);
        }
        return $uxon;
    }
    
    /**
     * 
     * @return bool
     */
    public function isMultiLine() : bool
    {
        return $this->multiLine;
    }
    
    /**
     * Set to FALSE to force a single-line text widget
     * 
     * @uxon-property multi_line
     * @uxon-type boolean
     * @uxon-default true
     * 
     * 
     * @param bool $value
     * @return Text
     */
    public function setMultiLine(bool $value) : Text
    {
        $this->multiLine = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iCanWrapText::getNowrap()
     */
    public function getNowrap(): bool
    {
        return ! $this->isMultiLine();
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iCanWrapText::setNowrap()
     */
    public function setNowrap(bool $value): iCanWrapText
    {
        return $this->setMultiLine(! $value);
    }

}