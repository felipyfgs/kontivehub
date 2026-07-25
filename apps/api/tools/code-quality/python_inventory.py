#!/usr/bin/env python3
"""Extract deterministic symbols from Python without importing application code."""

from __future__ import annotations

import ast
import hashlib
import io
import json
from pathlib import Path
import sys
import tokenize
from typing import Any


BRANCH_TYPES = (
    ast.If,
    ast.For,
    ast.AsyncFor,
    ast.While,
    ast.IfExp,
    ast.Match,
    ast.match_case,
    ast.ExceptHandler,
)
DECLARATION_TYPES = (ast.ClassDef, ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda)


def _annotation(node: ast.expr | None) -> str | None:
    if node is None:
        return None
    try:
        return ast.unparse(node)
    except (AttributeError, ValueError):
        return ast.dump(node, include_attributes=False)


def _parameters(node: ast.FunctionDef | ast.AsyncFunctionDef | ast.Lambda) -> list[dict[str, Any]]:
    arguments = node.args
    positional = [*arguments.posonlyargs, *arguments.args]
    positional_defaults = [None] * (len(positional) - len(arguments.defaults)) + list(arguments.defaults)
    params: list[dict[str, Any]] = []

    for argument, default in zip(positional, positional_defaults, strict=True):
        params.append(
            {
                "name": argument.arg,
                "type": _annotation(argument.annotation),
                "optional": default is not None,
                "variadic": False,
                "byReference": False,
            }
        )

    if arguments.vararg is not None:
        params.append(
            {
                "name": arguments.vararg.arg,
                "type": _annotation(arguments.vararg.annotation),
                "optional": True,
                "variadic": True,
                "byReference": False,
            }
        )

    for argument, default in zip(arguments.kwonlyargs, arguments.kw_defaults, strict=True):
        params.append(
            {
                "name": argument.arg,
                "type": _annotation(argument.annotation),
                "optional": default is not None,
                "variadic": False,
                "byReference": False,
            }
        )

    if arguments.kwarg is not None:
        params.append(
            {
                "name": arguments.kwarg.arg,
                "type": _annotation(arguments.kwarg.annotation),
                "optional": True,
                "variadic": True,
                "byReference": False,
            }
        )

    return params


def _branch_weight(node: ast.AST) -> int:
    if isinstance(node, ast.BoolOp):
        return max(0, len(node.values) - 1)
    if isinstance(node, ast.Try):
        return 0
    return int(isinstance(node, BRANCH_TYPES))


def _branch_metrics(root: ast.AST) -> tuple[int, int]:
    branches = 0
    max_depth = 0

    def visit(node: ast.AST, depth: int, is_root: bool = False) -> None:
        nonlocal branches, max_depth
        if not is_root and isinstance(node, DECLARATION_TYPES):
            return

        weight = _branch_weight(node)
        if weight:
            branches += weight
            depth += 1
            max_depth = max(max_depth, depth)

        for child in ast.iter_child_nodes(node):
            visit(child, depth)

    visit(root, 0, True)
    return branches, max_depth


def _token_count(source: str) -> int:
    ignored = {
        tokenize.ENCODING,
        tokenize.ENDMARKER,
        tokenize.INDENT,
        tokenize.DEDENT,
        tokenize.NEWLINE,
        tokenize.NL,
        tokenize.COMMENT,
    }
    try:
        return sum(
            1
            for token in tokenize.generate_tokens(io.StringIO(source).readline)
            if token.type not in ignored
        )
    except (IndentationError, tokenize.TokenError):
        return 0


def _import_fan_out(tree: ast.Module) -> int:
    imports: set[str] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            imports.update(alias.name for alias in node.names)
        elif isinstance(node, ast.ImportFrom):
            imports.add(node.module or ".")
    return len(imports)


