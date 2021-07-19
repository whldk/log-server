<?php

class loginCountController
{
    private $logdb = null;

    public function __construct($logdb)
    {
        $this->logdb = $logdb;
    }

    /**
     * 统计loginCount 过去三个小时 （每分钟数据自动更新）
     * @param string $coin
     * @param int $page
     * @param int $size
     * @return array
     */
    public function LoginCount(string $coin, $order, $page, $size) : array
    {
        $time = time() - 3600 * 3;

        //计算分页起始
        $offset = ($page - 1) * $size;
        if ($offset < 0) $offset = 0;
        if ($size < 0 || $size > 1000) $size = 1000;

        $model = 'proxy-'.$coin;
        $sql = "select `wallet`, sum(`count`) as `count` from logincount where model = '{$model}' and uptime > {$time} group by `wallet` order by `count` {$order}";
        $result = $this->logdb->query($sql);

        $list = [
            'page' => $page,
            'size' => $size,
            'total' => count($result),
            '_list' => []
        ];

        if (is_array($result) && count($result) > 0) {
            $result = array_splice($result, $offset, $size);    //不建议返回全部数据
            $list['_list'] = $result;
        }

        return $list;
    }

    /**
     * 大户登录统计情况 过去三个小时
     * @param string $coin
     * @return mixed
     */
    public function LargeLoginCount(string $coin,  $order)
    {
        //查询过去三个小时的数据
        $time = time() - 3600 * 3;
        $model = 'proxy-'.$coin;
        $sql = "select `wallet`, sum(`count`) as `count` from largelogincount where model = '{$model}' and uptime > {$time} group by `wallet` order by `count` {$order}";
        $result = $this->logdb->query($sql);
        return $result ?: [];
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
}