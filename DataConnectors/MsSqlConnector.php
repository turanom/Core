<?php
namespace exface\Core\DataConnectors;

use exface\Core\Exceptions\DataSources\DataConnectionFailedError;
use exface\Core\Exceptions\DataSources\DataConnectionTransactionStartError;
use exface\Core\Exceptions\DataSources\DataConnectionCommitFailedError;
use exface\Core\Exceptions\DataSources\DataConnectionRollbackFailedError;
use exface\Core\CommonLogic\DataQueries\SqlDataQuery;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\Core\ModelBuilders\MsSqlModelBuilder;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Interfaces\Exceptions\DataQueryExceptionInterface;
use exface\Core\Exceptions\DataSources\DataQueryConstraintError;
use exface\Core\CommonLogic\UxonObject;

/**
 * Microsoft SQL Server connector via the official sqlsrv PHP extension.
 * 
 * Apart from the typical SQL connection config syntax you can also explicitly set the `connection_options` 
 * as described here: https://docs.microsoft.com/en-us/sql/connect/php/connection-options.
 * 
 * Also note, that the connector handles SQL server warnings as errors by default. If you wish to suppress
 * error messages for warnings, set `warnings_return_as_errors` to `false`. See the official documentation
 * for more details: https://www.php.net/manual/en/function.sqlsrv-configure.php.
 *
 * @author Andrej Kabachnik
 *
 */
class MsSqlConnector extends AbstractSqlConnector
{
    private $connectionInfo = [];
    
    private $dBase = null;
    
    private $warningsReturnAsErrors = false;
    
    private $resultCounter = null;
    
