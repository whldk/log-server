<?php
date_default_timezone_set('PRC'); 
/**
 * @ignore
 */
class db
{

    private $db;

    private $tbl;

    private $sql_type = false;

    private $string = array();

    public $counter = 0;

    public $cache_counter = 0;

    private $database = '';

    private $user = '';

    private $pwd = '';

    private $dbname = '';

    /**
     * Wildcards for matching any (%) or exactly one (_) character within LIKE
     * expressions
     */
    var $any_char;

    var $one_char;

    function init($database, $user, $pwd, $dbname)
    {
        $this->db = mysqli_connect($database, $user, $pwd, $dbname);
        if ($this->db) {
            $this->db->query("SET NAMES 'utf8'");
        } else {
            die('connect db server failed');
        }
        // Do not change this please! This variable is used to easy the use of
        // it - and is hardcoded.
        $this->any_char = chr(0) . '%';
        $this->one_char = chr(0) . '_';
        
        $this->database = $database;
        $this->user = $user;
        $this->pwd = $pwd;
        $this->dbname = $dbname;
        
        return true;
    }

    function reconnect()
    {
        $this->init($this->database, $this->user, $this->pwd, $this->dbname);
        
        return true;
    }

    function real_escape_string($var)
    {
        if (is_null($var)) {
            return 'NULL';
        } else 
            if (is_string($var)) {
                return "'" . $this->db->real_escape_string($var) . "'";
            } else {
                return (is_bool($var)) ? intval($var) : $var;
            }
    }

    /**
     * Correctly adjust LIKE expression for special characters
     * Some DBMS are handling them in a different way
     *
     * @param string $expression
     *            The expression to use. Every wildcard is escaped, except
     *            $this->any_char and $this->one_char
     * @return string LIKE expression including the keyword!
     */
    function sql_like_expression($expression)
    {
        $expression = str_replace(array(
            '_',
            '%'
        ), array(
            "\_",
            "\%"
        ), $expression);
        $expression = str_replace(array(
            chr(0) . "\_",
            chr(0) . "\%"
        ), array(
            '_',
            '%'
        ), $expression);
        return ('LIKE ' . $this->real_escape_string($expression) . '');
    }

    /**
     * SQL Transaction
     *
     * @access private
     */
    function sql_transaction($status = 'begin')
    {
        switch ($status) {
            case 'begin':
                $this->db->autocommit(0);
                return $this->db->query("begin");
                break;
            
            case 'commit':
                $result = $this->db->query("commit");
                $this->db->autocommit(1);
                return $result;
                break;
            
            case 'rollback':
                $result = $this->db->rollback();
                $this->db->autocommit(1);
                return $result;
                break;
        }
        
        return true;
    }

