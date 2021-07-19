<?php
include 'db.php';
define('DEBUG_MODE',false);

class LoginTestController
{
    protected $logdb = null;

    public function __construct()
    {
       $this->logdb = new db();
       $this->logdb->init('127.0.0.1', 'root', '123456', 'beepool_log');
    }

    /**
     * 统计loginCount 从00:00 到 24:00 统计 （每分钟数据自动更新）
     * @param string $coin
     * @param int $page
     * @param int $size
     * @return array
     */
    public function LoginCount(string $coin, int $day = 1, $order = 'desc',  int $page, int $size) : array
    {
        $today = date('Y-m-d');
        if ($day === 1) {
            //查询过去三天的数据 [-1,-2,-3]
            $start = strtotime($today);
            $end = strtotime("+{$day}  day", $start);
        } else {
            //查询昨天、前天的数据
            $curr = strtotime($today);
            $start = strtotime("{$day}  day", $curr);
            $end = strtotime("+1 day", $start);
        }
        $model = 'proxy-'.$coin;
        //计算总页数
        $sql = "select `wallet` from logincount where model = '{$model}' and uptime >= {$start} && uptime < {$end} group by `wallet`";
        $result = $this->logdb->query($sql);
        $count = is_array($result) ? count($result) : 0;
        $list = [
            'page' => $page,
            'page_size' => $size,
            'total' => $count,
            'total_size' => ceil($count/ $size),
            '_list' => []
        ];
        if ($count === 0) {
            return $list;
        }
        //计算分页起始
        $offset = ($page - 1) * $size;
        if ($offset < 0) $offset = 0;
        if ($size < 0 || $size > 1000) $size = 1000;
        //开始分页查询
        $sql = "select `wallet`, sum(`count`) as `count` from logincount where model = '{$model}' and uptime >= {$start} && uptime < {$end} group by `wallet` order by `count` {$order} limit {$offset}, {$size}";
        $result = $this->logdb->query($sql);
        $list['_list'] = $result;
        return $list;
    }

    /**
     * 大户登录统计情况
     * @param string $coin
     * @param int $day
     * @return mixed
     */
    public function LargeLoginCount(string $coin, int $day = 1, $order = 'desc')
    {
        $today = date('Y-m-d');
        if ($day === 1) {
            //查看当天的数据
            $start = strtotime($today);
            $end = strtotime("+{$day}  day", $start);
        } else {
            //查询过去三天的数据 [-1,-2,-3]
            $curr = strtotime($today);
            $start = strtotime("{$day}  day", $curr);
            $end = strtotime("+1 day", $start);
        }
        $model = 'proxy-'.$coin;
        $sql = "select `wallet`, sum(`count`) as `count` from largelogincount where model = '{$model}' and uptime >= {$start} && uptime < {$end} group by `wallet` order by `count` {$order}";
        $result = $this->logdb->query($sql);
        $list['_list'] = $result;
        return $list;
    }

    /**
     * 过去二十四小时的分布区间
     * @param $coin
     * @param $table
     * @return array
     */
    public function Last24hData($coin, $table)
    {
        $model = 'proxy-'.$coin;
        $time = time() - 86400; //获取过去24小时的数据情况
        $sql = "SELECT SUM(`count`) as `count`, `uptime` FROM `{$table}` WHERE `model` = '{$model}' AND `uptime` > {$time} GROUP BY `uptime`";
        $result = $this->logdb->query($sql);
        $data = ['x' => [],'s' => []];
        if (is_array($result) && count($result) > 0) {
             foreach ($result as $item) {
                 $x = date('m-d H:i', $item['uptime']);
                 array_push($data['x'], $x);
                 array_push($data['s'], $item['count']);
             }
        }
        return $data;
    }

    /**
     * 查看用户历史记录
     * @param $coin
     * @param $wallet
     * @param $table
     * @return array
     */
    public function WalletData($coin, $wallet, $table)
    {
        $model = 'proxy-'.$coin;
        $sql = "SELECT `count`, `uptime` FROM `{$table}` WHERE `model` = '{$model}' AND `wallet` = '{$wallet}'";
        $result = $this->logdb->query($sql);
        $data = ['x' => [],'s' => []];
        if (is_array($result) && count($result) > 0) {
            foreach ($result as $item) {
                $x = date('m-d H:i', $item['uptime']);
                array_push($data['x'], $x);
                array_push($data['s'], $item['count']);
            }
        }
        return $data;
    }

