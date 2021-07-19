<?php
class dataController
{

    private $logdb = null;

    private $coredb = null;

    public function __construct($logdb, $coredb)
    {
        $this->logdb = $logdb;
        $this->coredb = $coredb;
    }

    public function getShareData($coin, $time = 0)
    {

        // 获取代理的提交的数据

        // interface model clients counter delay reject uptime
        $proxy = 'proxy-' . $coin;

        if ($time == 0) {

            $time = time() - 10800;
        }

        $sql = "select * from sharelog where model = '{$proxy}' and uptime > {$time} order by id asc";

        $result = $this->logdb->query($sql);

        $return = array(
            'lastData' => array(
                'allClients' => 0,
                'allCounter' => 0,
                'allDelay' => 0,
                'allReject' => 0
            )
        );

        $lastData = array();

        if ($result) {


            $startTime = 0 ;

            if(count($result) > 0){

                $startTime = $result[0]['uptime'];

            }

            foreach ($result as $item) {

                $lastData['clients'][$item['interface']] = $item['clients'];
                $lastData['counter'][$item['interface']] = $item['counter'];
                $lastData['delay'][$item['interface']] = $item['delay'];
                $lastData['reject'][$item['interface']] = $item['reject'];


                $return['clients'][$item['interface']]['value'][]=$item['clients'];
                $return['clients'][$item['interface']]['x'][] = date('H:i',$startTime + (@count($return['clients'][$item['interface']]['x']) * 60));

                $return['counter'][$item['interface']]['value'][]=$item['counter'];
                $return['counter'][$item['interface']]['x'][] = date('H:i',$startTime + (@count($return['counter'][$item['interface']]['x']) * 60));

                $return['delay'][$item['interface']]['value'][]=$item['delay'];
                $return['delay'][$item['interface']]['x'][] = date('H:i',$startTime + (@count($return['delay'][$item['interface']]['x']) * 60));

                $return['reject'][$item['interface']]['value'][]=$item['reject'];
                $return['reject'][$item['interface']]['x'][] = date('H:i',$startTime + (@count($return['reject'][$item['interface']]['x']) * 60));
            }

            $return['lastData'] = array(
                'allClients' => array_sum($lastData['clients']),
                'allCounter' => array_sum($lastData['counter']),
                'allDelay' => array_sum($lastData['delay']),
                'allReject' => array_sum($lastData['reject'])
            );
        }



        return $return;
    }

    public function getloginData($coin, $wallet, $time = 0)
    {
        if ($time == 0) {

            $time = time() - 86400;
        }

        $sql = "select * from logininfo where model = 'proxy-{$coin}' and wallet='{$wallet}' and uptime > {$time} order by id desc limit 500";

        $result = $this->logdb->query($sql);

        $return = array();

        if ($result) {

            foreach ($result as $item) {

                $return[] = array(
                    'worker' => $item['worker'],
                    'interface' => $item['interface'],
                    'ip' => trim($item['ip']),
                    'mode' => $item['mode'] ? 'pplns' : 'pps',
                    'uptime' => date('m-d H:i:s', $item['uptime'])
                );
            }
        }

        return $return;
    }

