<?php
/**
 * Custom Database Wrapper
 * @abstract Class to interact with mysqli.
 * 
 * @todo: async
 * 
 **/
//ini_set('mysqli.reconnect', 'On'); // for ping
// not in class, for security purposes

const DB_ASSOC = MYSQLI_ASSOC;
const DB_BOTH = MYSQLI_BOTH;
const DB_NUM = MYSQLI_NUM;


function onfetch($stmt, callable $callback) {
    if ($stmt->num_rows > 0) {
        $response = array();
        while($row = $stmt->fetch_array(MYSQLI_BOTH)) {
            $result = $row;
            try {
                $response[] = \call_user_func_array($callback, array($result, $response));
            } catch (\Exception $e) {
                return $e;
            }
        }
        return $response;
    }
}

trait DBextras // connection-independent tools
{
    /**
     * getQuery
     * returns query in string. for debug purposes.
     * use to debug runQuery statements
     * @return {string}
     */

    public function getQuery()
    {
        $args = \func_get_args();
        if (\count($args) == 1){
            echo (string) $args[0];
        } else {
            $query = $args[0];
            for($i=1; $i < count($args); $i++){
                $query = \preg_replace('/[?]/', "'" . $args[$i] . "'" , $query, 1);
            }
            return $query;
        }
    }
    public function toJSON($stmt, $mode = DB_ASSOC)
    {
        if (\gettype($stmt) != 'object') {
            return \json_encode([]);
        }

        $json = [];
        if ($stmt->num_rows > 0) {
            while($row = $stmt->fetch_array($mode)) {$json[] = $row;}
        }
        return \json_encode($json);
    }
    
    //fetch an array from executed SQL query. mode can also be numeric (MYSQLI_NUM) or both (MYSQLI_BOTH)
    public function fetch($stmt, $mode = MYSQLI_ASSOC)
    {
        if ($stmt->num_rows > 0) {
            return $stmt->fetch_array($mode);
        } else {
            return array();
        }
    }
    
    public function fetchAll($stmt, $mode = MYSQLI_ASSOC) {
        if ($stmt->num_rows > 0) {
            return $stmt->fetch_all($mode);
        } else {
            return array();
        }
    }

}

class Database
{
    //database vars
    private 
        $_link = null,
        $_waitForCommit = false;

    /**
     * DBextras
     * only include if you want ease of access to conversion of objects, debug the query, etc.
     */
    use DBextras; // include methods
    
