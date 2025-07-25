<?php

declare(strict_types=1);

namespace RectorLaravel\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ThisType;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\BetterPhpDocParser\ValueObject\Type\FullyQualifiedIdentifierTypeNode;
use Rector\Comments\NodeDocBlock\DocBlockUpdater;
use Rector\NodeTypeResolver\TypeComparator\TypeComparator;
use Rector\PhpParser\Node\BetterNodeFinder;
use Rector\PHPStan\ScopeFetcher;
use Rector\StaticTypeMapper\StaticTypeMapper;
use RectorLaravel\AbstractRector;
use RectorLaravel\NodeAnalyzer\ApplicationAnalyzer;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \RectorLaravel\Tests\Rector\ClassMethod\AddGenericReturnTypeToRelationsRector\AddGenericReturnTypeToRelationsRectorNewGenericsTest
 * @see \RectorLaravel\Tests\Rector\ClassMethod\AddGenericReturnTypeToRelationsRector\AddGenericReturnTypeToRelationsRectorOldGenericsTest
 */
class AddGenericReturnTypeToRelationsRector extends AbstractRector
{
    /**
     * @readonly
     */
    private TypeComparator $typeComparator;
    /**
     * @readonly
     */
    private DocBlockUpdater $docBlockUpdater;
    /**
     * @readonly
     */
    private PhpDocInfoFactory $phpDocInfoFactory;
    /**
     * @readonly
     */
    private BetterNodeFinder $betterNodeFinder;
    /**
     * @readonly
     */
    private StaticTypeMapper $staticTypeMapper;
    /**
     * @readonly
     */
    private ReflectionProvider $reflectionProvider;
    /**
     * @readonly
     */
    private ApplicationAnalyzer $applicationAnalyzer;
    // Relation methods which are supported by this Rector.
    /**
     * @var mixed[]
     */
    private const RELATION_METHODS = [
        'hasOne', 'hasOneThrough', 'morphOne',
        'belongsTo', 'morphTo',
        'hasMany', 'hasManyThrough', 'morphMany',
        'belongsToMany', 'morphToMany', 'morphedByMany',
    ];

    // Relation methods which need the class as TChildModel.
    /**
     * @var mixed[]
     */
    private const RELATION_WITH_CHILD_METHODS = ['belongsTo', 'morphTo'];

    // Relation methods which need the class as TIntermediateModel.
    /**
     * @var mixed[]
     */
    private const RELATION_WITH_INTERMEDIATE_METHODS = ['hasManyThrough', 'hasOneThrough'];

    private bool $shouldUseNewGenerics = false;
    private bool $shouldUsePivotGeneric = false;

