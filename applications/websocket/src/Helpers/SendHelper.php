<?php

namespace WebSocket\Helpers;

use Mix\Concurrent\Coroutine\Channel;
use Mix\Helper\JsonHelper;
use Swoole\WebSocket\Frame;

/**
 * Class SendHelper
 * @package WebSocket\Helpers
 * @author liu,jian <coder.keda@gmail.com>
 */
class SendHelper
{

    /**
     * Send error
     * @param Channel $sendChan
     * @param $code
     * @param $message
     * @param null $id
     */
    public static function error(Channel $sendChan, $code, $message, $id = null)
    {
        $data          = [
            'jsonrpc' => '2.0',
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
            'id'      => $id,
        ];
        $frame         = new Frame();
        $frame->opcode = SWOOLE_WEBSOCKET_OPCODE_TEXT;
        $frame->data   = JsonHelper::encode($data, JSON_UNESCAPED_UNICODE);
        $sendChan->push($frame);
    }

    /**
     * Send data
     * @param Channel $sendChan
     * @param $result
     * @param null $id
     */
    public static function data(Channel $sendChan, $result, $id = null)
    {
        $data          = [
            'jsonrpc' => '2.0',
            'error'   => null,
            'result'  => $result,
            'id'      => $id,
        ];
        $frame         = new Frame();
        $frame->opcode = SWOOLE_WEBSOCKET_OPCODE_TEXT;
        $frame->data   = JsonHelper::encode($data, JSON_UNESCAPED_UNICODE);
        $sendChan->push($frame);
    }

}
