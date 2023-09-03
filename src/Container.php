<?php

declare(strict_types=1);

namespace Nsyuremov\DiContainer;

use Closure;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionParameter;

class Container implements ContainerInterface
{
    private array $itemList = [];
    private array $callbackList = [];
    private array $cacheList = [];
    private array $noShareList = [];

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->itemList) || class_exists($id)) {
            return true;
        }
        return false;
    }

    public function get(string $id, bool $new = false, array $data = []): object
    {
        if (!$new && array_key_exists($id, $this->cacheList)) {
            return $this->cacheList[$id];
        }

        if (isset($this->itemList[$id])) {
            $args = $this->reflectArguments($this->itemList[$id]);
            $obj = call_user_func($this->itemList[$id], ...$args);
        } elseif (class_exists($id)) {
            $reflector = new ReflectionClass($id);
            $args = $reflector->getConstructor() === null ? [] : $this->reflectArguments([$id, '__construct'], [], $data);
            $obj = $reflector->newInstanceArgs($args);
        } else {
            throw new NotFoundException(
                sprintf('Alias (%s) is not an existing class and therefore cannot be resolved', $id)
            );
        }

        foreach ($this->callbackList[$id] ?? [] as $vo) {
            $args = $this->reflectArguments($vo, [$id => $obj]);
            $temp = call_user_func($vo, ...$args);
            $obj =  is_null($temp) ? $obj : $temp;
        }

        if (!in_array($id, $this->noShareList)) {
            $this->cacheList[$id] = $obj;
        }

        return $obj;
    }

    /**
     * @throws ReflectionException
     */
    public function set(string $id, callable $callable, bool $share = true): self
    {
        if (is_array($callable) && is_string($callable[0]) && class_exists($callable[0])) {
            $type = ReflectionClass::class;
            $reflector = new ReflectionClass($callable[0]);
            $params = $reflector->getMethod($callable[1])->getParameters();
        } else {
            $type = ReflectionFunction::class;
            $reflector = new ReflectionFunction(Closure::fromCallable($callable));
            $params = $reflector->getParameters();
        }

        $find = false;
        foreach ($params as $param) {
            if (null !== $type = $param->getType()) {
                if ($type->getName() === $id) {
                    $find = true;
                    break;
                }
            }
        }

        if ($find) {
            $this->callbackList[$id][] = $callable;
        } else {
            $this->itemList[$id] = $callable;
        }
        unset($this->cacheList[$id]);

        $this->setShare($id, $share);
        return $this;
    }

    public function setShare(string $id, bool $share): self
    {
        if ($share) {
            if (false !== $key = array_search($id, $this->noShareList)) {
                unset($this->noShareList[$key]);
            }
        } else {
            if (!in_array($id, $this->noShareList)) {
                $this->noShareList[] = $id;
            }
        }
        return $this;
    }

    public function reflectArguments($callable, array $default = [], array $data = []): array
    {
        if (is_array($callable) && is_string($callable[0]) && class_exists($callable[0])) {
            $reflector = new ReflectionClass($callable[0]);
            $params = $reflector->getMethod($callable[1])->getParameters();
        } else {
            $reflector = new ReflectionFunction(Closure::fromCallable($callable));
            $params = $reflector->getParameters();
        }

        $res = [];

        foreach ($params as $param) {
            $res[] = $this->getParam($param, $default, $data);
        }
        return $res;
    }

    private function getParam(ReflectionParameter $param, array $default = [], array $data = [])
    {
        $type = $param->getType();
        if ($type === null) {
            $paramName = $param->getName();

            if (array_key_exists($paramName, $default)) {
                return $default[$paramName];
            }

            if ($this->has($paramName)) {
                return $this->get($paramName);
            }
        } else {
            $typeName = $type->getName();

            if (array_key_exists($typeName, $default)) {
                return $default[$typeName];
            }

            if (!$type->isBuiltin()) {
                if ($this->has($typeName)) {
                    $result = $this->get($typeName);
                    if ($result instanceof $typeName) {
                        return $result;
                    }
                }
            }

            if (isset($param->name, $data) && gettype($data[$param->name]) == $typeName) {
                return $data[$param->name];
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->isOptional()) {
            return;
        }

        throw new OutOfBoundsException(sprintf(
            'Unable to resolve a value for parameter `$%s` at %s on line %s-%s',
            $param->getName(),
            $param->getDeclaringFunction()->getFileName(),
            $param->getDeclaringFunction()->getStartLine(),
            $param->getDeclaringFunction()->getEndLine(),
        ));
    }
}