    public function __construct(TypeComparator $typeComparator, DocBlockUpdater $docBlockUpdater, PhpDocInfoFactory $phpDocInfoFactory, BetterNodeFinder $betterNodeFinder, StaticTypeMapper $staticTypeMapper, ReflectionProvider $reflectionProvider, ApplicationAnalyzer $applicationAnalyzer)
    {
        $this->typeComparator = $typeComparator;
        $this->docBlockUpdater = $docBlockUpdater;
        $this->phpDocInfoFactory = $phpDocInfoFactory;
        $this->betterNodeFinder = $betterNodeFinder;
        $this->staticTypeMapper = $staticTypeMapper;
        $this->reflectionProvider = $reflectionProvider;
        $this->applicationAnalyzer = $applicationAnalyzer;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Add generic return type to relations in child of Illuminate\Database\Eloquent\Model',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
use App\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
CODE_SAMPLE

                    ,
                    <<<'CODE_SAMPLE'
use App\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    /** @return HasMany<Account> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
CODE_SAMPLE
                ),
                new CodeSample(
                    <<<'CODE_SAMPLE'
use App\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
CODE_SAMPLE

                    ,
                    <<<'CODE_SAMPLE'
use App\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof ClassMethod) {
            return null;
        }

        $scope = ScopeFetcher::fetch($node);

        if ($this->shouldSkipNode($node, $scope)) {
            return null;
        }

        $methodReturnType = $node->getReturnType();

        if ($methodReturnType === null) {
            return null;
        }

        $methodReturnTypeName = $this->getName($methodReturnType);

        if ($methodReturnTypeName === null) {
            return null;
        }

        if (! $this->isObjectType(
            $methodReturnType,
            new ObjectType('Illuminate\Database\Eloquent\Relations\Relation')
        )) {
            return null;
        }

        $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($node);

        // Don't update an existing return type if it differs from the native return type (thus the one without generics).
        // E.g. we only add generics to an existing return type, but don't change the type itself.
        if (
            $phpDocInfo->getReturnTagValue() instanceof ReturnTagValueNode
            && ! $this->areNativeTypeAndPhpDocReturnTypeEqual(
                $node,
                $methodReturnType,
                $phpDocInfo->getReturnTagValue()
            )
        ) {
            return null;
        }

        $relationMethodCall = $this->getRelationMethodCall($node);
        if (! $relationMethodCall instanceof MethodCall) {
            return null;
        }

        $relatedClass = $this->getRelatedModelClassFromMethodCall($relationMethodCall);

        if ($relatedClass === null) {
            return null;
        }

        // Put here to make the check as late as possible
        $this->setShouldUseNewGenerics();
        $this->setShouldUsePivotGeneric();

        $classForChildGeneric = $this->getClassForChildGeneric($scope, $relationMethodCall);
        $classForIntermediateGeneric = $this->getClassForIntermediateGeneric($relationMethodCall);

        // Don't update the docblock if return type already contains the correct generics. This avoids overwriting
        // non-FQCN with our fully qualified ones.
        if (
            $phpDocInfo->getReturnTagValue() instanceof ReturnTagValueNode
            && $this->areGenericTypesEqual(
                $node,
                $phpDocInfo->getReturnTagValue(),
                $relatedClass,
                $classForChildGeneric,
                $classForIntermediateGeneric
            )
        ) {
            return null;
        }

        $genericTypeNode = new GenericTypeNode(
            new FullyQualifiedIdentifierTypeNode($methodReturnTypeName),
            $this->getGenericTypes(
                $methodReturnType,
                $relatedClass,
                $classForChildGeneric,
                $classForIntermediateGeneric
            ),
        );

        // Update or add return tag
        if ($phpDocInfo->getReturnTagValue() instanceof ReturnTagValueNode) {
            $phpDocInfo->getReturnTagValue()
                ->type = $genericTypeNode;
        } else {
            $phpDocInfo->addTagValueNode(new ReturnTagValueNode($genericTypeNode, ''));
        }

        $this->docBlockUpdater->updateRefactoredNodeWithPhpDocInfo($node);

        return $node;
    }

    private function getRelatedModelClassFromMethodCall(MethodCall $methodCall): ?string
    {
        $argType = $this->getType($methodCall->getArgs()[0]->value);

        $objectClassNames = $argType->getClassStringObjectType()->getObjectClassNames();

        if ($objectClassNames === []) {
            return null;
        }

        return $objectClassNames[0];
    }

    private function getRelationMethodCall(ClassMethod $classMethod): ?MethodCall
    {
        $node = $this->betterNodeFinder->findFirstInFunctionLikeScoped(
            $classMethod,
            fn (Node $subNode): bool => $subNode instanceof Return_
        );

        if (! $node instanceof Return_) {
            return null;
        }

        $methodCall = $this->betterNodeFinder->findFirstInstanceOf($node, MethodCall::class);

        if (! $methodCall instanceof MethodCall) {
            return null;
        }

        // Find deepest MethodCall, which is the first in code, to allow chaining:
        // $this->hasMany(..)->orderBy(..)->with(..)
        while ($methodCall->var instanceof MethodCall) {
            $methodCall = $methodCall->var;
        }

        // Called method should be one of the Laravel's relation methods
        if (! $this->doesMethodHasName($methodCall, self::RELATION_METHODS)) {
            return null;
        }

        if (count($methodCall->getArgs()) < 1) {
            return null;
        }

        return $methodCall;
    }

    /**
     * We need the current class for generics which need a TChildModel. This is the case by for example the BelongsTo
     * relation.
     */
    private function getClassForChildGeneric(Scope $scope, MethodCall $methodCall): ?string
    {
        if ($this->shouldUseNewGenerics) {
            return null;
        }

        if (! $this->doesMethodHasName($methodCall, self::RELATION_WITH_CHILD_METHODS)) {
            return null;
        }

        $classReflection = $scope->getClassReflection();

        return ($nullsafeVariable1 = $classReflection) ? $nullsafeVariable1->getName() : null;
    }

    /**
     * We need the intermediate class for generics which need a TIntermediateModel.
     * This is the case for *through relations
     */
    private function getClassForIntermediateGeneric(MethodCall $methodCall): ?string
    {
        if (! $this->shouldUseNewGenerics) {
            return null;
        }

        if (! $this->doesMethodHasName($methodCall, self::RELATION_WITH_INTERMEDIATE_METHODS)) {
            return null;
        }

        $args = $methodCall->getArgs();

        if (count($args) < 2) {
            return null;
        }

        $argType = $this->getType($args[1]->value);

        $objectClassNames = $argType->getClassStringObjectType()->getObjectClassNames();

        if ($objectClassNames === []) {
            return null;
        }

        return $objectClassNames[0];
    }

    private function areNativeTypeAndPhpDocReturnTypeEqual(
        ClassMethod $classMethod,
        Node $node,
        ReturnTagValueNode $returnTagValueNode
    ): bool {
        $phpDocPHPStanType = $this->staticTypeMapper->mapPHPStanPhpDocTypeNodeToPHPStanType(
            $returnTagValueNode->type,
            $classMethod
        );

        $phpDocPHPStanTypeWithoutGenerics = $phpDocPHPStanType;
        if ($phpDocPHPStanType instanceof GenericObjectType) {
            $phpDocPHPStanTypeWithoutGenerics = new ObjectType($phpDocPHPStanType->getClassName());
        }

        $methodReturnTypePHPStanType = $this->staticTypeMapper->mapPhpParserNodePHPStanType($node);

        return $this->typeComparator->areTypesEqual(
            $methodReturnTypePHPStanType,
            $phpDocPHPStanTypeWithoutGenerics,
        );
    }

    private function areGenericTypesEqual(
        Node $node,
        ReturnTagValueNode $returnTagValueNode,
        string $relatedClass,
        ?string $classForChildGeneric,
        ?string $classForIntermediateGeneric
    ): bool {
        $phpDocPHPStanType = $this->staticTypeMapper->mapPHPStanPhpDocTypeNodeToPHPStanType(
            $returnTagValueNode->type,
            $node
        );
        $relationUsesPivots = $this->typeComparator->isSubtype($phpDocPHPStanType, new ObjectType('Illuminate\Database\Eloquent\Relations\BelongsToMany'))
            || $this->typeComparator->isSubtype($phpDocPHPStanType, new ObjectType('Illuminate\Database\Eloquent\Relations\MorphToMany'));

        if (! $phpDocPHPStanType instanceof GenericObjectType) {
            return false;
        }

        $phpDocTypes = $phpDocPHPStanType->getTypes();
        if ($phpDocTypes === []) {
            return false;
        }

        if (
            $this->typeComparator->areTypesEqual($phpDocTypes[0], new ObjectType($relatedClass))
            && count($phpDocTypes) > 1
            && $phpDocTypes[1] instanceof ThisType
            && ! $relationUsesPivots
        ) {
            return true;
        }

        if (! $this->shouldUseNewGenerics) {
            $phpDocHasChildGeneric = count($phpDocTypes) === 2;

            if ($classForChildGeneric === null && ! $phpDocHasChildGeneric) {
                return true;
            }

            if ($classForChildGeneric === null || ! $phpDocHasChildGeneric) {
                return false;
            }

            return $this->typeComparator->areTypesEqual($phpDocTypes[1], new ObjectType($classForChildGeneric));
        }

        if ($classForIntermediateGeneric === null && $relationUsesPivots) {
            // If there are less than three generics, it means method is using the old format. We should update it.
            if (count($phpDocTypes) < 3) {
                return false;
            }

            // We want to convert the existing relationship definition to use `$this` as the second generic
            // but only if the PHPDoc Tag doesn't look valid already
            return
                $this->typeComparator->areTypesEqual($phpDocTypes[0], new ObjectType($relatedClass))
                && $phpDocTypes[1] instanceof ThisType
                && $this->typeComparator->isSubtype($phpDocTypes[2], new ObjectType('Illuminate\Database\Eloquent\Relations\Pivot'));
        }

        if ($classForIntermediateGeneric === null) {
            return false;
        }

        return $this->typeComparator->areTypesEqual($phpDocTypes[1], new ObjectType($classForIntermediateGeneric));
    }

    private function shouldSkipNode(ClassMethod $classMethod, Scope $scope): bool
    {
        if ($classMethod->stmts === null) {
            return true;
        }

        $classReflection = $scope->getClassReflection();

        if (! $classReflection instanceof ClassReflection || $classReflection->isAnonymous()) {
            return true;
        }

        return ! $classReflection->isTrait()
            && ! $classReflection->isSubclassOfClass($this->reflectionProvider->getClass('Illuminate\Database\Eloquent\Model'));
    }

    /**
     * @param  array<string>  $methodNames
     */
    private function doesMethodHasName(MethodCall $methodCall, array $methodNames): bool
    {
        $methodName = $methodCall->name;

        if (! $methodName instanceof Identifier) {
            return false;
        }

        return in_array($methodName->name, $methodNames, true);
    }

    /**
     * @return IdentifierTypeNode[]
     */
    private function getGenericTypes(Node $node, string $relatedClass, ?string $childClass, ?string $intermediateClass): array
    {
        $generics = [new FullyQualifiedIdentifierTypeNode($relatedClass)];

        if (! $this->shouldUseNewGenerics && $childClass !== null) {
            $generics[] = new FullyQualifiedIdentifierTypeNode($childClass);
        }

        if ($this->shouldUseNewGenerics) {
            if ($intermediateClass !== null) {
                $generics[] = new FullyQualifiedIdentifierTypeNode($intermediateClass);
            }

            $generics[] = new IdentifierTypeNode('$this');

            if ($this->shouldUsePivotGeneric && $this->isObjectType(
                $node,
                new ObjectType('Illuminate\Database\Eloquent\Relations\BelongsToMany')
            )) {
                $generics[] = new FullyQualifiedIdentifierTypeNode('\Illuminate\Database\Eloquent\Relations\Pivot');
            }
        }

        return $generics;
    }

    private function setShouldUseNewGenerics(): void
    {
        $this->shouldUseNewGenerics = $this->applicationAnalyzer->isVersion(
            '>=',
            '11.15.0'
        );
    }

    private function setShouldUsePivotGeneric(): void
    {
        $this->shouldUsePivotGeneric = $this->applicationAnalyzer->isVersion(
            '>=',
            '12.3.0'
        );
    }
}