    protected static function get_diff_range($coin)
    {
        $diff_range = [
            'eth' => [
                'less100m' => 0,
                'less500m' => 0,
                'less1g' => 0,
                'less5g' => 0,
                'less30g' => 0,
                'less100g' => 0,
                'large100g' => 0
            ],
            'etc' => [
                'less100m' => 0,
                'less500m' => 0,
                'less1g' => 0,
                'less5g' => 0,
                'less30g' => 0,
                'less100g' => 0,
                'large100g' => 0
            ],
            'cfx' => [
                'less200m' => 0,
                'less1g' => 0,
                'less5g' => 0,
                'less10g' => 0,
                'less30g' => 0,
                'less100g' => 0,
                'large100g' => 0
            ],
            'rvn' => [
                'less50m' => 0,
                'less500m' => 0,
                'less1g' => 0,
                'less4g' => 0,
                'less15g' => 0,
                'large15g' => 0
            ],
            'ae' => [
                'less30g' => 0,
                'less100g' => 0,
                'less200g' => 0,
                'less1k'  => 0,
                'less4k'  => 0,
                'less7k'  => 0,
                'large7k' => 0
            ],
            'sero' => [
                'less50m' => 0,
                'less300m' => 0,
                'less500m' => 0,
                'less1g' => 0,
                'less3g' => 0,
                'large3g' => 0
            ]
        ];

        return $diff_range[$coin] ?? [];
    }

    protected static function get_user_range($coin)
    {
        $user_range = [
            'eth' => [
                'less100m' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less500m' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less1g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less5g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less30g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less100g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'large100g' => ['sub_account' => 0, 'anonymous_account' => 0],
            ],
            'etc' => [
                'less100m' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less500m' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less1g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less5g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less30g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less100g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'large100g' => ['sub_account' => 0, 'anonymous_account' => 0],
            ],
            'cfx' => [
                'less200m' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less1g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less5g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less10g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less30g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less100g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'large100g' => ['sub_account' => 0, 'anonymous_account' => 0],
            ],
            'rvn' => [
                'less50m' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less500m' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less1g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less4g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less15g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'large15g' => ['sub_account' => 0, 'anonymous_account' => 0],
            ],
            'ae' => [
                'less30g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less100g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less200g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less1k'  => ['sub_account' => 0, 'anonymous_account' => 0],
                'less4k'  => ['sub_account' => 0, 'anonymous_account' => 0],
                'less7k'  => ['sub_account' => 0, 'anonymous_account' => 0],
                'large7k' => ['sub_account' => 0, 'anonymous_account' => 0],
            ],
            'sero' => [
                'less50m' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less300m' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less500m' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less1g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'less3g' => ['sub_account' => 0, 'anonymous_account' => 0],
                'large3g' => ['sub_account' => 0, 'anonymous_account' => 0]
            ]
        ];

        return $user_range[$coin] ?? [];
    }

    public function getWalletRange($coin)
    {
        //查询过去二小时的钱包数据
        $time = 1625480587 - 86400 - 3600 * 9;

        $result = $this->logdb->select('*')->table('`walletlog`')
            ->where(" `coin` = '{$coin} '". ' and `uptime` > ' . $time)
            ->queryAll();

        if (is_array($result) && $result) {
            $diff_range = self::get_diff_range($coin);             //获取diff区间
            $user_range = self::get_user_range($coin);
            foreach ($result as $item) {
                self::count_diff($item['diff'], $coin, $item['wallet'],$diff_range, $user_range);
            }

            //各算力挖矿用户类型分布
            $line = ['x' => [], 's1' => [],  's2' => []];
            $users = self::format_data($user_range, $coin);
            $x = array_column($users, 'name');
            $line['x'] = $x;
            $series = array_column($users, 'value');
            foreach ($series as $item) {
                array_push($line['s1'], $item['sub_account']);
                array_push($line['s2'], $item['anonymous_account']);
            }

            return ['diff' => self::format_data($diff_range, $coin), 'user' => $line];
        }
        return ['diff' => [], 'user' => []];
    }

