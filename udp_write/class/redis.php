<?php

class opRedis
{

    private $uri = '127.0.0.1';

    private $port = 6379;

    private $server;
    
    private $loger;

    public function __construct($loger = null)
    {
        $this->server = new Redis();
        $this->loger = $loger;
    }

    public function connect($uri, $port)
    {
        $this->uri = $uri;
        $this->port = $port;
        
        $this->server->connect($this->uri, $this->port);
        $this->server->setOption(Redis::OPT_READ_TIMEOUT, -1);
        //scan noretry, the set of response maybe is empty. Or SCAN_RETRY
        $this->server->setOption(Redis::OPT_SCAN, Redis::SCAN_NORETRY);
    }

    public function __call($name, $args)
    {
        
    	$res = false;
    	
        for ($i = 0; $i<3; $i++){
            
            try {
            
                $res = call_user_func_array([
                    $this->server,
                    $name
                ], $args);
            
                break;
            
            } catch (RedisException $e) {
                // reconnect
                
                if (false === $this->checkConnection()){
            
                    $this->reconnect();
                }
                
                //@extend add
                if ($this->loger != null){
                    
                    $this->loger->write('redis', 'call', $e->getMessage());
                    
                }
            
                continue;
            }
            
        }
        
        
        return $res;
    }

    private function checkConnection()
    {
        $status = false;
        
        $response = $this->server->ping("pong");
        
        if ($response === "pong") {
            $status = true;
        }
        
        return $status;
    }

    private function reconnect()
    {
        $this->server = new Redis();
        
        $this->server->connect($this->uri, $this->port);
        $this->server->setOption(Redis::OPT_READ_TIMEOUT, -1);
    }

    public function __destruct()
    {
        $this->server->close();
    }
}