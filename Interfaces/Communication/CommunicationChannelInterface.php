<?php
namespace exface\Core\Interfaces\Communication;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\AliasInterface;

interface CommunicationChannelInterface extends WorkbenchDependantInterface, iCanBeConvertedToUxon, AliasInterface
{
    public function send(EnvelopeInterface $envelope) : CommunicationReceiptInterface;
    
    public function getName() : string;
    
    public function getConnection() : ?CommunicationConnectionInterface;
}