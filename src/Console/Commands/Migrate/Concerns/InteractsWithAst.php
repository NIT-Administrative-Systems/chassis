<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;

trait InteractsWithAst
{
    /**
     * @param  array<Node\Stmt>  $stmts
     * @return list<Node\Stmt>
     */
    protected function cloneStatements(array $stmts): array
    {
        $cloneTraverser = new NodeTraverser();
        $cloneTraverser->addVisitor(new CloningVisitor());

        /** @var list<Node\Stmt> $cloned */
        $cloned = $cloneTraverser->traverse($stmts);

        return $cloned;
    }

    /**
     * @param  array<Node\Stmt>  $stmts
     */
    protected function findClassNode(array $stmts): ?Class_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Class_) {
                return $stmt;
            }

            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    if ($nsStmt instanceof Class_) {
                        return $nsStmt;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<Node\Stmt>  $stmts
     */
    protected function ensureUseStatement(array &$stmts, string $fqcn): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $this->ensureUseStatementInList($stmt->stmts, $fqcn);

                return;
            }
        }

        $this->ensureUseStatementInList($stmts, $fqcn);
    }

    /**
     * @param  array<Node\Stmt>  $newStmts
     * @param  array<Node\Stmt>  $oldStmts
     */
    protected function formatPreservingPrint(Standard $printer, Parser $parser, array $newStmts, array $oldStmts): string
    {
        return $printer->printFormatPreserving($newStmts, $oldStmts, $parser->getTokens());
    }

    /**
     * @param  array<Node\Stmt>  $stmts
     */
    private function ensureUseStatementInList(array &$stmts, string $fqcn): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Use_) {
                foreach ($stmt->uses as $use) {
                    if ($use->name->toString() === $fqcn) {
                        return;
                    }
                }
            }
        }

        $lastUseIndex = -1;
        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof Use_) {
                $lastUseIndex = $i;
            }
        }

        $newUse = new Use_([new UseUse(new Name($fqcn))]);

        if ($lastUseIndex >= 0) {
            array_splice($stmts, $lastUseIndex + 1, 0, [$newUse]);
        } else {
            array_splice($stmts, 1, 0, [$newUse]);
        }
    }
}
