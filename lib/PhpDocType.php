<?php

declare(strict_types=1);

namespace Terrarium;

/**
 * A small recursive-descent parser for the PHPDoc/PHPStan type grammar, into a
 * neutral type AST. Supports the rich shapes PHP's own type system can't express:
 *
 *   scalars, ?T, A|B unions, T[], array<V>, array<K,V>, list<V>, iterable<V>,
 *   and object shapes: array{name: string, roles?: string[], meta: array{...}}
 *
 * AST node kinds:
 *   ['k'=>'scalar','n'=>int|float|string|bool|null|void|any|object|callable]
 *   ['k'=>'list','of'=>node]
 *   ['k'=>'map','key'=>node,'val'=>node]
 *   ['k'=>'object','fields'=>[['name'=>string,'opt'=>bool,'type'=>node], ...]]
 *   ['k'=>'union','of'=>[node, ...]]
 *   ['k'=>'nullable','of'=>node]
 */
final class PhpDocType
{
    private int $i = 0;

    private function __construct(private string $s)
    {
    }

    public static function parse(string $s): array
    {
        $p = new self(trim($s));
        try {
            $node = $p->union();
            return $node;
        } catch (\Throwable) {
            return ['k' => 'scalar', 'n' => 'any'];
        }
    }

    private function union(): array
    {
        $parts = [$this->atom()];
        while ($this->peek() === '|') {
            $this->i++;
            $parts[] = $this->atom();
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        // Fold a `T|null` union into a nullable for nicer rendering.
        $nonNull = array_values(array_filter($parts, fn ($n) => !($n['k'] === 'scalar' && $n['n'] === 'null')));
        if (count($nonNull) === 1 && count($parts) === 2) {
            return ['k' => 'nullable', 'of' => $nonNull[0]];
        }
        return ['k' => 'union', 'of' => $parts];
    }

    private function atom(): array
    {
        $this->ws();
        if ($this->peek() === '?') {
            $this->i++;
            return ['k' => 'nullable', 'of' => $this->atom()];
        }
        if ($this->peek() === '(') {
            $this->i++;
            $node = $this->union();
            $this->expect(')');
            return $this->postfix($node);
        }

        $id = $this->ident();
        $lower = strtolower(ltrim($id, '\\'));

        if (in_array($lower, ['array', 'list', 'iterable', 'non-empty-array', 'non-empty-list'], true)) {
            $this->ws();
            if ($this->peek() === '{') {
                return $this->postfix($this->shape());
            }
            if ($this->peek() === '<') {
                $args = $this->generic();
                if (count($args) === 2) {
                    return ['k' => 'map', 'key' => $args[0], 'val' => $args[1]];
                }
                return $this->postfix(['k' => 'list', 'of' => $args[0] ?? ['k' => 'scalar', 'n' => 'any']]);
            }
            // bare array/list -> list of any
            return $this->postfix(['k' => 'list', 'of' => ['k' => 'scalar', 'n' => 'any']]);
        }

        return $this->postfix($this->scalar($lower));
    }

    /** Apply trailing `[]` (one or more) to a node. */
    private function postfix(array $node): array
    {
        while (true) {
            $this->ws();
            if ($this->peek() === '[' && ($this->s[$this->i + 1] ?? '') === ']') {
                $this->i += 2;
                $node = ['k' => 'list', 'of' => $node];
            } else {
                return $node;
            }
        }
    }

    /** Parse `<T>` or `<K, V>`. */
    private function generic(): array
    {
        $this->expect('<');
        $args = [$this->union()];
        $this->ws();
        while ($this->peek() === ',') {
            $this->i++;
            $args[] = $this->union();
            $this->ws();
        }
        $this->expect('>');
        return $args;
    }

    /** Parse `array{name: T, opt?: U, ...}`. */
    private function shape(): array
    {
        $this->expect('{');
        $fields = [];
        $this->ws();
        while ($this->peek() !== '}' && $this->peek() !== '') {
            $key = $this->key();
            $this->ws();
            $opt = false;
            if ($this->peek() === '?') {
                $opt = true;
                $this->i++;
                $this->ws();
            }
            $this->expect(':');
            $type = $this->union();
            $fields[] = ['name' => $key, 'opt' => $opt, 'type' => $type];
            $this->ws();
            if ($this->peek() === ',') {
                $this->i++;
                $this->ws();
            }
        }
        $this->expect('}');
        return ['k' => 'object', 'fields' => $fields];
    }

    private function key(): string
    {
        $this->ws();
        $c = $this->peek();
        if ($c === '"' || $c === "'") {
            $this->i++;
            $start = $this->i;
            while ($this->peek() !== $c && $this->peek() !== '') {
                $this->i++;
            }
            $k = substr($this->s, $start, $this->i - $start);
            $this->i++; // closing quote
            return $k;
        }
        return $this->ident();
    }

    private function scalar(string $name): array
    {
        $n = match ($name) {
            'int', 'integer', 'positive-int', 'negative-int', 'non-negative-int' => 'int',
            'float', 'double' => 'float',
            'string', 'non-empty-string', 'class-string', 'numeric-string', 'lowercase-string' => 'string',
            'bool', 'boolean', 'true', 'false' => 'bool',
            'null' => 'null',
            'void', 'never' => 'void',
            'mixed', '' => 'any',
            'object', 'stdclass' => 'object',
            'callable', 'closure' => 'callable',
            default => ctype_upper($name[0] ?? 'a') || str_contains($name, '\\') ? 'object' : 'any',
        };
        return ['k' => 'scalar', 'n' => $n];
    }

    // --- cursor helpers ------------------------------------------------------
    private function ws(): void
    {
        while (in_array($this->peek(), [' ', "\t", "\n", "\r"], true)) {
            $this->i++;
        }
    }
    private function peek(): string
    {
        $this->skipInlineWs();
        return $this->s[$this->i] ?? '';
    }
    private function skipInlineWs(): void
    {
        while (($this->s[$this->i] ?? '') === ' ' || ($this->s[$this->i] ?? '') === "\t") {
            $this->i++;
        }
    }
    private function ident(): string
    {
        $this->ws();
        $start = $this->i;
        while (preg_match('/[A-Za-z0-9_\\\\-]/', $this->s[$this->i] ?? '')) {
            $this->i++;
        }
        return substr($this->s, $start, $this->i - $start);
    }
    private function expect(string $c): void
    {
        if ($this->peek() !== $c) {
            throw new \RuntimeException("expected '$c'");
        }
        $this->i++;
    }
}
