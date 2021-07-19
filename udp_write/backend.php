<?php
include 'class/redis.php';
include 'class/parser.php';
include 'class/loger.php';
include 'class/db.php';
include 'config.php';

$serv = new swoole_server($interface, $port, SWOOLE_PROCESS, SWOOLE_SOCK_UDP);

$serv->set(array(
    'worker_num' => 3,
    'daemonize' => true,
    'max_request' => 30000
));


$shareProcess = new swoole_process(function (swoole_process $process) use($serv)
{
    global $redisIp, $redisPort, $databaseIp, $databaseUser, $databasePwd, $databaseName;
    
    $redis = new opRedis();
    $redis->connect($redisIp, $redisPort);


    $db = new db();
    $db->init($databaseIp, $databaseUser, $databasePwd, $databaseName);
    
    $lastTime = time();
    
    $quotient = ($lastTime % 10);
    
    while (1) {
        $currentTime = time();
        
        if ($currentTime % 10 === $quotient || ($currentTime - $lastTime) > 10) {
            
            //logininfo

            $logininfo=array(
              'key'=>array('interface','model','wallet','worker','mode','ip','uptime'),
              'value'=>array()
            );

            $walletIpinfo = array(
              'key'=>array('wallet','ip','uptime'),
              'value'=>array()
            );

            while(1){
              
              $result = $redis->rPop('logininfo');

              if($result){

                $resultItem = json_decode($result, true);

                if(is_array($resultItem) && count($resultItem) == 7){

                  $logininfo['value'][] = $resultItem;

                  $walletIpinfo['value'][] = array(
                    $resultItem[2],$resultItem[5],$resultItem[6]
                  );

                }else{

                  break;

                }

              }else{

                break;

              }
            }
            
            //写入

            if(count($logininfo['value']) > 0 ){

              $db->multi_insert($logininfo)->table('logininfo')->queryAll();
              $db->multi_replace($walletIpinfo)->table('walletip')->queryAll();

            }
            
            //largelogininfo

            $logininfo=array(
              'key'=>array('interface','model','wallet','worker','mode','ip','uptime'),
              'value'=>array()
            );

            $walletIpinfo = array(
              'key'=>array('wallet','ip','uptime'),
              'value'=>array()
            );

            while(1){
              
              $result = $redis->rPop('largelogininfo');

              if($result){

                $resultItem = json_decode($result, true);

                if(is_array($resultItem) && count($resultItem) == 7){

                  $logininfo['value'][] = $resultItem;

                  $walletIpinfo['value'][] = array(
                    $resultItem[2],$resultItem[5],$resultItem[6]
                  );

                }else{

                  break;

                }

              }else{

                break;

              }
            }
           
            //写入

            if(count($logininfo['value']) > 0 ){

              $db->multi_insert($logininfo)->table('largelogininfo')->queryAll();
              $db->multi_replace($walletIpinfo)->table('walletip')->queryAll();

            }

            //new

            $modelConfig = array(
              'blockinfo' => array(
                'key' => array('interface','model','height','wallet','worker','ip','uptime'),
                'table' => 'blockinfo'
              ),
              'shareinfo' => array(
                'key' => array('interface','model','clients','counter','delay','reject','uptime'),
                'table' => 'sharelog'
              ),
              'boardcastinfo' => array(
                'key' => array('interface','model','counter','cost','uptime'),
                'table' => 'boardcastinfo'
              ),
              'shareprocessor' => array(
                'key' => array('interface','counter','uptime'),
                'table' => 'shareprocessor'
              ),
              'walletclient'=> array(
                'key' => array('interface','wallet','client'),
                'table' => 'walletclient'
              ),
              'sharepointer' => array(
                'key' => array('interface','worker','wallet','cost','uptime'),
                'table' => 'sharepointer'
              ),
              'txmakercreate' => array(
                'key' => array('interface','model','allbalance','allcounter','accountbalance','accountcounter','uptime'),
                'table' => 'txmakercreate'
              ),
              'txmakerpay' => array(
                'key' => array('interface','model','counter','balance','success','uptime'),
                'table' => 'txmakerpay'
              )
            );

            foreach($modelConfig as $rediskey => $dbconfig){

              $keysLen = count($dbconfig['key']);

              $insertSet = array(
                'key' => $dbconfig['key'],
                'value' => array()
              );

              while(1){
              
                $result = $redis->rPop($rediskey);
  
                if($result){
  
                  $resultItem = json_decode($result, true);
  
                  if(is_array($resultItem) && count($resultItem) == $keysLen){
  
                    $insertSet['value'][] = $resultItem;
  
                  }else{
  
                    break;
  
                  }
  
                }else{
  
                  break;
  
                }
              }

              if(count($insertSet['value']) > 0){

                $db->multi_replace($insertSet)->table($dbconfig['table'])->queryAll();

              }

              

            }

    
            $lastTime = $currentTime;
        }
        
        sleep(1);
    }
});

