<?php

namespace Tools\CodeQuality;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class PhpSymbolCollector
{
    private Parser $parser;

    private Standard $printer;

    private int $anonymousSequence = 0;

    public function __construct(?Parser $parser = null, ?Standard $printer = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
        $this->printer = $printer ?? new Standard;
    }

    /**
     * @return array{
     *     symbols: list<array<string, mixed>>,
     *     parseErrors: list<array{language: string, line: int|null, message: string}>
     * }
     */
    public function collect(string $source, string $repoPath): array
    {
        $this->anonymousSequence = 0;

        try {
            $statements = $this->parser->parse($source) ?? [];
            $statements = (new NodeTraverser(new NameResolver))->traverse($statements);
        } catch (Error $error) {
            return [
                'symbols' => [],
                'parseErrors' => [[
                    'language' => 'php',
                    'line' => $error->getStartLine() > 0 ? $error->getStartLine() : null,
                    'message' => $this->sanitizeError($error->getRawMessage()),
                ]],
            ];
        }

        $importFanOut = $this->importFanOut($statements);
        $symbols = [];
        foreach ($statements as $statement) {
            $this->collectNode($statement, $repoPath, null, $importFanOut, $symbols);
        }

        usort($symbols, fn (array $left, array $right): int => [$left['startLine'], $left['id']] <=> [$right['startLine'], $right['id']]);

        return ['symbols' => $symbols, 'parseErrors' => []];
    }

