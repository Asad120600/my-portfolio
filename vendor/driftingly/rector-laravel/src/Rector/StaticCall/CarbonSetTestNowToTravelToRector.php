<?php

namespace RectorLaravel\Rector\StaticCall;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use Rector\PHPStan\ScopeFetcher;
use RectorLaravel\AbstractRector;
use Symplify\RuleDocGenerator\Exception\PoorDocumentationException;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \RectorLaravel\Tests\Rector\StaticCall\CarbonSetTestNowToTravelToRector\CarbonSetTestNowToTravelToRectorTest
 */
final class CarbonSetTestNowToTravelToRector extends AbstractRector
{
    /**
     * @readonly
     */
    private ReflectionProvider $reflectionProvider;
    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reflectionProvider = $reflectionProvider;
    }

    /**
     * @throws PoorDocumentationException
     */
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Use the `$this->travelTo()` method in Laravel\'s `TestCase` class instead of the `Carbon::setTestNow()` method.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\TestCase;

class SomeTest extends TestCase
{
    public function test()
    {
        Carbon::setTestNow('2024-08-11');
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\TestCase;

class SomeTest extends TestCase
{
    public function test()
    {
        $this->travelTo('2024-08-11');
    }
}
CODE_SAMPLE
                ),
            ],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [StaticCall::class];
    }

    public function refactor(Node $node): ?MethodCall
    {
        if (! $node instanceof StaticCall) {
            return null;
        }

        $scope = ScopeFetcher::fetch($node);

        if (! $scope->isInClass()) {
            return null;
        }

        if (! $scope->getClassReflection()->isSubclassOfClass($this->reflectionProvider->getClass('Illuminate\Foundation\Testing\TestCase'))) {
            return null;
        }

        if (! $this->isName($node->name, 'setTestNow')) {
            return null;
        }

        if (! $this->isCarbon($node->class)) {
            return null;
        }

        $args = $node->args === []
            ? [new Arg($this->nodeFactory->createNull())]
            : $node->args;

        return $this->nodeFactory->createMethodCall(
            new Variable('this'),
            'travelTo',
            $args,
        );
    }

    private function isCarbon(Node $node): bool
    {
        return $this->isObjectType($node, new ObjectType('Carbon\Carbon')) ||
            $this->isObjectType($node, new ObjectType('Carbon\CarbonImmutable')) ||
            $this->isObjectType($node, new ObjectType('Illuminate\Support\Carbon'));
    }
}
