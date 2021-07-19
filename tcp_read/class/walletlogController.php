<?php
class walletlogController
{
    private $logdb = null;

    public function __construct($logdb)
    {
        $this->logdb = $logdb;
    }

    /**
     * 获取过去2小时的活跃用户挖矿数据
     * @param $coin
     * @param $type
     * @param $order
     * @param $limit
     * @return array|query
     */
    public function getWalletRank($coin, $type, $order, $limit)
    {
        //查询过去二小时的钱包数据
        $time = time() - 7200;

        $result = $this->logdb->select('*')->table('`walletlog`')
            ->where(" `coin` = '{$coin} '". ' and `uptime` > ' . $time)
            ->queryAll();

        if (is_array($result) && $result) {
            $name = array_column($result, $type);
            $order = ($order === 'desc') ? SORT_DESC : SORT_ASC;
            array_multisort($name, $order, $result);
            $result = array_splice($result, 0, $limit);    //不建议返回全部数据
            array_walk($result, function (&$v) use ($coin) {
                $v['diff'] = self::unit($v['diff'], $coin, 'diff');
                $v['share'] = self::unit($v['share'], $coin, 'share');
                $v['delay'] = self::unit($v['delay'], $coin, 'delay');
                $v['reject'] = self::unit($v['reject'], $coin, 'reject');
            });
        }
        return $result;
    }


    /**
     * 获取过去2个小时用户的数据范围情况
     * @param $coin
     * @return array
     */
    public function getWalletRange($coin)
    {
        //查询过去二小时的钱包数据
        $time = time() - 7200;

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
        return ['diff' => []];
    }


    /**
     * 获取当前用户数据、与00点过后的数据做对比分析（仅限当天的涨跌幅度查看）
     * @param $coin
     * @param $field
     * @param $order
     * @param $page
     * @param $size
     * @return array
     */
    public function getWalletWave($coin, $field, $order, $page, $size)
    {
        $time = time() - 7200;

        //计算总量
        $sql = "SELECT count(`id`) as `count` FROM `walletlog` WHERE `coin` = '{$coin}' AND `uptime` > $time";
        $count = $this->logdb->query($sql);
        $count = $count ? $count[0]['count'] : 0;
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

        $sql = "SELECT
                    `coin`,`wallet`,`diff`,`share`,`delay`,`reject`,
                    IFNULL(((`diff` - `up_diff`) / `up_diff` * 100),100) AS `up_diff_wave`,
                    IFNULL(((`share` - `up_share`) / `up_share` * 100), 100) AS `up_share_wave`,
                    (`diff` - `up_diff`) AS diff24,
                    (`share` - `up_share`) AS share24
                FROM
                    `walletlog`
                WHERE
                    `coin` = '{$coin}'
                AND `uptime` > $time
                ORDER BY
                    {$field} {$order}
                LIMIT {$offset}, {$size}";

        $result = $this->logdb->query($sql);

        if (is_array($result) && count($result) > 0) {
            array_walk($result, function (&$v) use ($coin) {
                $diff_up = (int)$v['diff24'];
                $share_up = (int)$v['share24'];

                $diff_opt = true;
                $v['diff_opt'] = 1;
                if ($diff_up < 0) {
                    $diff_up = abs($diff_up);
                    $diff_opt = false;
                    $v['diff_opt'] = 0;
                }

                $share_opt = true;
                $v['share_opt'] = 1;
                if ($share_up < 0) {
                    $share_up = abs($share_up);
                    $share_opt = false;
                    $v['share_opt'] = 0;
                }

                //处理24H 的 diff  和 share
                $diff_up = self::unit($diff_up, $coin, 'diff');
                $share_up = self::unit($share_up, $coin, 'share');
                $v['diff24'] = ($diff_opt === false) ? '-'.$diff_up  : $diff_up;
                $v['share24'] = ($share_opt === false) ? '-'.$share_up  : $share_up;

                //转换各个数据单位
                $v['diff'] = self::unit($v['diff'], $coin, 'diff');
                $v['share'] = self::unit($v['share'], $coin, 'share');
                $v['delay'] = self::unit($v['delay'], $coin, 'delay');
                $v['reject'] = self::unit($v['reject'], $coin, 'reject');
            });

            $list['_list'] = $result;
            return $list;
        }

        return [];
    }

