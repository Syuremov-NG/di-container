<?php

declare(strict_types=1);

namespace Nsyuremov\DiContainer\src;

use Psr\Container\NotFoundExceptionInterface;

class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}