$serv->addProcess($shareProcess);

$serv->on('workerstart', function ($serv, $id)
{
    global $redisIp, $redisPort, $databaseIp, $databaseUser, $databasePwd, $databaseName;
    
    $redis = new opRedis();
    $redis->connect($redisIp, $redisPort);
    $serv->redis = $redis;

    $db = new db();
    $db->init($databaseIp, $databaseUser, $databasePwd, $databaseName);

    $sql = 'select wallet from ignorewallet';

    $result = $db->query($sql);

    $serv->ignoreWallet = array();

    if($result){
      foreach($result as $item){

        $serv->ignoreWallet[] = strtolower(trim($item['wallet']));

      }
    }

    $db->sql_close();

    $serv->parser = new logParser();

});

$serv->on('packet', function ($serv, $data, $clientInfo) {

  $msg = $serv->parser->post($data);

  if(!is_array($msg) || !isset($msg['model'])) return;

  switch ($msg['newmodel']) {
    case 'proxy-*':
      
      switch($msg['method']){
        case 'login':

          if(!in_array(strtolower($msg['wallet']),$serv->ignoreWallet)){

            $serv->redis->lPush('logininfo',json_encode(
              array(
                $msg['interface'],$msg['model'],$msg['wallet'],$msg['worker'],($msg['mode']=='pps'?0:1),$msg['ip'],$msg['date']
              )
            ));

          }else{
            $serv->redis->lPush('largelogininfo',json_encode(
              array(
                $msg['interface'],$msg['model'],$msg['wallet'],$msg['worker'],($msg['mode']=='pps'?0:1),$msg['ip'],$msg['date']
              )
            ));
          }

        break;
        case 'block':

          $serv->redis->lPush('blockinfo',json_encode(
            array(
              $msg['interface'],$msg['model'],$msg['height'],$msg['wallet'],$msg['worker'],$msg['ip'],$msg['date']
            )
          ));

        break;
        case 'share':
          $serv->redis->lPush('shareinfo',json_encode(
            array(
              $msg['interface'],$msg['model'],$msg['clients'],$msg['counter'],$msg['delay'],$msg['reject'],$msg['date']
            )
          ));
        break;
        case 'boardcast':
          $serv->redis->lPush('boardcastinfo',json_encode(
            array(
              $msg['interface'],$msg['model'],$msg['counter'],$msg['cost'],$msg['date']
            )
          ));
        break;
      }

      break;

    case 'sharePointer':
      $serv->redis->lPush('sharepointer',json_encode(
        array(
          $msg['interface'],$msg['worker'],$msg['wallet'],$msg['cost'],$msg['date']
        )
      ));
    break;

    case 'shareProcesser':
      switch($msg['method']){
        case 'share-process':
          $serv->redis->lPush('shareprocessor',json_encode(
            array(
              $msg['interface'],$msg['counter'],$msg['date']
            )
          ));
          break;
        case 'wallet-client':
          $serv->redis->lPush('walletclient',json_encode(
            array(
              $msg['interface'],$msg['wallet'],$msg['clients']
            )
          ));         
          break;
      }

    break;

    case 'txmaker-*':
      switch($msg['method']){
        case 'create':
          $serv->redis->lPush('txmakercreate',json_encode(
            array(
              $msg['interface'],$msg['model'],$msg['allbalance'],$msg['allcounter'],$msg['accountbalance'],$msg['accountcounter'],$msg['date']
            )
          ));
        break;
        case 'pay':
          $serv->redis->lPush('txmakerpay',json_encode(
            array(
              $msg['interface'],$msg['model'],$msg['counter'],$msg['balance'],$msg['success'],$msg['date']
            )
          ));
        break;
      }
    break;
    default:
      break;
  }

});

$serv->start();