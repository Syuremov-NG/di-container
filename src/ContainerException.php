<?php

declare(strict_types=1);

namespace Nsyuremov\DiContainer\src;

use Exception;
use Psr\Container\ContainerExceptionInterface;

class ContainerException extends Exception implements ContainerExceptionInterface
{
}