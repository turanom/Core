<?php

namespace exface\Core\CommonLogic\Model;

use Symfony\Component\ExpressionLanguage\Lexer;
use exface\Core\Interfaces\Formulas\FormulaTokenStreamInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;

class SymfonyTokenStream implements FormulaTokenStreamInterface
{
    private $tokenStream = null;
    
    private $tokens = null;
    
    private $formName = null;
    
    private $nestedForms = null;
    
    private $formulas = null;
    
    private $arguments = null;
    
    public function __construct(string $expression)
    {
        $lexer = new Lexer();
        $tokenStream = $lexer->tokenize($expression);
        $this->tokenStream = $tokenStream;
    }
    
    protected function getTokens() : array
    {
        if ($this->tokens === null) {
            $tokens = [];
            do {
                $tok = $this->tokenStream->current;
                $tokens[] = [$tok->type => $tok->value];
                $this->tokenStream->next();
            } while (! $this->tokenStream->isEOF());
            $this->tokens = $tokens;
        }
        return $this->tokens;
    }
    
    protected function getFormulas() : array
    {
        if ($this->formulas === null) {
            $forms = [];
            $delim = AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER;
            $tokens = $this->getTokens();
            $buffer = null;
            for ($i = 0; $i < count($tokens); $i++) {
                $token = $tokens[$i];
                //if token is preceeded by a ':' its an aggregation not a formula
                if ($token['name'] && $tokens[$i-1]['punctuation'] !== ':') {
                    //if the token is followd by a '.' ist a formula with namespace, therefor buffer the token
                    if ($tokens[$i+1]['punctuation'] === $delim) {
                        $buffer .= $token['name'] . $delim;
                        continue;
                    }
                    if ($tokens[$i+1]['punctuation'] === '(') {
                        if ($tokens[$i-1]['punctuation'] === $delim && $buffer) {
                            $forms[] = $buffer . $token['name'];
                        } else {
                            $forms[] = $token['name'];
                        }
                        $buffer = null;
                    }
                }
            }            
            $this->formulas = $forms;
        }
        return $this->formulas;
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getFormulaName() : ?string
    {
        if ($this->formName === null) {
            $this->formName = $this->getFormulas()[0];
        }
        return $this->formName;
    }
    
    /**
     * 
     * @return array
     */
    public function getNestedFormulas() : array
    {
        if ($this->nestedForms === null) {
            $nestedForms = $this->getFormulas();
            array_shift($nestedForms); 
            $this->nestedForms = $nestedForms;
        }
        return $this->nestedForms;
    }
    
    /**
     * 
     * @return array
     */
    public function getArguments() : array
    {
        if ($this->arguments === null) {
            $tokens = $this->getTokens();
            $arguments = [];
            $buffer = null;
            for ($i = 0; $i < count($tokens); $i++) {
                $token = $tokens[$i];
                if ($token['name']) {
                    //if the token is followed by a ':' its an attribute alias with an aggregation, therefor buffer the token
                    if ($tokens[$i+1]['punctuation'] === ':') {
                        $buffer .= $token['name'] . ':';
                    } elseif ($tokens[$i+1]['punctuation'] !== '(' && $tokens[$i+1]['punctuation'] !== '.') {
                        $arguments[] = $buffer . $token['name'];
                        $buffer = null;
                    } else {
                        $buffer = null;
                    }
                }
            }
            $this->arguments = $arguments;
        }
        return $this->arguments;
    }
    
    /**
     * 
     * @return string
     */
    public function getExpression() : string
    {
        return $this->tokenStream->getExpression();
    }
}