    public function getwalletIP($wallet, $time = 0)
    {
        if ($time == 0) {

            $time = time() - 2592000;
        }

        $sql = "select * from walletip where wallet='{$wallet}' and uptime > {$time}";

        $result = $this->logdb->query($sql);

        $return = array();

        if ($result) {

            foreach ($result as $item) {

                $return[] = array(
                    'ip' => trim($item['ip']),
                    'uptime' => date('m-d H:i', $item['uptime'])
                );
            }
        }

        return $return;
    }
    public function getIpWallet($ip, $time = 0)
    {
        if ($time == 0) {

            $time = time() - 2592000;
        }



        $sql = "select * from walletip where ip like '{$ip}%' and uptime > {$time}";

        $result = $this->logdb->query($sql);

        $return = array();

        if ($result) {

            foreach ($result as $item) {

                $return[] = array(
                    'wallet' => $item['wallet'],
                    'ip' => trim($item['ip']),
                    'uptime' => date('m-d H:i', $item['uptime'])
                );
            }
        }

        return $return;
    }
    public function getShareProcessor($time = 0)
    {
        if ($time == 0) {

            $time = time() - 10800;
        }

        $sql = "select * from shareprocessor where uptime > {$time} order by id asc";

        $result = $this->logdb->query($sql);

        $return = array(
        );

        if ($result) {

            $startTime = 0 ;

            if(count($result) > 0){

                $startTime = $result[0]['uptime'];

            }

            foreach ($result as $item) {

                $return[$item['interface']]['value'][] = $item['counter'];
                $return[$item['interface']]['x'][] = date('H:i',$startTime + (count($return[$item['interface']]['x']) * 60));

            }
        }

        return $return;
    }
    public function getBoardcastData($coin, $time = 0)
    {

        // 获取代理的提交的数据

        // interface model clients counter delay reject uptime
        $proxy = 'proxy-' . $coin;

        if ($time == 0) {

            $time = time() - 600;
        }

        $sql = "select * from boardcastinfo where model = '{$proxy}' and uptime > {$time} order by id asc";

        $result = $this->logdb->query($sql);

        $return = array(
        );


        if ($result) {


            foreach ($result as $item) {


                $return['clients'][$item['interface']]['value'][]=$item['counter'];
                $return['clients'][$item['interface']]['x'][] = date('H:i', $item['uptime']);

                $return['cost'][$item['interface']]['value'][]=$item['cost'];
                $return['cost'][$item['interface']]['x'][] = date('H:i',  $item['uptime']);
            }
        }

        return $return;
    }
    public function getBlockList($coin, $time = 0)
    {

        // 获取代理的提交的数据

        // interface model clients counter delay reject uptime
        $proxy = 'proxy-' . $coin;

        if ($time == 0) {

            $time = time() - 10800;
        }

        $sql = "select * from blockinfo where model = '{$proxy}' and uptime > {$time} order by id desc limit 100";

        $result = $this->logdb->query($sql);

        $return = array(
        );


        if ($result) {


            foreach ($result as $item) {

                $item['formatTime'] = date('H:i:s', $item['uptime']);

                $return[]=$item;


            }
        }

        return $return;
    }

    public function getBlockCounter($coin){
        //获取过去24小时的block统计分布，获取过去7天的block数量统计

        $proxy = 'proxy-' . $coin;

        $time24 = time() - 86400;

        $sql24 = "SELECT FROM_UNIXTIME(uptime, '%H') AS timeh, COUNT(*) AS num FROM blockinfo where model='{$proxy}' and uptime > {$time24}  GROUP BY timeh";

        $result24 = $this->logdb->query($sql24);

        $return = array(
        );

        if ($result24) {


            foreach ($result24 as $item) {

                $return['block24']['value'][]=$item['num'];
                $return['block24']['x'][] = $item['timeh'];

            }
        }

        $time7 = time() - 1987200;

        $sql7 = "SELECT FROM_UNIXTIME(uptime, '%d') AS timeh, COUNT(*) AS num FROM blockinfo where model='{$proxy}' and uptime > {$time7}  GROUP BY timeh";

        $result7 = $this->logdb->query($sql7);

        if ($result7) {


            foreach ($result7 as $item) {

                $return['block7']['value'][]=$item['num'];
                $return['block7']['x'][] = $item['timeh'];

            }
        }

        return $return;
    }

    public function getTopLogin($coin, $time = 0){

        $proxy = 'proxy-' . $coin;

        if ($time == 0) {

            $time = time() - 10800;

        }

        $return = array();


        $sql = "select wallet,count(*) as count from logininfo where uptime > {$time} and model='{$proxy}'  GROUP BY wallet ORDER BY count desc limit 20";

        $result = $this->logdb->query($sql);

        if($result) {

            foreach ($result as $item) {

                $return[]= $item;

            }

        }

        return $return;

    }

    public function getTopLargeLogin($coin, $time = 0){

        $proxy = 'proxy-' . $coin;

        if ($time == 0) {

            $time = time() - 10800;

        }

        $return = array();


        $sql = "select wallet,count(*) as count from largelogininfo where uptime > {$time} and model='{$proxy}'  GROUP BY wallet ORDER BY count desc limit 20";

        $result = $this->logdb->query($sql);

        if($result) {

            foreach ($result as $item) {

                $return[]= $item;

            }

        }

        return $return;

    }

    public function getBlockListByWallet($coin, $wallet, $time = 0){

        if ($time == 0) {

            $time = time() - 2592000;
        }

        $sql = "select * from blocks where coin='{$coin}' and wallet='{$wallet}' and time > {$time} order by id desc";

        $result = $this->coredb->query($sql);

        $return = array();

        if($result) {

            foreach ($result as $item) {

                $item['formatTime'] = date('m-d H:i', $item['time']);

                $return[]= $item;

            }

        }


        return $return;
    }


}