    protected static function count_diff($number, $coin, $wallet, &$range, &$user)
    {
        //获取长度
        $len = strlen($number);
        switch ($coin) {
            case 'eth':
            case 'etc':
                switch ($len) {
                    case (7 <= $len && $len < 10):
                        $m = number_format($number / pow(1000,2),2);
                        if ($m < 100) {
                            $range['less100m']++;
                            self::user_analysis($wallet,$user['less100m']);
                        } else if ($m >= 100 && $m < 500) {
                            $range['less500m']++;
                            self::user_analysis($wallet,$user['less500m']);
                        } else if ($m >= 500 && $m < 1000) {
                            $range['less1g']++;
                            self::user_analysis($wallet,$user['less1g']);
                        }
                        break;
                    case (10 <= $len && $len < 13):
                        $g = number_format($number / pow(1000,3),2);
                        if ($g < 5) {
                            $range['less5g']++;
                            self::user_analysis($wallet,$user['less5g']);
                        } else if ($g >= 5 && $g < 30) {
                            $range['less30g']++;
                            self::user_analysis($wallet,$user['less30g']);
                        } else if ($g >= 30 && $g < 100) {
                            $range['less100g']++;
                            self::user_analysis($wallet,$user['less100g']);
                        } else {
                            $range['large100g']++;
                            self::user_analysis($wallet,$user['large100g']);
                        }
                        break;
                    case (13 <= $len &&  $len < 16):
                        $range['large100g']++;
                        self::user_analysis($wallet,$user['large100g']);
                        break;
                }
                break;
            case 'cfx':
                switch ($len) {
                    case (4 <= $len && $len < 7):
                        $range['less200m']++;
                        break;
                    case (7 <= $len && $len < 10):
                        $m = number_format($number / pow(1000, 2), 2);
                        if ($m < 200) {
                            $range['less200m']++;
                        } else if ($m >= 200 && $m < 1000) {
                            $range['less1g']++;
                        }
                        break;
                    case (10 <= $len && $len < 13):
                        $g = number_format($number / pow(1000,3),2);
                        if ($g < 5) {
                            $range['less5g']++;
                        } else if ($g >= 5 && $g < 10) {
                            $range['less10g']++;
                        } else if ($g >= 10 && $g < 30) {
                            $range['less30g']++;
                        } else if ($g >=30 && $g < 100) {
                            $range['less100g']++;
                        } else if ($g >= 100) {
                            $range['large100g']++;
                        }
                        break;
                }
                break;
            case 'rvn':
                switch ($len) {
                    case (4 <= $len && $len < 7):
                        $range['less50m']++;
                        break;
                    case (7 <= $len && $len < 10):
                        $m = number_format($number / pow(1000, 2), 2);
                        if ($m < 50) {
                            $range['less50m']++;
                        } else if ($m >= 50 && $m < 500) {
                            $range['less500m']++;
                        } else if ($m >= 500 && $m < 1000) {
                            $range['less1g']++;
                        }
                        break;
                    case (10 <= $len && $len < 13):
                        $g = number_format($number / pow(1000,3),2);
                        if ($g < 4) {
                            $range['less4g']++;
                        } else if ($g >= 4 && $g < 15) {
                            $range['less15g']++;
                        }  else  {
                            $range['large15g']++;
                        }
                        break;
                }
                break;
            case 'ae':
                switch ($len) {
                    case (0 <= $len && $len < 4):
                        if ($number < 30) {
                            $range['less30g']++;
                        } else if ($number >=30 && $number < 100) {
                            $range['less100g']++;
                        } else if ($number >=100 && $number < 200) {
                            $range['less200g']++;
                        } else if ($number >=200 && $number < 1000) {
                            $range['less1k']++;
                        }
                        break;
                    case (4 <= $len && $len < 10):
                        $k = number_format($number / pow(10000,1),2);
                        if ($k < 4) {
                            $range['4k']++;
                        } else if ($k >= 4 && $k < 7) {
                            $range['7k']++;
                        } else {
                            $range['large7k']++;
                        }
                        break;
                }
                break;
            case 'sero':
                switch ($len) {
                    case (4 <= $len && $len < 7):
                        $range['less50m']++;
                        break;
                    case (7 <= $len && $len < 10):
                        $m = number_format($number / pow(1000, 2), 2);
                        if ($m < 50) {
                            $range['less50m']++;
                        } else if ($m >= 50 && $m < 300) {
                            $range['less300m']++;
                        } else if ($m >= 300 && $m < 500) {
                            $range['less500m']++;
                        } else if ($m >= 500 && $m < 1000) {
                            $range['less1g']++;
                        }
                        break;
                    case (10 <= $len && $len < 13):
                        $g = number_format($number / pow(1000,3),2);
                        if ($g < 3) {
                            $range['less3g']++;
                        } else {
                            $range['large3g']++;
                        }
                        break;
                }
                break;
        }
    }

