<?php

declare(strict_types=1);

/*
 * Complexity gate (Spec V4.3.3 §13): per method, cyclomatic ≤ 10, nesting ≤ 3,
 * parameters ≤ 4. Accepted breaches live in tools/complexity-baseline.json,
 * which can only shrink: a new breach, a changed value or a stale entry fails.
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

const BUDGET = ['cyclomatic' => 10, 'nesting' => 3, 'parameters' => 4];

$decisions = [
    Node\Stmt\If_::class, Node\Stmt\ElseIf_::class, Node\Stmt\For_::class, Node\Stmt\Foreach_::class,
    Node\Stmt\While_::class, Node\Stmt\Do_::class, Node\Stmt\Catch_::class, Node\Expr\Ternary::class,
    Node\Expr\BinaryOp\BooleanAnd::class, Node\Expr\BinaryOp\BooleanOr::class,
    Node\Expr\BinaryOp\LogicalAnd::class, Node\Expr\BinaryOp\LogicalOr::class, Node\Expr\BinaryOp\Coalesce::class,
];
$nesting = [
    Node\Stmt\If_::class, Node\Stmt\For_::class, Node\Stmt\Foreach_::class, Node\Stmt\While_::class,
    Node\Stmt\Do_::class, Node\Stmt\Switch_::class, Node\Stmt\TryCatch::class, Node\Expr\Match_::class,
];

$depth = static function (Node $node, int $level) use (&$depth, $nesting): int {
    $deepest = $level;
    foreach ($node->getSubNodeNames() as $name) {
        foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) {
            if (!$child instanceof Node || $child instanceof Node\Expr\Closure || $child instanceof Node\Expr\ArrowFunction) {
                continue;
            }
            $nested = in_array($child::class, $nesting, true) ? $level + 1 : $level;
            $deepest = max($deepest, $depth($child, $nested));
        }
    }

    return $deepest;
};

$parser = (new ParserFactory())->createForNewestSupportedVersion();
$finder = new NodeFinder();
$measured = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $ast = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
        foreach ($class->getMethods() as $method) {
            $cyclomatic = 1;
            foreach ($finder->find($method->stmts ?? [], static fn(Node $n): bool => in_array($n::class, $decisions, true)
                || ($n instanceof Node\Stmt\Case_ && $n->cond !== null)
                || ($n instanceof Node\MatchArm && $n->conds !== null)) as $ignored) {
                ++$cyclomatic;
            }
            $id = ($class->namespacedName ?? $class->name) . '::' . $method->name;
            $measured[$id] = [
                'cyclomatic' => $cyclomatic,
                'nesting' => $depth($method, 0),
                'parameters' => count($method->params),
            ];
        }
    }
}

$baselineFile = __DIR__ . '/complexity-baseline.json';
$baseline = is_file($baselineFile) ? json_decode((string) file_get_contents($baselineFile), true, flags: JSON_THROW_ON_ERROR) : [];
$failures = [];
$breaches = [];

foreach ($measured as $id => $values) {
    foreach (BUDGET as $metric => $limit) {
        if ($values[$metric] <= $limit) {
            continue;
        }
        $breaches["{$id}.{$metric}"] = $values[$metric];
        if (($baseline[$id][$metric] ?? null) !== $values[$metric]) {
            $failures[] = "{$id}: {$metric} {$values[$metric]} > {$limit} (not in baseline or value changed)";
        }
    }
}
foreach ($baseline as $id => $metrics) {
    foreach ($metrics as $metric => $value) {
        if (!isset($breaches["{$id}.{$metric}"])) {
            $failures[] = "{$id}: baseline entry {$metric}={$value} no longer breaches; remove it";
        }
    }
}

$max = static fn(string $metric): int => max(array_column($measured, $metric) ?: [0]);
printf(
    "complexity: %d methods; max cyclomatic %d, nesting %d, parameters %d; %d accepted breaches\n",
    count($measured),
    $max('cyclomatic'),
    $max('nesting'),
    $max('parameters'),
    count($breaches),
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL {$failure}\n");
}

exit($failures === [] ? 0 : 1);
