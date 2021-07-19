<?php
class logParser {

    private $staticConfig = [
        'interface','model','type','method'
    ];

    private $config = [
        'proxy-*' => [
            'login'=> [
                'rule'=>'/(.+) (.+) (.+) (.+)/si',
                'keys'=>['wallet','worker','mode','ip']
            ],
            'block' => [
                'rule'=>'/(.+) (.+) (.+) (.+) (.+)?/si',
                'keys'=>['height','wallet','worker','ip']
            ],
            'share' => [
                'rule' => '/clients:(\d+), counter:(\d+), delay:(\d+), reject:(\d+)/si',
                'keys' =>['clients','counter','delay','reject']
            ],
            'boardcast' => [
                'rule' => '/counter:(\d+) cost:(.{5})/si',
                'keys' =>['counter','cost']
            ]
        ],
        'sqlQueue' => [
            'query' => [
                'rule' => '/counter:(\d+)/si',
                'keys' =>['counter']
            ]
        ],
        'shareProcesser' => [
            'share-process' => [
                'rule' => '/processed (\d+) shares/si',
                'keys' =>['counter']
            ],
            'wallet-client' => [
                'rule' => '/(.+) (\d+)/si',
                'keys' =>['wallet','clients']
            ],
        ],
        'sharePointer' => [
            'update' => [
                'rule' => '/Worker Counter:(\d+), Wallet Counter:(\d+), Coin Counter:(\d+), Cost Time:(.{5})/si',
                'keys' =>['worker','wallet','coin','cost']
            ]
        ],
        'coinPPS' => [
            'run' => [
                'rule' => '/Coin: (.+), Amount:(\d+), Count:(\d+), PPS balance:(\d+), PPS diff:(\d+)/si',
                'keys' =>['coin','amount','count','balance','diff']
            ]
        ],
        'coinDivider' => [
            'divide' => [
                'rule' => '/Coin: (.+), Height:(\d+), Amount:(\d+), All diff:(\d+), PPLNS diff:(\d+)/si',
                'keys' =>['coin','height','amount','alldiff','diff']
            ]
        ],
        'txmaker-*' => [
            'create' => [
                'rule' => '/All Balance:(\d+), All Counter:(\d+), Account Balance:(\d+), Account Counter:(\d+)/si',
                'keys' =>['allbalance','allcounter','accountbalance','accountcounter']
            ],
            'pay' => [
                'rule' => '/Counter:(\d+), Balance:(\d+), Success:(\d+)/si',
                'keys' =>['counter','balance','success']
            ]
        ]
    ];



    public function post($msg)
    {
        $return = [];
        $tempmsg = explode(' ', $msg);

        array_shift($tempmsg);
        array_shift($tempmsg);

        foreach ($this->staticConfig as $key) {
            $return[$key] = array_shift($tempmsg);
        }

        $return['date'] = time();

        if(count($tempmsg) > 0){
          $content = implode(' ',$tempmsg);
          $model = $return['model'];
          switch (substr($model, 0, 5)) {
            case 'proxy':
              $model = 'proxy-*';
              break;
            case 'txmak':
              $model = 'txmaker-*';
              break;
          }

          $return['newmodel'] = $model;

          if (isset($this->config[$model][$return['method']])) {
            preg_match($this->config[$model][$return['method']]['rule'], $content, $match);
            foreach($this->config[$model][$return['method']]['keys'] as $i=>$key){
              $return[$key] = trim($match[$i+1]);
            }
          }
        }
        return $return;
    }
}