<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ServiceRegisterTest extends TestCase
{

    public function testRegister(): void
    {
        $_this = $this;
        $func  = function () use ($_this) {
            $center               = new \Mix\Etcd\Registry([
                'host'    => '127.0.0.1',
                'port'    => 2379,
                'version' => 'v3',
                'ttl'     => 10,
            ]);
            $serviceFactory       = new \Mix\Etcd\Factory\ServiceFactory();
            $serviceBundleFactory = new \Mix\Etcd\Factory\ServiceBundleFactory();
            $ip                   = current(swoole_get_local_ip());
            $service              = $serviceFactory->createService('php.micro.srv.test', $ip, 9501);
            $service->withMetadata("foo", "bar");
            $serviceBundle = $serviceBundleFactory->createServiceBundle($service);
            $center->register($serviceBundle);
        };
        run($func);
    }

}