    /**
     * 执行sql语句
     *
     * @param string/array $query
     *            想要执行的sql语句或者sql语句数组，如果为数组，则自动启用回滚
     * @param int $cache_ttl
     *            缓存时间
     * @param bool $delay
     *            是否延迟执行，写入队列
     * @return 插入id/影响行数/或数组
     */
    function query($query, $cache_ttl = 0, $delay = false)
    {
        if (is_array($query)) {
            if (count($query) == 1)
                return $this->query($query[0], $cache_ttl);
            $this->sql_transaction('begin');
            $error = false;
            $i = 0;
            foreach ($query as $value) {
                $i ++;
                preg_match_all("#\{([0-9]{1,2})\}#i", $value, $b);
                $value = str_replace("{" . $b[1][0] . "}", $a[$b[1][0]], $value);
                $result = $this->query($value);
                $a[$i] = $result;
                if ($result === false)
                    $error = true;
                    // elseif ($result === 0 && checkType ( substr ( trim (
                    // $value ), 0, 1 ), 'upper' ))
                    // //如果首字母是大写，即使执行成功了，但是影响行数为0也会进行回滚。
                    // $error = true;
                if ($error) {
                    $this->sql_transaction('rollback');
                    break;
                }
            }
            if (! $error)
                $this->sql_transaction('commit');
            else {
                return false;
            }
        } else {
            
            $query = trim($query);
            if ($query == '')
                return false;
            
            $type = rtrim(strtolower(substr($query, 0, 7)));
            if (defined('DEBUG_MODE')) {
                echo $query . "\r\n";
            }
            
            for ($i = 0; $i < 2; $i ++) {
                
                $result = $this->db->query($query);
                
                if ($result === false) {
                    if (mysqli_errno($this->db) == 2006 or mysqli_errno($this->db) == 2013) {
                        $r = $this->checkConnection();
                        if ($r === true) {
                            continue;
                        }
                    }
                    return false;
                }
                break;
            }
            
            ++ $this->counter;
            if ($result === false) {
                
                return false;
            }
            $affected = $this->db->affected_rows;
            switch ($type) {
                case 'select':
                    if ($result->num_rows > 0) {
                        while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
                            $a[] = $row;
                        }
                        
                        $result->free();
                    } else
                        $a = 0;
                    break;
                case 'update':
                case 'delete':
                    if ($affected > 0)
                        $a = $affected;
                    else
                        $a = 0;
                    break;
                case 'insert':
                    if ($affected > 0)
                        $a = $this->db->insert_id ? $this->db->insert_id : - 1;
                    else
                        $a = 0;
                    break;
                case 'replace':
                    if ($affected > 1)
                        $a = $affected;
                    elseif ($affected > 0)
                        $a = $this->db->insert_id ? $this->db->insert_id : - 1;
                    break;
                default:
                    $a = $result;
            }
        }
        unset($result);
        return $a;
    }

    /**
     * Build LIMIT query
     */
    function query_limit($query, $total, $offset = 0, $cache_ttl = 0)
    {
        // if $total is set to 0 we do not want to limit the number of rows
        if ($total == 0) {
            // MySQL 4.1+ no longer supports -1 in limit queries
            $total = '18446744073709551615';
        }
        
        $query .= "\n LIMIT " . ((! empty($offset)) ? $offset . ', ' . $total : $total);
        
        return $this->query($query, $cache_ttl);
    }

    /**
     *
     * @param string $type            
     * @param array $array            
     * @return boolean string
     */
    function build_query($type, $array)
    {
        if (! is_array($array) || $type == "") {
            return false;
        }
        $type = strtolower($type);
        if ($type == 'update' || $type == 'where') {
            $values = array();
            foreach ($array as $key => $var) {
                if (strpos($key, '.')) {
                    $key = str_replace('.', '`.`', $key);
                }
                $values[] = "`$key` = " . $this->real_escape_string($var);
            }
            $query = implode(($type == 'update') ? ', ' : ' AND ', $values);
            return $query;
        }
        if ($type == 'insert') {
            foreach ($array as $key => $value) {
                $leftkey[] = $key;
                $rightvalue[] = $this->real_escape_string($value);
            }
            return "(`" . implode("`,`", $leftkey) . "`) values (" . implode(",", $rightvalue) . ")";
        }
    }

    /**
     *
     * @param string $str            
     */
    function select($str = "")
    {
        $str = trim($str);
        if ($str == "")
            $strArray[] = '*';
        else
            $strArray = explode(',', $str);
        if ($this->sql_type == 'select') {
            $strArray = array_merge($this->string['tbl'], $strArray);
            $strArray = array_unique($strArray);
        } else
            $this->string = array();
        
        if (in_array('*', $strArray)) {
            unset($strArray);
            $strArray[] = "*";
        }
        $this->sql_type = 'select';
        $this->string['tbl'] = $strArray;
        return $this;
    }

    /**
     */
    function delete()
    {
        $this->sql_type = 'delete';
        $this->string = array();
        $this->string['tbl'][] = "*";
        return $this;
    }

    /**
     *
     * @param array $array            
     */
    function update($array)
    {
        if (is_array($array) && count($array) > 0)
            $str = $this->build_query('update', $array);
        else
            die('this is a wrong update array');
        $this->sql_type = 'update';
        $this->string = array();
        $this->string['tbu'] = $str;
        $this->string['tbl'][] = "*";
        return $this;
    }

    /**
     *
     * @param array $array            
     */
    function insert($array)
    {
        if (is_array($array) && count($array) > 0)
            $str = $this->build_query('insert', $array);
        else
            die('this is a wrong insert array');
        $this->sql_type = 'insert';
        $this->string = array();
        $this->string['tbi'] = $str;
        $this->string['tbl'][] = "*";
        return $this;
    }

    /**
     *
     * @param array $array            
     */
    function replace($array)
    {
        if (is_array($array) && count($array) > 0)
            $str = $this->build_query('insert', $array);
        else
            die('this is a wrong insert array');
        $this->sql_type = 'replace';
        $this->string = array();
        $this->string['tbi'] = $str;
        $this->string['tbl'][] = "*";
        return $this;
    }

    /**
     * insert data
     *
     * @param array $array            
     */
    function multi_insert($array)
    {
        if (! is_array($array) || ! is_array($array['key']) || ! is_array($array['value']) || sizeof($array['value']) < 1)
            die('this is a wrong multi_insert array.');
        $insertStr = "(`" . implode("`,`", $array['key']) . "`) VALUES ";
        foreach ($array['value'] as $row) {
            $insertArray[] = "(" . implode(",", array_map(array(
                $this,
                'real_escape_string'
            ), $row)) . ")";
        }
        $insertQuery = $insertStr . implode(",", $insertArray);
        $this->sql_type = 'insert';
        $this->string = array();
        $this->string['tbi'] = $insertQuery;
        $this->string['tbl'][] = "*";
        return $this;
    }

    /**
     * replace data
     *
     * @param array $array            
     */
    function multi_replace($array)
    {
        if (! is_array($array) || ! is_array($array['key']) || ! is_array($array['value']) || sizeof($array['value']) < 1)
            die('this is a wrong multi_insert array.');
        $insertStr = "(`" . implode("`,`", $array['key']) . "`) VALUES ";
        foreach ($array['value'] as $row) {
            $insertArray[] = "(" . implode(",", array_map(array(
                $this,
                'real_escape_string'
            ), $row)) . ")";
        }
        $insertQuery = $insertStr . implode(",", $insertArray);
        $this->sql_type = 'replace';
        $this->string = array();
        $this->string['tbi'] = $insertQuery;
        $this->string['tbl'][] = "*";
        return $this;
    }

    function group_by($str)
    {
        $str = trim($str);
        if ($str == "")
            die('please check group_by in sql');
        if (! $this->sql_type)
            die('please check sql');
        if (isset($this->string['tbg']) && check_void($this->string['tbg']))
            $this->string['tbg'] .= " , " . $str;
        else
            $this->string['tbg'] = $str;
        return $this;
    }

    function table($str)
    {
        if (is_array($str)) {
            foreach ($str as $table_name => $alias) {
                if (is_array($alias)) {
                    foreach ($alias as $multi_alias) {
                        $table_array[] = $table_name . ' ' . $multi_alias;
                    }
                } else {
                    $table_array[] = $table_name . ' ' . $alias;
                }
            }
            if (isset($this->string['tbn']) && check_void($this->string['tbn'])) {
                $strArray = array_merge($this->string['tbn'], $table_array);
                $strArray = array_unique($table_array);
            }
            $this->string['tbn'] = $strArray;
        } else {
            $str = trim($str);
            if ($str == "")
                die('please check table');
            if (! $this->sql_type)
                die('please check sql');
            $strArray = explode(',', $str);
            if (isset($this->string['tbn']) && check_void($this->string['tbn'])) {
                $strArray = array_merge($this->string['tbn'], $strArray);
                $strArray = array_unique($strArray);
            }
            $this->string['tbn'] = $strArray;
        }
        return $this;
    }

    /**
     *
     * @param string $str            
     * @param string $type            
     */
    function where($str, $type = 'and')
    {
        if (is_array($str)) {
            $this->string['tbw'] = $this->build_query("where", $str);
        } else {
            $str = trim($str);
            if ($str == "")
                die('please check where in sql');
            if (! $this->sql_type)
                die('please check sql');
            if (isset($this->string['tbw']) && check_void($this->string['tbw']))
                $this->string['tbw'] .= " " . $type . " (" . $str . ")";
            else
                $this->string['tbw'] = $str;
        }
        return $this;
    }

    /**
     *
     * @param string $str            
     * @param string $type            
     */
    function where_in($field, $array, $negate = false, $allow_empty_set = false, $type = 'and')
    {
        $str = $this->sql_in_set($field, $array, $negate = false, $allow_empty_set = false);
        if (! $this->sql_type)
            die('please check sql');
        if (isset($this->string['tbw']) && check_void($this->string['tbw']))
            $this->string['tbw'] .= " " . $type . " (" . $str . ")";
        else
            $this->string['tbw'] = $str;
        return $this;
    }

    /**
     *
     * @param
     *            $rows
     * @param
     *            $offset
     */
    function limit($rows, $offset = 0)
    {
        if (! is_numeric($offset) || ! is_numeric($rows)) {
            $offset = 0;
            $rows = 30;
        }
        $this->string['limit']['s'] = $offset;
        $this->string['limit']['o'] = $rows;
        return $this;
    }

    /**
     *
     * @param string $str            
     */
    function order($str)
    {
        $str = trim($str);
        if (strlen($str) > 0) {
            if (isset($this->string['tbo']) && check_void($this->string['tbo']))
                $this->string['tbo'] .= "," . $str;
            else
                $this->string['tbo'] = $str;
        }
        return $this;
    }

    /**
     * Generate or execute sql query
     *
     * @param bool $trigger
     *            Return query statement when its value is true.
     * @param int $ttl
     *            cache time. Disable it by set to 0.
     * @return query statement or this query result.
     */
    function queryAll($trigger = false, $ttl = 0, $delay = false)
    {
        if (! $this->sql_type)
            die('please check sql');
        if (count($this->string['tbl']) > 0 && count($this->string['tbn']) > 0) {
            switch ($this->sql_type) {
                case 'select':
                    $query = "SELECT " . implode(',', $this->string['tbl']) . " FROM " . implode(',', $this->string['tbn']);
                    if (isset($this->string['tbw']) && check_void($this->string['tbw']))
                        $query .= " WHERE " . $this->string['tbw'];
                    if (isset($this->string['tbg']) && check_void($this->string['tbg']))
                        $query .= " GROUP BY " . $this->string['tbg'];
                    if (isset($this->string['tbo']) && check_void($this->string['tbo']))
                        $query .= " ORDER BY " . $this->string['tbo'];
                    if (isset($this->string['limit']) && check_void($this->string['limit']))
                        $query .= " LIMIT " . $this->string['limit']['s'] . "," . $this->string['limit']['o'];
                    break;
                case 'delete':
                    $query = "DELETE FROM " . implode(',', $this->string['tbn']);
                    if (isset($this->string['tbw']) && check_void($this->string['tbw']))
                        $query .= " WHERE " . $this->string['tbw'];
                    if (isset($this->string['limit']) && check_void($this->string['limit']))
                        $query .= " LIMIT " . $this->string['limit']['o'];
                    break;
                case 'insert':
                    $query = "INSERT INTO " . implode(',', $this->string['tbn']) . " " . $this->string['tbi'];
                    break;
                case 'replace':
                    $query = "REPLACE INTO " . implode(',', $this->string['tbn']) . " " . $this->string['tbi'];
                    break;
                case 'update':
                    $query = "UPDATE " . implode(',', $this->string['tbn']) . " SET " . $this->string['tbu'];
                    if (isset($this->string['tbw']) && check_void($this->string['tbw']))
                        $query .= " WHERE " . $this->string['tbw'];
                    if (isset($this->string['limit']) && check_void($this->string['limit']))
                        $query .= " LIMIT " . $this->string['limit']['o'];
                    break;
                default:
                    die('please check sql');
                    break;
            }
            $this->clearAll();
            
            return $trigger ? $query : $this->query($query, $ttl);
        }
    }

    /**
     * Build IN or NOT IN sql comparison string, uses <> or = on single element
     * arrays to improve comparison speed
     *
     * @access public
     * @param string $field
     *            the sql column that shall be compared
     * @param array $array
     *            values that are allowed (IN) or not allowed (NOT IN)
     * @param bool $negate
     *            NOT IN (), false for IN () (default)
     * @param bool $allow_empty_set
     *            allow $array to be empty, this function will return 1=1 or 1=0
     *            then. Default to false.
     */
    function sql_in_set($field, $array, $negate = false, $allow_empty_set = false)
    {
        if (! sizeof($array)) {
            if (! $allow_empty_set) {
                // Print the backtrace to help identifying the location of the
                // problematic code
                return '1=1';
            } else {
                // NOT IN () actually means everything so use a tautology
                if ($negate) {
                    return '1=1';
                }  // IN () actually means nothing so use a contradiction
else {
                    return '1=0';
                }
            }
        }
        
        if (! is_array($array)) {
            $array = array(
                $array
            );
        }
        
        if (sizeof($array) == 1) {
            @reset($array);
            $var = current($array);
            
            return $field . ($negate ? ' <> ' : ' = ') . $this->real_escape_string($var);
        } else {
            return $field . ($negate ? ' NOT IN ' : ' IN ') . '(' . implode(', ', array_map(array(
                $this,
                'real_escape_string'
            ), $array)) . ')';
        }
    }

    function clearAll()
    {
        $this->sql_type = false;
        $this->string = array();
    }

    function sql_close()
    {
        mysqli_close($this->db);
    }

    protected function checkConnection()
    {
        if (! @$this->ping()) {
            $this->sql_close();
            return $this->reconnect();
        }
        return true;
    }

    function ping()
    {
        if (! mysqli_ping($this->db)) {
            return false;
        } else {
            return true;
        }
    }

    function __destruct()
    {

    }
}

function check_void()
{
    return true;
}