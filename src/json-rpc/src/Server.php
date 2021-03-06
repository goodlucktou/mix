<?php

namespace Mix\JsonRpc;

use Mix\Concurrent\Sync\WaitGroup;
use Mix\Http\Message\Factory\StreamFactory;
use Mix\Http\Message\ServerRequest;
use Mix\JsonRpc\Event\ProcessedEvent;
use Mix\JsonRpc\Factory\ResponseFactory;
use Mix\JsonRpc\Helper\JsonRpcHelper;
use Mix\JsonRpc\Message\Request;
use Mix\JsonRpc\Message\Response;
use Mix\Server\Connection;
use Mix\Server\Exception\ReceiveException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Coroutine\Channel;

/**
 * Class Server
 * @package Mix\JsonRpc
 */
class Server implements \Mix\Http\Server\HandlerInterface, \Mix\Server\HandlerInterface
{

    /**
     * @var string
     */
    public $host = '';

    /**
     * @var int
     */
    public $port = 0;

    /**
     * @var bool
     */
    public $reusePort = false;

    /**
     * 事件调度器
     * @var EventDispatcherInterface
     */
    public $dispatcher;

    /**
     * @var \Mix\Server\Server
     */
    protected $server;

    /**
     * @var object[]
     */
    protected $services = [];

    /**
     * @var callable[]
     */
    protected $callables = [];

    /**
     * Server constructor.
     * @param string $host
     * @param int $port
     * @param bool $reusePort
     */
    public function __construct(string $host, int $port, bool $reusePort = false)
    {
        $this->host      = $host;
        $this->port      = $port;
        $this->reusePort = $reusePort;
    }

    /**
     * 获取全部 service 名称, 通过类名
     * @return string[]
     */
    public function services()
    {
        $services = [];
        foreach ($this->services as $service) {
            $className  = strtolower(basename(str_replace('\\', '/', get_class($service))));
            $services[] = $className;
        }
        return $services;
    }

    /**
     * Register
     * @param object $service
     */
    public function register(object $service)
    {
        array_push($this->services, $service);
        $className = str_replace('/', '\\', basename(str_replace('\\', '/', get_class($service))));
        $methods   = get_class_methods($service);
        foreach ($methods as $method) {
            $this->callables[sprintf('%s.%s', $className, $method)] = [$service, $method];
        }
    }

    /**
     * Start
     * @throws \Swoole\Exception
     */
    public function start()
    {
        $server = $this->server = new \Mix\Server\Server($this->host, $this->port, false, $this->reusePort);
        $server->set([
            'open_eof_check' => true,
            'package_eof'    => Constants::EOF,
        ]);
        $server->start($this);
    }

    /**
     * 连接处理
     * @param Connection $conn
     * @throws \Throwable
     */
    public function handle(Connection $conn)
    {
        // 消息发送
        $sendChan = new Channel();
        xdefer(function () use ($sendChan) {
            $sendChan->close();
        });
        xgo(function () use ($sendChan, $conn) {
            while (true) {
                $data = $sendChan->pop();
                if (!$data) {
                    return;
                }
                try {
                    $conn->send($data);
                } catch (\Throwable $e) {
                    $conn->close();
                    throw $e;
                }
            }
        });
        // 消息读取
        while (true) {
            try {
                $data = $conn->recv();
                $this->callTCP($sendChan, $data);
            } catch (\Throwable $e) {
                // 忽略服务器主动断开连接异常
                if ($e instanceof ReceiveException) {
                    return;
                }
                // 抛出异常
                throw $e;
            }
        }
    }

    /**
     * 调用TCP
     * @param Channel $sendChan
     * @param string $content
     */
    protected function callTCP(Channel $sendChan, string $content)
    {
        /**
         * 解析
         * @var Request[] $requests
         * @var bool $single
         */
        try {
            list($single, $requests) = JsonRpcHelper::parseRequests($content);
        } catch (\Throwable $ex) {
            $response = (new ResponseFactory)->createErrorResponse(-32700, 'Parse error', null);
            $sendChan->push(JsonRpcHelper::content(true, $response));
            return;
        }
        // 处理
        $responses = $this->process(...$requests);
        // 发送
        $sendChan->push(JsonRpcHelper::content($single, ...$responses));
    }