    protected static function user_analysis($wallet, &$user)
    {

        if (strlen($wallet) > 16) {
            $user['sub_account'] += 1;
        } else {
            $user['anonymous_account'] += 1;
        }
    }


    /**
     * 算力分布、名称转换
     * @param $data
     * @param $coin
     * @return array
     */
    protected static function format_data($data, $coin)
    {
        $format_data = [];
        foreach ($data as $name => $value) {
            //过滤为0的区间
            if ($value === 0) {
                continue;
            }
            $format_data[] = [
                'name' => self::translate($name, $coin),
                'value' => $value
            ];
        }
        return $format_data;
    }

    /**
     * 分布算力、翻译
     * @param $name
     * @param $coin
     * @return mixed
     */
    protected static function translate($name, $coin)
    {
        $translate = [
            'eth' => [
                'less100m' => '< 100(M/s)',
                'less500m' => '100 ~ 500(M/s)',
                'less1g' => '500 ~ 1000(M/s)',
                'less5g' => '1 ~ 5(G/s)',
                'less30g' => '5 ~ 30(G/s)',
                'less100g' => '30 ~ 100(G/s)',
                'large100g' => '> 100(G/s)',
            ],
            'etc' => [
                'less100m' => '< 100(M/s)',
                'less500m' => '100 ~ 500(M/s)',
                'less1g' => '500 ~ 1000(M/s)',
                'less5g' => '1 ~ 5(G/s)',
                'less30g' => '5 ~ 30(G/s)',
                'less100g' => '30 ~ 100(G/s)',
                'large100g' => '> 100(G/s)',
            ],
            'cfx' => [
                'less200m' => '< 200(M/s)',
                'less1g' => '200 ~ 1000(M/s)',
                'less5g' => '1 ~ 5(G/s)',
                'less10g' => '5 ~ 10(G/s)',
                'less30g' => '10 ~ 30(G/s)',
                'less100g' => '30 ~ 100(G/s)',
                'large100g' => '> 100(G/s)',
            ],
            'rvn' => [
                'less50m' => '< 50(M/s)',
                'less500m' => '50 ~ 500(M/s)',
                'less1g' => '500 ~ 1000(M/s)',
                'less4g' => '1 ~ 4(G/s)',
                'less15g' => '4 ~ 15(G/s)',
                'large15g' => '> 15(G/s)'
            ],
            'ae'  => [
                'less30g' => '< 30(g/s)',
                'less100g' => '30 ~ 100(g/s)',
                'less200g' => '100 ~ 200(g/s)',
                'less1k'  => '200 ~ 1000(g/s)',
                'less4k'  => '1 ~ 4(K/s)',
                'less7k'  => '4 ~ 7(K/s)',
                'large7k' => '> 7 (K/s)'
            ],
            'sero'=> [
                'less50m' => '< 50(M/s)',
                'less300m' => '50 ~ 300(M/s)',
                'less500m' => '300 ~ 500(M/s)',
                'less1g' => '500 ~ 1000(M/s)',
                'less3g' => '1 ~ 3(G/s)',
                'large3g' => '> 3(G/s)'
            ]
        ];
        return $translate[$coin][$name] ?: $name;
    }