    //  Methods Declaration
    public function __construct($host, $database, $username, $password, $port =  3306)
    {
        $user = $username !== null ? $username : ini_get('mysqli.default_user');
        $pass = $password !== null ? $password : ini_get('mysqli.default_pw');
        $prt = $port !== null ? $port : ini_get('mysqli.default_port');
        \mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR); // throw exceptions on connection or query error
        try {
            $db = \mysqli_connect($host,$user,$pass,$database, $prt);
            $db->set_charset("utf8mb4"); // changed from utf8 to utf8mb4, reason: full int8n support
            $this->_link = $db;
        } catch( Exception $e ) {
            return false;
        }
    }
    
    public function close()
    {
        if ($this->_link != null) { return $this->_link->close(); }
    }
    
    function __destruct()
    {
        $this->close();
    }

    public function runQuery($sql)
    {
        //$this->ping();          // reconnects if connection is closed
        $args = \func_get_args();
        \array_shift($args);

        try {
            // changed 22-01-2015, you can now insert null values natively
            foreach($args as $val){
                if (gettype($val) != "integer") {
                    $sql = ($val != null ? \preg_replace('/[?]/', "'" . $this->escapeString($val) . "'" , $sql, 1) : \preg_replace('/[?]/', 'null' , $sql, 1));
                } else {
                    $sql = \preg_replace('/[?]/',  $val , $sql, 1);

                }
            }
        } catch(\Exception $e) {
            echo "Error binding. Check number of parameters";
        }
        
        $exec = $this->query($sql);
        if ($exec == false) {
            echo \mysqli_error($this->_link) . ' ' . \mysqli_errno($this->_link);
        }
        return $exec;
    }

    private function getType(&$var) {
        $t = gettype($var);
        if (($t === 'boolean') || ($t === 'integer')) {
            return 'i';
        } elseif ($t === 'blob') {
            return 'b';
        } elseif ($t === 'double') {
            return 'd';
        } else {
            // string, null & undefined
            return 's';
        }
    }

    public function runBinded($sql) {
        $args = \func_get_args();
        \array_shift($args);
        // else
        if (count($args) == 0) {
            return $this->query($sql);
        }
        $bind = $this->_link->prepare($sql);
        $values = [ 0 => '' ];
        foreach($args as $k => $val){
            $values[0] .= $this->getType($val);
            $values[] = &$args[$k];
        }
        \call_user_func_array([$bind, "bind_param"], $values);

        if ($bind->execute()) {
            $this->_affected = $bind->affected_rows;
            return $bind->get_result();
        } else {
            return false;
        }
    }
    
    public function getLastInserted() {
        return $this->_link->insert_id;
    }

    public function setAutoCommit($bool = TRUE) // required before complex queries --use with runQuery() & commit()
    {
        $this->_link->autocommit($bool);
    }

    public function waitForCommit($bool = TRUE) // required before complex queries --use with runQuery() & commit()
    {
        $this->_link->autocommit(!$bool);
        $this->_waitForCommit = $bool;
    }
    
    public function query($sql) {
        return $this->_link->query($sql);
    }
    
    /**
     * Unlocks table after reading the first result
     * @param type $sql
     * @return sql_result
     */
    public function queryB($sql) {
        return $this->_link->query($sql, MYSQLI_USE_RESULT);
    }    
    
    public function queryAsync($sql) {
        // fallback
        $flag = defined('MYSQLI_ASYNC') ? MYSQLI_ASYNC : MYSQLI_USE_RESULT;
        return $this->_link->query($sql, $flag);
    }
    
    public function commit() {
        $rval = $this->_link->commit();
        if ($this->_waitForCommit == true) {
            $this->_waitForCommit = false;
            $this->_link->autocommit(true);
        }
        return $rval;
    }
    
    public function escape($string) {
        return $this->_link->real_escape_string($string);
    }
    
    public function getAffectedRows() {
        return $this->_link->affected_rows;
    }
    
    public function ping() {
        return mysqli_ping($this->_link);
    }
    
    public function getError() {
        return $this->_link->error;
    }

    public function getWarnings() {
        return $this->_link->get_warnings();
    }

    public function escapeString($string) {
        return $this->_link->real_escape_string($string);
    }
    
    /*
     * load arrays into tables. tested 28OUT2014
     * useful for replacing lots of INSERT query'0s
     * 
     * this very method = 0.4 ms / query @ igor first server - 1000 items inserted
     * traditional method = 39ms / query @ igor first server - 1000 items inserted
     * transaction method = 0.9ms / query (with setAutoCommit(false) & commit) @ igor first server - 1000 items inserted
     * 
     * how to use:
     * ````
     * $arr = array();
     * $arr[] = array('name' => 'wally', 'location' => 'whereami');
     * $arr[] = array('name' => 'solar system', 'location' => 'universe');
     * [...]
     * $dbobj->bulkload('somedb.entities', $arr);
     * ´´´´
     */
    public function bulkLoad($table, &$data, $options = []) { // works. tested 28OUT2014
        // set file location in /dev/shm (ramdisk)

        // make options local
        extract($options);

        if (!isset($file)) {
            $file = '/dev/shm/mybt' . mt_rand() . '.txt';
        }

        echo gettype($data);
        if (!is_array($data)) {
            trigger_error('First argument "queries" must be an array',E_USER_ERROR);
            return false;
        }
        if (empty($table)) {
            trigger_error('No insert table specified',E_USER_ERROR);
            return false;
        }
        if (empty($data)) {
            return false;
        }
        
        $buf = '';
        foreach($data as $i=>$row) {
            $buf .= implode(':::,', $row)."^^^\n";
        }
        
        // save in ramdisk
        if (!@\file_put_contents($file, $buf)) {
            trigger_error('Cant write to buffer file: "'.$file.'"', E_USER_ERROR);
            return false;
        }
        $fields = implode(', ', array_keys($row));
        
        // bulk load
        $this->_link->query("LOAD DATA INFILE '${file}' "
                . "INTO TABLE ${table} "
                . "FIELDS TERMINATED BY ':::,' "
                . "LINES TERMINATED BY '^^^\\n' "
                . "(${fields})");
        @\unlink($file);
        return true;
    }
}