    /**
     * 获取用户的三天的历史数据
     * @param $coin
     * @param $wallet
     * @return array
     */
    public function getWalletAll($coin, $wallet)
    {
        $sql = "SELECT * from walletlog WHERE coin = '{$coin}' AND wallet = '{$wallet}' ORDER BY uptime ASC";
        $result = $this->logdb->query($sql);
        if (is_array($result) && count($result)) {
            $option = [
                'x' => [],
                "series" => [
                    'diff' => [],
                    'share' => [],
                    'delay' => [],
                    'reject' => []
                ]
            ];
            foreach ($result as $item) {
                $x = date('m-d H', $item['uptime']) . ':00';
                array_push($option['x'], $x);
                if ($coin !== 'ae') {
                    array_push($option['series']['diff'], self::unit_case($item['diff'], 'm'));
                } else {
                    array_push($option['series']['diff'], $item['diff']);
                }
                array_push($option['series']['share'], self::unit_case($item['share'], 'k'));
                array_push($option['series']['delay'], $item['delay']);
                array_push($option['series']['reject'], $item['reject']);
            }
            return $option;
        }
        return [];
    }


    /**
     * 获取24H的算力增减
     * @param $coin
     * @param $times
     * @return array
     */
    public function getCoinDiff24($coin, $times)
    {
        if (is_array($times) && count($times) > 1) {
            $sql = "SELECT (`diff` - `up_diff`) as `diff` from walletlog WHERE coin = '{$coin}' AND uptime between $times[0] and $times[1]";
        } else {
            $sql = "SELECT (`diff` - `up_diff`) as `diff` from walletlog WHERE coin = '{$coin}' AND uptime > $times[0]";
        }

        $result = $this->logdb->query($sql);

        $curr_diff = [
            'diff24_up' => [],
            'diff24_down' => [],
            'diff24' => 0
        ];

        if (is_array($result) && count($result) > 0) {
            foreach ($result as $item) {
                if ($item['diff'] > 0) {
                    $curr_diff['diff24_up'][] = $item['diff'];
                } else {
                    $curr_diff['diff24_down'][] = $item['diff'];
                }
            }
        }

        $diff24_up = array_sum($curr_diff['diff24_up']);
        $diff24_down = array_sum($curr_diff['diff24_down']);
        $diff24 = $diff24_up + $diff24_down;

        $diff_opt = true;
        if ($diff24 < 0) {
            $diff_opt = false;
        }
        return [
            'diff24_up' => self::unit($diff24_up, $coin, 'diff'),
            'diff24_down' => '-' . self::unit(abs($diff24_down), $coin, 'diff'),
            'diff24' => $diff_opt === true ? self::unit($diff24, $coin, 'diff') : '-' . self::unit(abs($diff24), $coin, 'diff')
        ];
    }

    /**
     * 过去3天的算力增长趋势
     * @param $coin
     * @return array
     */
    public function getCoinDiffAll($coin)
    {
        $now = strtotime(date('Y-m-d', time()));
        $days = [
            date('Y-m-d', $now) => $this->getCoinDiff24($coin,  [time() - 7200])
        ];
        for ($i = 1; $i <= 3; $i++) {
            $d = strtotime('-'. $i .'day', $now);
            $d_start = $d - 7200;
            $days += [
                date('Y-m-d', $d) => $this->getCoinDiff24($coin,  [$d_start + 86400, $d + 86400])
            ];
        }
        return $days;
    }

    /**
     * 获取时间节点的diff总量
    * @param $coin
     * @param $start
     * @param $end
     * @return int|mixed
     */
    public function getDiffTotal($coin, $start, $end)
    {
        $sql = "select sum(diff) as diff from walletlog where coin = '{$coin}' and  uptime > {$start} and uptime < $end";
        $res = $this->logdb->query($sql);
        return $res ? $res['0']['diff'] : 0;
    }

