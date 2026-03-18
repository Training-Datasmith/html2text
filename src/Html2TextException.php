<?php

declare(strict_types=1);

namespace Soundasleep;

class Html2TextException extends \Exception
{
    /** @var string $more_info */
    public $more_info;

    public function __construct(string $message = '', string $more_info = '')
    {
        parent::__construct($message);
        $this->more_info = $more_info;
    }

}
