<?php

class loger
{

    private $udpServer = '127.0.0.1';

    private $udpPort = '8989';

    private $sourceIp = '0.0.0.0';

    private $enableUdp = false;

    private $logPath = './logs/';

    private $systemName = 'shareProcesser';

    public function enableUdp()
    {
        $this->enableUdp = true;
    }

    public function disableUdp()
    {
        $this->enableUdp = false;
    }

    public function setUdp($server, $port, $interface = '0.0.0.0')
    {
        $this->udpServer = $server;
        $this->udpPort = $port;
        $this->sourceIp = $interface;
        $this->enableUdp = true;
    }

    public function setLogPath($path)
    {
        $this->logPath = $path;
    }

    public function setSystemName($systemName)
    {
        $this->systemName = $systemName;
    }

    public function write($type, $method, $content)
    {
        $logContent = date('m-d H:i:s') . " {$this->sourceIp} {$this->systemName} {$type} {$method} {$content}";
        
        if ($this->enableUdp) {
            
            $handle = stream_socket_client("udp://{$this->udpServer}:{$this->udpPort}", $errno, $errstr);
            
            if (! $handle) {
                
                echo "Cant create udp socket." . PHP_EOL;
            }
            
            fwrite($handle, $logContent . "\n");
            
            fclose($handle);
        } else {
            
            //
            
            $filename = $this->logPath . date('YY-m-d') . '.log';
            
            file_put_contents($logContent, $logContent . "\n", FILE_APPEND);
        }
    }
}