    private $multiqueryResults = null;
    
    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performConnect()
     */
    protected function performConnect()
    {
        $connectInfo = $this->getConnectionOptions();
        $connectInfo["Database"] = $this->getDatabase();
        $connectInfo["CharacterSet"] = $this->getCharacterSet();
        $connectInfo['ReturnDatesAsStrings'] = $this->getConnectionOptions()['ReturnDatesAsStrings'] ?? true;
        if ($this->getUser()) {
            $connectInfo["UID"] = $this->getUser();
        }
        if ($this->getPassword()) {
            $connectInfo["PWD"] = $this->getPassword();
        }
        
        if (function_exists('sqlsrv_connect') === false) {
            throw new DataConnectionFailedError($this, 'PHP function "sqlsrv_connect" not available!', '76BJXFH');
        }
        
        if ($this->getWarningsReturnAsErrors() === false) {
            if(sqlsrv_configure("WarningsReturnAsErrors", 0) === false) {
                throw new DataConnectionFailedError($this, 'PHP function "sqlsrv_connect" not available!', '76BJXFH');
            }
        }
        
        if (! $conn = sqlsrv_connect($this->getHost() . ($this->getPort() ? ', ' . $this->getPort() : ''), $connectInfo)) {
            throw new DataConnectionFailedError($this, "Failed to create the database connection! " . $this->getLastErrorMessage());
        } else {
            $this->setCurrentConnection($conn);
        }
    }
    
    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performDisconnect()
     */
    protected function performDisconnect()
    {
        if (($conn = $this->getCurrentConnection()) !== null) {
            @sqlsrv_close($conn);
        }
    }
    
    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performQuery()
     *
     * @param SqlDataQuery $query
     */
    protected function performQuerySql(SqlDataQuery $query)
    {
        $sql = $query->getSql();
        $this->resultCounter = null;
        if ($query->isMultipleStatements()) {
            $stmtNo = 0;
            $this->resultCounter = 0;
            
            $stmt = sqlsrv_query($this->getCurrentConnection(), $sql);
            if ($stmt === false) {
                throw $this->createQueryError($query, 'SQL multi-query statement ' . ($stmtNo + 1) . ' failed! ' . $this->getLastErrorMessage());
            } else {
                $query->setResultResource($stmt);
            }
            
            // Consume the first result without calling sqlsrv_next_result.
            $this->resultCounter = max(sqlsrv_rows_affected($stmt), 0);
            $this->multiqueryResults[$stmtNo] = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $this->multiqueryResults[$stmtNo][] = $row;
            }
            
            // Move to the next result and display results.
            $next_result = sqlsrv_next_result($stmt);
            while ($next_result === true) {
                $stmtNo++;
                $next_result = sqlsrv_next_result($stmt);
                $this->resultCounter += max(sqlsrv_rows_affected($stmt), 0);
                $this->multiqueryResults[$stmtNo] = [];
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $this->multiqueryResults[$stmtNo][] = $row;
                }
            }
            if($next_result === false) {
                throw $this->createQueryError($query, 'SQL multi-query statement ' . ($stmtNo+1) . ' failed! ' . $this->getLastErrorMessage());
            }
        } else {
            if (StringDataType::startsWith($sql, 'INSERT', false) === true) {
                $sql .= '; SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME';
            }
            if (! $result = sqlsrv_query($this->getCurrentConnection(), $sql)) {
                throw $this->createQueryError($query, "SQL query failed! " . $this->getLastErrorMessage());
            } else {
                $query->setResultResource($result);
            }
        }
        return $query;
    }
    
    /**
     *
     * @param DataQueryInterface $query
     * @param string $message
     * @return DataQueryExceptionInterface
     */
    protected function createQueryError(DataQueryInterface $query, string $message = null) : DataQueryExceptionInterface
    {
        $err = $this->getLastError();
        if ($message === null) {
            $message = $this->getLastErrorMessage();
        }
        
        switch ($err['code']) {
            case 2627:
            case 2601:
                return new DataQueryConstraintError($query, $message, '73II64M');
            default:
                return new DataQueryFailedError($query, $message, '6T2T2UI');
        }
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\SqlDataConnectorInterface::getInsertId()
     */
    public function getInsertId(SqlDataQuery $query)
    {
        $id = "";
        $resource = $query->getResultResource();
        if ($resource) {
            sqlsrv_next_result($resource);
            sqlsrv_fetch($resource);
            $id = sqlsrv_get_field($resource, 0);
        }
        return $id;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\SqlDataConnectorInterface::getAffectedRowsCount()
     */
    function getAffectedRowsCount(SqlDataQuery $query)
    {
        if (! $stmt = $query->getResultResource()) {
            return null;
        }
        if ($this->resultCounter !== null) {
            return $this->resultCounter;
        }
        $cnt = sqlsrv_rows_affected($stmt);
        // sqlsrv_rows_affected() can return FALSE in case of an error accoring to the docs and -1
        // if no counting was possible.
        switch (true) {
            case $cnt === false:
                if ($err = $this->getLastErrorMessage()) {
                    throw new DataQueryFailedError($query, "Cannot count affected rows in SQL query: " . $err, '6T2TCL6');
                } else {
                    return null;
                }
            case $cnt === -1:
                return null;
        }
        return $cnt;
    }
    
    /**
     *
     * @return array|NULL
     */
    protected function getLastError() : ?array
    {
        return $this->getErrors()[0] ?? null;
    }
    
    /**
     * 
     * @return string
     */
    protected function getLastErrorMessage() : string
    {
        $err = $this->getLastError();
        if ($err === null) {
            return 'Unknown SQL error';
        }
        
        $code = $err['code'];
        $msg = $err['message'];
        
        // Workaround for strang error in some multi-sequence queries
        if ($msg === '[Microsoft][ODBC Driver Manager] Function sequence error') {
            $errors = $this->getErrors();
            if (count($errors) > 1) {
                for ($i = 1; $i < count($errors); $i++) {
                    $msg = rtrim($msg, " .") . '. ' . $errors[$i]['message'];
                }
            }
        }
        
        // Remove error origin markers
        $msg = str_replace([
            '[Microsoft]',
            '[ODBC Driver Manager]',
            '[SQL Server]'
        ], '', $msg);
        $msg = preg_replace('/\\[ODBC Driver \\d+ for SQL Server\\]/', '', $msg);
        
        return $msg . ($code ? ' (code ' . $code . ')' : '');
    }
    
    /**
     *
     * @return mixed
     */
    protected function getErrors()
    {
        if ($this->getWarningsReturnAsErrors()) {
            return sqlsrv_errors();
        } else {
            return sqlsrv_errors(SQLSRV_ERR_ERRORS);
        }
    }
    
    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\DataConnectors\AbstractSqlConnector::makeArray()
     */
    public function makeArray(SqlDataQuery $query)
    {
        $rs = $query->getResultResource();
        $array = [];
        if (! $rs) {
            return $array;
        }
        if ($query->isMultipleStatements() && ! empty($this->multiqueryResults)) {
            foreach ($this->multiqueryResults as $rows) {
                if (! empty($rows)) {
                    return $rows;
                }
            }
        } else {
            while ($row = sqlsrv_fetch_array($rs, SQLSRV_FETCH_ASSOC)) {
                $array[] = $row;
            }
        }
        return $array;
    }
    
    public function transactionStart()
    {
        // Do nothing if the autocommit option is set for this connection
        if ($this->getAutocommit()) {
            return $this;
        }
        
        if (! $this->transactionIsStarted()) {
            // Make sure, the connection is established
            if (! $this->isConnected()) {
                $this->connect();
            }
            if (! sqlsrv_begin_transaction($this->getCurrentConnection())) {
                throw new DataConnectionTransactionStartError($this, 'Cannot start transaction in "' . $this->getAliasWithNamespace() . '": ' . $this->getLastErrorMessage(), '6T2T2JM');
            } else {
                $this->setTransactionStarted(true);
            }
        }
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::transactionCommit()
     */
    public function transactionCommit()
    {
        // Do nothing if the autocommit option is set for this connection
        if ($this->getAutocommit()) {
            return $this;
        }
        
        // Do nothing if no transaction was started - there is nothing to commit.
        if ($this->transactionIsStarted() === false) {
            return $this;
        }
        
        if (! sqlsrv_commit($this->getCurrentConnection())) {
            throw new DataConnectionCommitFailedError($this, 'Cannot commit transaction in "' . $this->getAliasWithNamespace() . '": ' . $this->getLastErrorMessage(), '6T2T2O9');
        } else {
            $this->setTransactionStarted(false);
        }
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::transactionRollback()
     */
    public function transactionRollback()
    {
        // Throw error if trying to rollback a transaction with autocommit enabled
        if ($this->getAutocommit()) {
            throw new DataConnectionRollbackFailedError($this, 'Cannot rollback transaction in "' . $this->getAliasWithNamespace() . '": The autocommit options is set to TRUE for this connection!');
        }
        
        // Do nothing if no transaction was started - no changes to roll back.
        if ($this->transactionIsStarted() === false) {
            return $this;
        }
        
        if (! sqlsrv_rollback($this->getCurrentConnection())) {
            throw new DataConnectionRollbackFailedError($this, $this->getLastErrorMessage(), '6T2T2S1');
        } else {
            $this->setTransactionStarted(false);
        }
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\SqlDataConnectorInterface::freeResult()
     */
    public function freeResult(SqlDataQuery $query)
    {
        if (is_resource($query->getResultResource())) {
            sqlsrv_free_stmt($query->getResultResource());
        }
    }
    
    /**
     * SQL Server user id (same as "user")
     *
     * @deprecated use setUser()
     * @param string $value
     * @return MsSqlConnector
     */
    protected function setUID($value)
    {
        return $this->setUser($value);
    }
    
    /**
     * The password for the connection (same as "password")
     *
     * @deprecated use setPassword()
     * @param string $value
     * @return MsSqlConnector
     */
    protected function setPWD($value)
    {
        return $this->setPassword($value);
    }
    
    /**
     * The server name for the connection (same as "host")
     *
     * @deprecated use setHost()
     * @param string $value
     * @return MsSqlConnector
     */
    protected function setServerName($value)
    {
        return $this->setHost($value);
    }
    
    public function getDatabase()
    {
        return $this->dBase ?? $this->getConnectionOptions()['Database'];
    }
    
    /**
     * The database to connect to
     *
     * @uxon-property database
     * @uxon-type string
     *
     * @param string $value
     * @return \exface\Core\DataConnectors\MsSqlConnector
     */
    public function setDatabase($value)
    {
        $this->dBase = $value;
        return $this;
    }
    
    /**
     * The database name to connect to (same as "database")
     *
     * @deprecated use setDatabase()
     * @param string $value
     * @return MySqlConnector
     */
    protected function setDbase($value)
    {
        return $this->setDatabase($value);
    }
    
    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\DataConnectors\AbstractSqlConnector::exportUxonObject()
     */
    public function exportUxonObject()
    {
        $uxon = parent::exportUxonObject();
        $uxon->setProperty('Database', $this->getDatabase());
        return $uxon;
    }
    
    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\DataConnectors\AbstractSqlConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new MsSqlModelBuilder($this);
    }
    
    /**
     * The character set to be used in this connection (same as "character_set")
     *
     * @deprecated use setCharacterSet()
     * @param string $value
     * @return MySqlConnector
     */
    protected function setCharset($value)
    {
        return $this->setCharacterSet($value);
    }
    
    /**
     *
     * @return bool
     */
    protected function getWarningsReturnAsErrors() : bool
    {
        return $this->warningsReturnAsErrors;
    }
    
    /**
     * Set to TRUE to make the connection throw exceptions on SQL Server warning messages.
     *
     * See https://docs.microsoft.com/en-us/sql/connect/php/how-to-handle-errors-and-warnings-using-the-sqlsrv-driver
     * for details.
     *
     * @uxon-property warnings_return_as_errors
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param bool $value
     * @return MsSqlConnector
     */
    public function setWarningsReturnAsErrors(bool $value) : MsSqlConnector
    {
        $this->warningsReturnAsErrors = $value;
        return $this;
    }
    
    /**
     * 
     * @return array
     */
    protected function getConnectionOptions() : array
    {
        return $this->connectionInfo;
    }
    
    /**
     * SQL server PHP driver connection options for advanced configuration
     * 
     * See https://docs.microsoft.com/en-us/sql/connect/php/connection-options for details.
     * 
     * @uxon-property connection_options
     * @uxon-type object
     * @uxon-template {"":""}
     * 
     * @link https://docs.microsoft.com/en-us/sql/connect/php/connection-options
     * @param UxonObject|array $value
     * @return MsSqlConnector
     */
    public function setConnectionOptions($value) : MsSqlConnector
    {
        if ($value instanceof UxonObject) {
            $opts = $value->toArray();
        } else {
            $opts = $value;
        }
        
        foreach ($opts as $opt => $val) {
            switch ($opt) {
                case 'TransactionIsolation':
                    $opts[$opt] = constant($val);
                    break;
            }
        }
        
        $this->connectionInfo = $opts;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\DataConnectors\AbstractSqlConnector::getCharacterSet()
     */
    public function getCharacterSet()
    {
        return parent::getCharacterSet() ?? $this->getConnectionOptions()['CharacterSet'];
    }
}