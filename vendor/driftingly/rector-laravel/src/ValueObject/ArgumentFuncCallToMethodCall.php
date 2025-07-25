<?php

declare(strict_types=1);

namespace RectorLaravel\ValueObject;

use Rector\Validation\RectorAssert;
use RectorLaravel\Contract\ValueObject\ArgumentFuncCallToMethodCallInterface;

final class ArgumentFuncCallToMethodCall implements ArgumentFuncCallToMethodCallInterface
{
    /**
     * @readonly
     */
    private string $function;
    /**
     * @readonly
     */
    private string $class;
    /**
     * @readonly
     */
    private ?string $methodIfArgs = null;
    /**
     * @readonly
     */
    private ?string $methodIfNoArgs = null;
    public function __construct(
        string $function,
        string $class,
        ?string $methodIfArgs = null,
        ?string $methodIfNoArgs = null
    ) {
        $this->function = $function;
        $this->class = $class;
        $this->methodIfArgs = $methodIfArgs;
        $this->methodIfNoArgs = $methodIfNoArgs;
        RectorAssert::className($class);
        RectorAssert::functionName($function);
    }

    public function getFunction(): string
    {
        return $this->function;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getMethodIfNoArgs(): ?string
    {
        return $this->methodIfNoArgs;
    }

    public function getMethodIfArgs(): ?string
    {
        return $this->methodIfArgs;
    }
}