    /**
     * 获取全网算力
     * @todo 已废弃
     * @param $coin
     * @return array
     */
    public function coinNetworkDiff($coin)
    {
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
        $pass3_time = time() - 86400 * 3;  //仅获取过去三天的算力
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

    /**
     * 获取过去三天的活跃钱包用户数量
     * @param $coin
     * @return array
     */
    public function activeUser($coin)
    {
        $time = time() - 86400 * 3;
        $sql = "SELECT
                    COUNT(`wallet`) AS `count`,
                    CONCAT( FROM_UNIXTIME(uptime, '%d-%H'), ':00' ) AS `days`
                FROM
                    `walletlog`
                WHERE `coin` = '{$coin}' AND `uptime` > $time
                GROUP BY `days`";
        $result = $this->logdb->query($sql);
        if (is_array($result) && count($result) > 0) {
            $data = [
                'x' => array_column($result, 'days'),
                's' => array_column($result, 'count')
            ];
           return $data;
        }
        return [];
    }

    /**
     * 获取过去三天的算力情况趋势
     * @param $coin
     * @return array
     */
    public function activeDiff($coin)
    {
        $time = time() - 86400 * 3;
        $sql = "SELECT
                    SUM(`diff`) AS `diff`,
                    CONCAT(FROM_UNIXTIME(uptime, '%d-%H'), ':00') AS `days`
                FROM `walletlog`
                WHERE coin = '{$coin}' AND uptime > $time
                GROUP BY `days`";
        $result = $this->logdb->query($sql);
        if (is_array($result) && count($result) > 0) {
            $data = [
                'x' => array_column($result, 'days'),
                's' => [],
                'u' => []
            ];
            $diffs = array_column($result, 'diff');
            foreach ($diffs as $diff) {
                $item = self::unit((int)$diff, $coin, 'diff');
                $d = explode(' ', $item);
                array_push($data['s'], $d[0]);
                array_push($data['u'], $d[1]);
            }
            return $data;
        }
        return [];
    }

    /**
     * 获取过去三天的算力增长明细趋势
     * @param $coin
     * @return array
     */
    public function activeUpDiff($coin)
    {
        $time = time() - 86400 * 3;
        $sql = "SELECT
                    SUM(`diff` - `up_diff`) AS `diff`,
                    CONCAT(FROM_UNIXTIME(uptime, '%d-%H'),':00') AS `days`
                FROM `walletlog`
                WHERE coin = '{$coin}' AND `uptime` > $time
                GROUP BY `days`";
        $result = $this->logdb->query($sql);
        if (is_array($result) && count($result) > 0) {
            $data = [
                'x' => array_column($result, 'days'),
                's' => [],
                'u' => [],
                'total' => self::unit((int)array_sum(array_column($result, 'diff')), $coin, 'diff')
            ];
            $diffs = array_column($result, 'diff');
            foreach ($diffs as $diff) {
                $item = self::unit((int)$diff, $coin,'diff');
                $d = explode(' ', $item);
                array_push($data['s'], $d[0]);
                array_push($data['u'], $d[1]);
            }
            return $data;
        }
        return [];
    }

    /**
     * 计算不同币种的算力分布
     * @param $number
     * @param $coin
     * @param $range
     */
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
                        self::user_analysis($wallet,$user['less200m']);
                        break;
                    case (7 <= $len && $len < 10):
                        $m = number_format($number / pow(1000, 2), 2);
                        if ($m < 200) {
                            $range['less200m']++;
                            self::user_analysis($wallet,$user['less200m']);
                        } else if ($m >= 200 && $m < 1000) {
                            $range['less1g']++;
                            self::user_analysis($wallet,$user['less1g']);
                        }
                        break;
                    case (10 <= $len && $len < 13):
                        $g = number_format($number / pow(1000,3),2);
                        if ($g < 5) {
                            $range['less5g']++;
                            self::user_analysis($wallet,$user['less5g']);
                        } else if ($g >= 5 && $g < 10) {
                            $range['less10g']++;
                            self::user_analysis($wallet,$user['less10g']);
                        } else if ($g >= 10 && $g < 30) {
                            $range['less30g']++;
                            self::user_analysis($wallet,$user['less30g']);
                        } else if ($g >=30 && $g < 100) {
                            $range['less100g']++;
                            self::user_analysis($wallet,$user['less100g']);
                        } else if ($g >= 100) {
                            $range['large100g']++;
                            self::user_analysis($wallet,$user['large100g']);
                        }
                        break;
                }
                break;
            case 'rvn':
                switch ($len) {
                    case (4 <= $len && $len < 7):
                        $range['less50m']++;
                        self::user_analysis($wallet,$user['less50m']);
                        break;
                    case (7 <= $len && $len < 10):
                        $m = number_format($number / pow(1000, 2), 2);
                        if ($m < 50) {
                            $range['less50m']++;
                            self::user_analysis($wallet,$user['less50m']);
                        } else if ($m >= 50 && $m < 500) {
                            $range['less500m']++;
                            self::user_analysis($wallet,$user['less500m']);
                        } else if ($m >= 500 && $m < 1000) {
                            $range['less1g']++;
                            self::user_analysis($wallet,$user['less1g']);
                        }
                        break;
                    case (10 <= $len && $len < 13):
                        $g = number_format($number / pow(1000,3),2);
                        if ($g < 4) {
                            $range['less4g']++;
                            self::user_analysis($wallet,$user['less4g']);
                        } else if ($g >= 4 && $g < 15) {
                            $range['less15g']++;
                            self::user_analysis($wallet,$user['less15g']);
                        }  else  {
                            $range['large15g']++;
                            self::user_analysis($wallet,$user['large15g']);
                        }
                        break;
                }
                break;
            case 'ae':
                switch ($len) {
                    case (0 <= $len && $len < 4):
                        if ($number < 30) {
                            $range['less30g']++;
                            self::user_analysis($wallet,$user['less30g']);
                        } else if ($number >=30 && $number < 100) {
                            $range['less100g']++;
                            self::user_analysis($wallet,$user['less100g']);
                        } else if ($number >=100 && $number < 200) {
                            $range['less200g']++;
                            self::user_analysis($wallet,$user['less200g']);
                        } else if ($number >=200 && $number < 1000) {
                            $range['less1k']++;
                            self::user_analysis($wallet,$user['less1k']);
                        }
                        break;
                    case (4 <= $len && $len < 10):
                        $k = number_format($number / pow(10000,1),2);
                        if ($k < 4) {
                            $range['4k']++;
                            self::user_analysis($wallet,$user['4k']);
                        } else if ($k >= 4 && $k < 7) {
                            $range['7k']++;
                            self::user_analysis($wallet,$user['7k']);
                        } else {
                            $range['large7k']++;
                            self::user_analysis($wallet,$user['large7k']);
                        }
                        break;
                }
                break;
            case 'sero':
                switch ($len) {
                    case (4 <= $len && $len < 7):
                        $range['less50m']++;
                        self::user_analysis($wallet,$user['less50m']);
                        break;
                    case (7 <= $len && $len < 10):
                        $m = number_format($number / pow(1000, 2), 2);
                        if ($m < 50) {
                            $range['less50m']++;
                            self::user_analysis($wallet,$user['less50m']);
                        } else if ($m >= 50 && $m < 300) {
                            $range['less300m']++;
                            self::user_analysis($wallet,$user['less300m']);
                        } else if ($m >= 300 && $m < 500) {
                            $range['less500m']++;
                            self::user_analysis($wallet,$user['less500m']);
                        } else if ($m >= 500 && $m < 1000) {
                            $range['less1g']++;
                            self::user_analysis($wallet,$user['less1g']);
                        }
                        break;
                    case (10 <= $len && $len < 13):
                        $g = number_format($number / pow(1000,3),2);
                        if ($g < 3) {
                            $range['less3g']++;
                            self::user_analysis($wallet,$user['less3g']);
                        } else {
                            $range['large3g']++;
                            self::user_analysis($wallet,$user['large3g']);
                        }
                        break;
                }
                break;
        }
    }


    /**
     * 子账户与匿名账户分析
     * @param $wallet
     * @param $user
     */
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


    /**
     * 根据不同单位处理
     * @param $number
     * @param $unit
     * @return float
     */
    protected static function unit_case($number, $unit)
    {
        switch ($unit) {
            case 'k':
                return floor($number / pow(1000,1));
                break;
            case 'm':
                return floor($number / pow(1000,2));
                break;
            case 'G':
                return floor($number / pow(1000,3));
                break;
            case 'ae/g':
                return floor($number / pow(1000,1));
                break;
        }
    }

    /**
     * 币种算力分布
     * @param $coin
     * @return array|mixed
     */
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
}