    /**
     * 调用HTTP
     * @param \Mix\Http\Message\Response $httpResponse
     * @param string $content
     */
    protected function callHTTP(\Mix\Http\Message\Response $httpResponse, string $content)
    {
        /**
         * 解析
         * @var Request[] $requests
         * @var bool $single
         */
        try {
            list($single, $requests) = JsonRpcHelper::parseRequests($content);
        } catch (\Throwable $ex) {
            $response = (new ResponseFactory)->createErrorResponse(-32700, 'Parse error', null);
            $body     = (new StreamFactory)->createStream(JsonRpcHelper::content(true, $response));
            $httpResponse->withBody($body)
                ->withContentType('application/json')
                ->withStatus(200)
                ->end();
            return;
        }
        // 处理
        $responses = $this->process(...$requests);
        // 发送
        $body = (new StreamFactory)->createStream(JsonRpcHelper::content($single, ...$responses));
        $httpResponse->withBody($body)
            ->withContentType('application/json')
            ->withStatus(200)
            ->end();
    }

    /**
     * 处理
     * @param Request ...$requests
     * @return array
     */
    protected function process(Request ...$requests)
    {
        $waitGroup = WaitGroup::new();
        $waitGroup->add(count($requests));
        $responses = [];
        foreach ($requests as $request) {
            xgo(function () use ($request, &$responses, $waitGroup) {
                xdefer(function () use ($waitGroup) {
                    $waitGroup->done();
                });
                // 验证
                if (!JsonRpcHelper::validRequest($request)) {
                    $responses[] = (new ResponseFactory)->createErrorResponse(-32600, 'Invalid Request', $request->id);
                    return;
                }
                if (!isset($this->callables[$request->method])) {
                    $responses[] = (new ResponseFactory)->createErrorResponse(-32601, 'Method not found', $request->id);
                    return;
                }
                // 执行
                $microtime = static::microtime();
                try {
                    $result      = call_user_func($this->callables[$request->method], ...$request->params);
                    $result      = is_scalar($result) ? [$result] : $result;
                    $responses[] = (new ResponseFactory)->createResultResponse($result, $request->id);

                    $event         = new ProcessedEvent();
                    $event->time   = round((static::microtime() - $microtime) * 1000, 2);
                    $event->method = $request->method;
                    $this->dispatch($event);
                } catch (\Throwable $ex) {
                    $message     = sprintf('%s %s in %s on line %s', $ex->getMessage(), get_class($ex), $ex->getFile(), $ex->getLine());
                    $code        = $ex->getCode();
                    $responses[] = (new ResponseFactory)->createErrorResponse($code, $ex->getMessage(), $request->id);

                    $event         = new ProcessedEvent();
                    $event->time   = round((static::microtime() - $microtime) * 1000, 2);
                    $event->method = $request->method;
                    $event->error  = sprintf('[%d] %s', $code, $message);
                    $this->dispatch($event);
                }
            });
        }
        $waitGroup->wait();
        return $responses;
    }

    /**
     * Handle HTTP
     * @param ServerRequest $request
     * @param \Mix\Http\Message\Response $response
     */
    public function handleHTTP(ServerRequest $request, \Mix\Http\Message\Response $response)
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (strpos($contentType, 'application/json') === false) {
            $response->withStatus(500)->end();
            return;
        }
        $content = $request->getBody()->getContents();
        $this->callHTTP($response, $content);
    }

    /**
     * 获取微秒时间
     * @return float
     */
    protected static function microtime()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * Dispatch
     * @param object $event
     */
    protected function dispatch(object $event)
    {
        if (!isset($this->dispatcher)) {
            return;
        }
        $this->dispatcher->dispatch($event);
    }

    /**
     * Shutdown
     * @throws \Swoole\Exception
     */
    public function shutdown()
    {
        $this->server->shutdown();
    }

}