    /**
     * @param  list<array<string, mixed>>  $symbols
     * @param  array{id: string, qualifiedName: string, kind: string}|null  $parent
     */
    private function collectNode(
        Node $node,
        string $repoPath,
        ?array $parent,
        int $importFanOut,
        array &$symbols,
    ): void {
        $currentParent = $parent;
        $descriptor = $this->describeDeclaration($node, $repoPath, $parent);

        if ($descriptor !== null) {
            $symbol = $this->symbol($node, $repoPath, $descriptor, $parent, $importFanOut);
            $symbols[] = $symbol;
            $currentParent = [
                'id' => $symbol['id'],
                'qualifiedName' => $symbol['qualifiedName'],
                'kind' => $symbol['kind'],
            ];
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $this->collectNode($child, $repoPath, $currentParent, $importFanOut, $symbols);
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $this->collectNode($item, $repoPath, $currentParent, $importFanOut, $symbols);
                    }
                }
            }
        }
    }

    /**
     * @param  array{id: string, qualifiedName: string, kind: string}|null  $parent
     * @return array{kind: string, qualifiedName: string, displayName: string}|null
     */
    private function describeDeclaration(Node $node, string $repoPath, ?array $parent): ?array
    {
        if ($node instanceof Stmt\ClassLike) {
            $kind = match (true) {
                $node instanceof Stmt\Interface_ => 'interface',
                $node instanceof Stmt\Trait_ => 'trait',
                $node instanceof Stmt\Enum_ => 'enum',
                default => 'class',
            };
            $name = $node->name?->toString();
            $qualified = isset($node->namespacedName)
                ? $node->namespacedName->toString()
                : ($parent['qualifiedName'] ?? $repoPath).'::{anonymous-class#'.$this->nextAnonymous().'}';

            return [
                'kind' => $kind,
                'qualifiedName' => $qualified,
                'displayName' => $name ?? 'anonymous-class',
            ];
        }

        if ($node instanceof Stmt\ClassMethod) {
            $name = $node->name->toString();
            $owner = $parent['qualifiedName'] ?? $repoPath;

            return [
                'kind' => 'method',
                'qualifiedName' => $owner.'::'.$name,
                'displayName' => $name,
            ];
        }

        if ($node instanceof Stmt\Function_) {
            $name = $node->name->toString();
            $qualified = isset($node->namespacedName)
                ? $node->namespacedName->toString()
                : ($parent['qualifiedName'] ?? $repoPath).'::'.$name;

            return [
                'kind' => 'function',
                'qualifiedName' => $qualified,
                'displayName' => $name,
            ];
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $sequence = $this->nextAnonymous();
            $label = $node instanceof Expr\Closure ? 'closure' : 'arrow';
            $owner = $parent['qualifiedName'] ?? $repoPath;

            return [
                'kind' => $node instanceof Expr\Closure ? 'closure' : 'arrow-function',
                'qualifiedName' => $owner.'::{'.$label.'#'.$sequence.'}',
                'displayName' => $label.'#'.$sequence,
            ];
        }

        return null;
    }

    /**
     * @param  array{kind: string, qualifiedName: string, displayName: string}  $descriptor
     * @param  array{id: string, qualifiedName: string, kind: string}|null  $parent
     * @return array<string, mixed>
     */
    private function symbol(
        Node $node,
        string $repoPath,
        array $descriptor,
        ?array $parent,
        int $importFanOut,
    ): array {
        $startLine = max(1, $node->getStartLine());
        $endLine = max($startLine, $node->getEndLine());
        $parameters = $node instanceof FunctionLike ? $this->parameters($node) : [];
        $normalized = $this->normalizedSource($node);
        [$branches, $maxDepth] = $this->branchMetrics($node);

        return [
            'id' => $repoPath.'::'.$descriptor['qualifiedName'].'@'.$startLine,
            'path' => $repoPath,
            'qualifiedName' => $descriptor['qualifiedName'],
            'displayName' => $descriptor['displayName'],
            'parentId' => $parent['id'] ?? null,
            'kind' => $descriptor['kind'],
            'language' => 'php',
            'startLine' => $startLine,
            'endLine' => $endLine,
            'parameters' => $parameters,
            'metrics' => [
                'lines' => $endLine - $startLine + 1,
                'branches' => $branches,
                'complexity' => $branches + 1,
                'maxDepth' => $maxDepth,
                'parameterCount' => count($parameters),
                'importFanOut' => $importFanOut,
                'tokenCount' => $this->tokenCount($normalized),
            ],
            'fingerprint' => hash('sha256', $normalized),
        ];
    }

    /** @return list<array{name: string, type: string|null, optional: bool, variadic: bool, byReference: bool}> */
    private function parameters(FunctionLike $node): array
    {
        return array_map(function (Node\Param $parameter): array {
            $name = $parameter->var instanceof Expr\Variable && is_string($parameter->var->name)
                ? $parameter->var->name
                : 'unknown';

            return [
                'name' => $name,
                'type' => $this->typeToString($parameter->type),
                'optional' => $parameter->default !== null || $parameter->variadic,
                'variadic' => $parameter->variadic,
                'byReference' => $parameter->byRef,
            ];
        }, $node->getParams());
    }

    private function typeToString(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Name || $type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?'.$this->typeToString($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn (Node $item): string => (string) $this->typeToString($item), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn (Node $item): string => (string) $this->typeToString($item), $type->types));
        }

        return $type->getType();
    }

    /** @param list<Stmt> $statements */
    private function importFanOut(array $statements): int
    {
        $imports = [];
        $visit = function (Node $node) use (&$visit, &$imports): void {
            if ($node instanceof Stmt\Use_) {
                foreach ($node->uses as $use) {
                    $imports[$use->name->toString()] = true;
                }
            } elseif ($node instanceof Stmt\GroupUse) {
                foreach ($node->uses as $use) {
                    $imports[$node->prefix->toString().'\\'.$use->name->toString()] = true;
                }
            }
            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};
                if ($child instanceof Node) {
                    $visit($child);
                } elseif (is_array($child)) {
                    foreach ($child as $item) {
                        if ($item instanceof Node) {
                            $visit($item);
                        }
                    }
                }
            }
        };

        foreach ($statements as $statement) {
            $visit($statement);
        }

        return count($imports);
    }

    /** @return array{int, int} */
    private function branchMetrics(Node $root): array
    {
        $branches = 0;
        $maxDepth = 0;

        $visit = function (Node $node, int $depth, bool $isRoot = false) use (&$visit, &$branches, &$maxDepth): void {
            if (! $isRoot && $this->isDeclaration($node)) {
                return;
            }

            $branchWeight = $this->branchWeight($node);
            $isControl = $branchWeight > 0;
            if ($isControl) {
                $branches += $branchWeight;
                $depth++;
                $maxDepth = max($maxDepth, $depth);
            }

            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};
                if ($child instanceof Node) {
                    $visit($child, $depth);
                } elseif (is_array($child)) {
                    foreach ($child as $item) {
                        if ($item instanceof Node) {
                            $visit($item, $depth);
                        }
                    }
                }
            }
        };

        $visit($root, 0, true);

        return [$branches, $maxDepth];
    }

    private function branchWeight(Node $node): int
    {
        if ($node instanceof Expr\BinaryOp\BooleanAnd || $node instanceof Expr\BinaryOp\BooleanOr) {
            return 1;
        }

        return (int) ($node instanceof Stmt\If_
            || $node instanceof Stmt\ElseIf_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
            || $node instanceof Stmt\Case_
            || $node instanceof Stmt\Catch_
            || $node instanceof Expr\Ternary
            || $node instanceof Expr\BinaryOp\Coalesce
            || $node instanceof Node\MatchArm);
    }

    private function isDeclaration(Node $node): bool
    {
        return $node instanceof Stmt\ClassLike
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Stmt\Function_
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction;
    }

    private function normalizedSource(Node $node): string
    {
        $printed = $node instanceof Expr
            ? $this->printer->prettyPrintExpr($node)
            : $this->printer->prettyPrint([$node]);

        return trim((string) preg_replace('/\s+/', ' ', $printed));
    }

    private function tokenCount(string $source): int
    {
        $count = 0;
        foreach (token_get_all('<?php '.$source) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function nextAnonymous(): int
    {
        return ++$this->anonymousSequence;
    }

    private function sanitizeError(string $message): string
    {
        $message = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message));

        return mb_substr($message, 0, 240);
    }
}