class SymbolVisitor(ast.NodeVisitor):
    def __init__(self, source: str, repo_path: str, import_fan_out: int) -> None:
        self.source = source
        self.repo_path = repo_path
        self.import_fan_out = import_fan_out
        self.symbols: list[dict[str, Any]] = []
        self.stack: list[dict[str, str]] = []
        self.anonymous_sequence = 0

    def visit_ClassDef(self, node: ast.ClassDef) -> Any:
        return self._visit_declaration(node, "class", node.name, self.generic_visit)

    def visit_FunctionDef(self, node: ast.FunctionDef) -> Any:
        kind = "method" if self.stack and self.stack[-1]["kind"] == "class" else "function"
        return self._visit_declaration(node, kind, node.name, self.generic_visit)

    def visit_AsyncFunctionDef(self, node: ast.AsyncFunctionDef) -> Any:
        kind = "method" if self.stack and self.stack[-1]["kind"] == "class" else "function"
        return self._visit_declaration(node, kind, node.name, self.generic_visit)

    def visit_Lambda(self, node: ast.Lambda) -> Any:
        self.anonymous_sequence += 1
        return self._visit_declaration(
            node,
            "arrow-function",
            f"lambda#{self.anonymous_sequence}",
            self.generic_visit,
        )

    def _visit_declaration(
        self,
        node: ast.AST,
        kind: str,
        display_name: str,
        visit_children: Any,
    ) -> Any:
        parent = self.stack[-1] if self.stack else None
        qualified = self._qualified_name(kind, display_name, parent)
        start_line = max(1, getattr(node, "lineno", 1))
        end_line = max(start_line, getattr(node, "end_lineno", start_line) or start_line)
        segment = ast.get_source_segment(self.source, node) or ""
        parameters = _parameters(node) if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda)) else []
        branches, max_depth = _branch_metrics(node)
        symbol_id = f"{self.repo_path}::{qualified}@{start_line}"
        symbol = {
            "id": symbol_id,
            "path": self.repo_path,
            "qualifiedName": qualified,
            "displayName": display_name,
            "parentId": parent["id"] if parent else None,
            "kind": kind,
            "language": "python",
            "startLine": start_line,
            "endLine": end_line,
            "parameters": parameters,
            "metrics": {
                "lines": end_line - start_line + 1,
                "branches": branches,
                "complexity": branches + 1,
                "maxDepth": max_depth,
                "parameterCount": len(parameters),
                "importFanOut": self.import_fan_out,
                "tokenCount": _token_count(segment),
            },
            "fingerprint": hashlib.sha256(
                ast.dump(node, annotate_fields=True, include_attributes=False).encode("utf-8")
            ).hexdigest(),
        }
        self.symbols.append(symbol)
        self.stack.append({"id": symbol_id, "qualifiedName": qualified, "kind": kind})
        try:
            return visit_children(node)
        finally:
            self.stack.pop()

    def _qualified_name(self, kind: str, display_name: str, parent: dict[str, str] | None) -> str:
        if parent is None:
            return display_name
        if kind == "method" and parent["kind"] == "class":
            return f"{parent['qualifiedName']}::{display_name}"
        if kind == "arrow-function":
            return f"{parent['qualifiedName']}::{{{display_name}}}"
        return f"{parent['qualifiedName']}.<locals>.{display_name}"


def collect_source(source: str, repo_path: str) -> dict[str, Any]:
    try:
        tree = ast.parse(source, filename=repo_path, type_comments=True)
    except SyntaxError as error:
        message = " ".join(str(error.msg).split())[:240]
        return {
            "symbols": [],
            "parseErrors": [
                {
                    "language": "python",
                    "line": error.lineno,
                    "message": message,
                }
            ],
        }

    visitor = SymbolVisitor(source, repo_path, _import_fan_out(tree))
    visitor.visit(tree)
    visitor.symbols.sort(key=lambda symbol: (symbol["startLine"], symbol["id"]))
    return {"symbols": visitor.symbols, "parseErrors": []}


def collect_paths(root: Path, repo_paths: list[str]) -> dict[str, dict[str, Any]]:
    root = root.resolve(strict=True)
    output: dict[str, dict[str, Any]] = {}
    for repo_path in sorted(set(repo_paths)):
        if not repo_path.startswith("apps/api/"):
            raise ValueError(f"Path fora de apps/api: {repo_path}")
        absolute = (root / repo_path.removeprefix("apps/api/")).resolve(strict=True)
        if root not in absolute.parents or not absolute.is_file():
            raise ValueError(f"Arquivo Python fora da raiz: {repo_path}")
        output[repo_path] = collect_source(absolute.read_text(encoding="utf-8"), repo_path)
    return output


def main() -> int:
    request = json.load(sys.stdin)
    root = Path(request["root"])
    paths = request.get("paths", [])
    if not isinstance(paths, list) or not all(isinstance(path, str) for path in paths):
        raise ValueError("paths deve ser uma lista de strings")
    response = collect_paths(root, paths)
    json.dump(response, sys.stdout, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
