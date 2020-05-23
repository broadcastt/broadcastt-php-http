<?php

namespace Broadcastt\Exception;

class JsonEncodeException extends \RuntimeException
{
    private $data;

    public function __construct($message, $data, $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }
}