    /**
     * 换算单位格式
     * @param int $number
     * @return string
     */
    protected static function unit(int $number, string $coin, string $type)
    {
        $len = strlen($number);
        if ($coin === 'ae' && $type === 'diff') {
            switch ($len) {
                case (0 <= $len && $len < 4):
                    return $number . ' g/s';
                    break;
                case (4 <= $len && $len < 7):
                    return number_format($number / pow(10000, 1), 2) . ' K/s';
                    break;
            }
        } else {
            switch ($len) {
                case (0 <= $len && $len < 4):
                    return $number . ' H/s';
                    break;
                case (4 <= $len && $len < 7):
                    return number_format($number / pow(1000,1),2) . ' K/s';
                    break;
                case (7 <= $len && $len < 10):
                    return number_format($number / pow(1000,2),2) . ' M/s';
                    break;
                case (10 <= $len && $len < 13):
                    return number_format($number / pow(1000,3),2) . ' G/s';
                    break;
                case (13 <= $len &&  $len < 16):
                    return number_format($number / pow(1000,4),2) . ' T/s';
                    break;
                case (16 <= $len &&  $len < 19):
                    return number_format($number / pow(1000,5),2) . ' P/s';
                    break;
                case (19 <= $len &&  $len < 22):
                    return number_format($number / pow(1000,6),2) . ' E/s';
                    break;
            }
        }
    }

    public function getDiffTotal($coin, $start, $end)
    {
        $sql = "select sum(diff) as diff from walletlog where coin = '{$coin}' and  uptime > {$start} and uptime < $end";
        $res = $this->logdb->query($sql);
        return $res ? $res['0']['diff'] : 0;
    }

    public function coinNetworkDiff($coin)
    {
        $time = time() - 86400 * 3;
        $sql = "SELECT
                    COUNT(`wallet`) AS `count`,
                    CONCAT( FROM_UNIXTIME(uptime, '%d-%H'), ':00' ) AS `days`
                FROM
                    `walletlog`
                WHERE
                    `coin` = '{$coin}'
                AND `uptime` > $time
                GROUP BY `days`";

        echo $sql;die;
        $result = $this->logdb->query($sql);
        if (is_array($result) && count($result) > 0) {
            $data = [
                'x' => array_column($result, 'days'),
                's' => array_column($result, 'count')
            ];
            return $data;
        }
        return [];
        die;

        $time = time() - 86400 * 30;
        $sql = "SELECT COUNT(`wallet`) AS `count`,FROM_UNIXTIME(created_at, '%Y-%m-%d') AS `days` 
        FROM `accounts` WHERE `created_at` > {$time} and `coin` = '{$coin}' GROUP BY `days`";
        echo $sql;die;

        //初始化日期节点
        $days = [];
        $times = [0, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 22];
        foreach ($times as $time) {
            $day = date('Y-m-d', time());
            $s = strtotime($day) + $time * 3600;
            array_push($days,  $s);
            for ($i = 1; $i <= 3; $i++) {
              $ps = strtotime('-'.$i.'day',  $s);
              array_push($days, $ps);
            }
        }
        $curr = time();
        $pass3_time = time() - 86400 * 3;
        $result = [
            'x' => [],
            'y' => [],
            'unit' => [],
        ];
        sort($days, SORT_ASC);
        //进行处理
        foreach ($days as $k => &$day) {
            $h = date('H', $day);
            $curr_h = date('H', time());
            if($day > $curr || $day < $pass3_time) {
                unset($days[$k]);
            }
            if ($curr_h === $h) {
                $now = time();
                $diff = $now - $day;
                if ($diff < 1200) {
                    unset($days[$k]);
                }
                continue;
            }
        }
        foreach ($days as $k => $day) {
            $key = date('d-H:00', $day);
            array_push($result['x'], $key);
            $diff = $this->getDiffTotal($coin, $day, $day + 7200);
            if ($diff){
                $diff_unit = explode(' ', self::unit($diff, $coin, 'diff'));
                array_push($result['y'], $diff_unit[0]);
                array_push($result['unit'], $diff_unit[1]);
            } else {
                array_push($result['y'],0);
                array_push($result['unit'], '');
            }
        }
        return $result;
    }

}

$t = new LoginTestController();
//var_dump($t->LoginCount('etc', -2, 1, 100));
//var_dump($t->LargeLoginCount('eth', 1));
//var_dump($t->Last24hData('eth', 'largelogincount'));
//var_dump($t->WalletData('eth', '52f5759f59f0A6b2DdE265323aD8912da40d1809', 'logincount'));

var_dump($t->coinNetworkDiff('etc'));