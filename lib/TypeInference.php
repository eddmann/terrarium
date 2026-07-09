<?php

declare(strict_types=1);

namespace Terrarium;

use Closure;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

require_once __DIR__ . '/PhpDocType.php';

/**
 * Reusable type inference for a Terrarium SDK. A guest wrapper `use`s this trait,
 * calls `inferTypes($name, $fn)` from its own `register()`, and gets `dts()`
 * (TypeScript) and `pyi()` (Python stub) for free — types inferred from PHP
 * Reflection, enriched by PHPDoc (incl. full object shapes via PhpDocType).
 *
 * This is "the fold": instead of a separate Sdk wrapper, Js/Py themselves become
 * typed SDKs.
 */
trait TypeInference
{
    /** @var array<string, array{params: list<array{name:string,node:array,opt:bool}>, return: array}> */
    private array $caps = [];

    /** Reflect a registered callable and record its inferred type model. */
    protected function inferTypes(string $name, callable $fn): void
    {
        $r = new ReflectionFunction(Closure::fromCallable($fn));
        $doc = $this->parseDoc($r->getDocComment() ?: '');

        $params = [];
        foreach ($r->getParameters() as $p) {
            $node = isset($doc['params'][$p->getName()])
                ? PhpDocType::parse($doc['params'][$p->getName()])
                : $this->reflNode($p->getType());
            $params[] = ['name' => $p->getName(), 'node' => $node, 'opt' => $p->isOptional()];
        }
        $return = $doc['return'] !== null
            ? PhpDocType::parse($doc['return'])
            : $this->reflNode($r->getReturnType());

        $this->caps[$name] = ['params' => $params, 'return' => $return, 'doc' => $doc['summary']];
    }

    private function reflNode(?ReflectionType $t): array
    {
        if ($t === null) {
            return ['k' => 'scalar', 'n' => 'any'];
        }
        if ($t instanceof ReflectionUnionType) {
            $of = array_map(fn ($m) => $this->reflNode($m), $t->getTypes());
            $nonNull = array_values(array_filter($of, fn ($n) => !($n['k'] === 'scalar' && $n['n'] === 'null')));
            if (count($nonNull) === 1 && count($of) === 2) {
                return ['k' => 'nullable', 'of' => $nonNull[0]];
            }
            return ['k' => 'union', 'of' => $of];
        }
        $name = $t instanceof ReflectionNamedType ? $t->getName() : 'mixed';
        $node = PhpDocType::parse($name);
        if ($t->allowsNull() && strtolower($name) !== 'mixed' && strtolower($name) !== 'null') {
            return ['k' => 'nullable', 'of' => $node];
        }
        return $node;
    }

    /** @return array{params: array<string,string>, return: ?string, summary: ?string} */
    private function parseDoc(string $doc): array
    {
        $params = [];
        // Type can contain spaces (object shapes); capture up to the $var.
        if (preg_match_all('/@param\s+(.+?)\s+\$(\w+)/s', $doc, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $params[$mm[2]] = $this->clean($mm[1]);
            }
        }
        $return = null;
        if (preg_match('/@return\s+(.+?)(?:\n|\*\/|$)/s', $doc, $m)) {
            $return = $this->clean($m[1]);
        }
        return ['params' => $params, 'return' => $return, 'summary' => $this->parseSummary($doc)];
    }

    /**
     * The human description leading a docblock — the prose before the first
     * `@tag`. Surfaced in the generated type defs as a JSDoc comment / `#` line,
     * so guest authors see what each capability does, not just its signature.
     */
    private function parseSummary(string $doc): ?string
    {
        // Strip the /** */ frame and the leading `*` on each line.
        $text = preg_replace('#^\s*/\*+|\*+/\s*$#', '', $doc) ?? $doc;
        $text = preg_replace('/^\s*\*\s?/m', '', $text) ?? $text;
        // Keep only the prose before the first @tag.
        $text = preg_split('/(?:^|\s)@\w+/', $text, 2)[0] ?? '';
        // Collapse to a single line and drop anything that could break a comment.
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $text = str_replace('*/', '', $text);
        return $text === '' ? null : $text;
    }

    /** Strip docblock `*` continuation noise from a (possibly multi-line) type. */
    private function clean(string $t): string
    {
        $t = preg_replace('/\s*\n\s*\*\s*/', ' ', $t) ?? $t;
        return trim($t);
    }

    // --- TypeScript emitter --------------------------------------------------

    /**
     * A `.d.ts` for the registered SDK. There is no synthetic root: each
     * top-level name is declared directly (`declare const math: {...}`,
     * `declare const ping: (...) => ...`), matching how the guest reaches it
     * (`math.add(...)`, `ping()`).
     */
    public function dts(): string
    {
        $out = '';
        foreach ($this->tree() as $key => $child) {
            if (isset($child['__leaf'])) {
                $cap = $child['__leaf'];
                if (!empty($cap['doc'])) {
                    $out .= "/** {$cap['doc']} */\n";
                }
                $out .= "declare const {$key}: " . $this->tsSig($cap) . ";\n";
            } else {
                $out .= "declare const {$key}: " . $this->tsNode($child, 0) . ";\n";
            }
        }
        return $out;
    }

    private function tsNode(array $node, int $indent): string
    {
        $pad = str_repeat('  ', $indent + 1);
        $out = "{\n";
        foreach ($node as $key => $child) {
            if (isset($child['__leaf'])) {
                $cap = $child['__leaf'];
                if (!empty($cap['doc'])) {
                    $out .= "{$pad}/** {$cap['doc']} */\n";
                }
                $out .= "{$pad}readonly {$key}: " . $this->tsSig($cap) . ";\n";
            } else {
                $out .= "{$pad}readonly {$key}: " . $this->tsNode($child, $indent + 1) . ";\n";
            }
        }
        return $out . str_repeat('  ', $indent) . '}';
    }

    private function tsSig(array $cap): string
    {
        $ps = array_map(
            fn ($p) => $p['name'] . ($p['opt'] ? '?' : '') . ': ' . $this->ts($p['node']),
            $cap['params']
        );
        return '(' . implode(', ', $ps) . ') => ' . $this->ts($cap['return']);
    }

    private function ts(array $n): string
    {
        return match ($n['k']) {
            'scalar' => match ($n['n']) {
                'int', 'float' => 'number',
                'string' => 'string',
                'bool' => 'boolean',
                'null' => 'null',
                'void' => 'void',
                'object' => 'Record<string, any>',
                'callable' => '(...args: any[]) => any',
                default => 'any',
            },
            'list' => $this->ts($n['of']) . '[]',
            'map' => 'Record<' . $this->ts($n['key']) . ', ' . $this->ts($n['val']) . '>',
            'object' => $this->tsObject($n['fields']),
            'union' => implode(' | ', array_map(fn ($x) => $this->ts($x), $n['of'])),
            'nullable' => $this->ts($n['of']) . ' | null',
            default => 'any',
        };
    }

    private function tsObject(array $fields): string
    {
        if ($fields === []) {
            return '{}';
        }
        $parts = array_map(
            fn ($f) => $f['name'] . ($f['opt'] ? '?' : '') . ': ' . $this->ts($f['type']),
            $fields
        );
        return '{ ' . implode('; ', $parts) . ' }';
    }

    // --- Python emitter (hoists object shapes into TypedDicts) ---------------

    /**
     * A `.pyi` for the registered SDK. There is no synthetic root: each dotted
     * namespace becomes a top-level `class`, and each flat capability a top-level
     * `def` — matching how the guest reaches them (`math.add(...)`, `ping()`).
     */
    public function pyi(): string
    {
        $dicts = [];
        $blocks = [];
        foreach ($this->tree() as $key => $child) {
            $blocks[] = isset($child['__leaf'])
                ? $this->pyDef($key, $child['__leaf'], 0, false, $dicts)
                : rtrim($this->pyNode($child, $key, 0, $dicts), "\n");
        }
        $header = "from typing import Any, Callable, Optional, NotRequired, TypedDict\n\n";
        $bodies = array_map(fn ($d) => $d['body'], $dicts);
        $td = $bodies === [] ? '' : implode("\n\n", $bodies) . "\n\n";
        $body = implode("\n\n", $blocks);
        return $header . $td . $body . ($body !== '' ? "\n" : '');
    }

    private function pyNode(array $node, string $name, int $indent, array &$dicts): string
    {
        $pad = str_repeat('    ', $indent);
        $inner = str_repeat('    ', $indent + 1);
        $body = '';
        foreach ($node as $key => $child) {
            $body .= isset($child['__leaf'])
                ? $this->pyDef($key, $child['__leaf'], $indent + 1, true, $dicts) . "\n"
                : $this->pyNode($child, $key, $indent + 1, $dicts);
        }
        return "{$pad}class {$name}:\n" . ($body !== '' ? $body : "{$inner}pass\n");
    }

    /**
     * Emit one capability as a Python `def` stub at `$indent`. `$static` decorates
     * it as a `@staticmethod` (a namespaced capability, inside a class); a flat,
     * un-namespaced capability is a plain module-level function. No trailing "\n".
     */
    private function pyDef(string $key, array $cap, int $indent, bool $static, array &$dicts): string
    {
        $pad = str_repeat('    ', $indent);
        $hint = $this->camel($key);
        $ps = array_map(
            fn ($p) => $p['name'] . ': ' . $this->py($p['node'], $dicts, $hint . $this->camel($p['name'])),
            $cap['params']
        );
        $ret = $this->py($cap['return'], $dicts, $hint . 'Result');
        $out = '';
        if (!empty($cap['doc'])) {
            $out .= "{$pad}# {$cap['doc']}\n";
        }
        if ($static) {
            $out .= "{$pad}@staticmethod\n";
        }
        return $out . "{$pad}def {$key}(" . implode(', ', $ps) . ") -> {$ret}: ...";
    }

    private function py(array $n, array &$dicts, string $hint): string
    {
        return match ($n['k']) {
            'scalar' => match ($n['n']) {
                'int' => 'int',
                'float' => 'float',
                'string' => 'str',
                'bool' => 'bool',
                'null', 'void' => 'None',
                'object' => 'dict[str, Any]',
                'callable' => 'Callable',
                default => 'Any',
            },
            'list' => 'list[' . $this->py($n['of'], $dicts, $hint . 'Item') . ']',
            'map' => 'dict[' . $this->py($n['key'], $dicts, $hint . 'Key') . ', ' . $this->py($n['val'], $dicts, $hint . 'Value') . ']',
            'object' => $this->pyTypedDict($n['fields'], $dicts, $hint),
            'union' => implode(' | ', array_map(fn ($x) => $this->py($x, $dicts, $hint), $n['of'])),
            'nullable' => 'Optional[' . $this->py($n['of'], $dicts, $hint) . ']',
            default => 'Any',
        };
    }

    private function pyTypedDict(array $fields, array &$dicts, string $hint): string
    {
        $key = $this->fieldsKey($fields);
        $name = $hint !== '' ? $hint : 'Obj';
        // Reuse if an identical shape already has this name; rename on a clash.
        $base = $name;
        $i = 2;
        while (isset($dicts[$name]) && $dicts[$name]['key'] !== $key) {
            $name = $base . $i++;
        }
        // Reserve the name before recursing (nested fields may reference it).
        $dicts[$name] = ['key' => $key, 'body' => ''];
        $lines = ["class {$name}(TypedDict):"];
        if ($fields === []) {
            $lines[] = '    pass';
        }
        foreach ($fields as $f) {
            $t = $this->py($f['type'], $dicts, $name . $this->camel($f['name']));
            if ($f['opt']) {
                $t = "NotRequired[{$t}]";
            }
            $lines[] = "    {$f['name']}: {$t}";
        }
        $dicts[$name] = ['key' => $key, 'body' => implode("\n", $lines)];
        return $name;
    }

    private function fieldsKey(array $fields): string
    {
        return implode(',', array_map(fn ($f) => $f['name'] . ($f['opt'] ? '?' : ''), $fields));
    }

    // --- PHP stub emitter (the SDK as the PHP guest sees it) ------------------

    /**
     * A `.php` stub describing the guest-visible SDK: each namespace is a final
     * class of typed methods (sub-namespaces as typed properties), each flat
     * capability a `\Closure`-typed variable -- matching how guest PHP reaches
     * them (`$math->add(2, 3)`, `$api->v1->hello(...)`, `$ping()`). Rich shapes
     * PHP cannot express natively (`array{...}`, `int[]`) ride in docblocks.
     */
    public function php(): string
    {
        $classes = [];
        $vars = [];
        foreach ($this->tree() as $key => $child) {
            if (isset($child['__leaf'])) {
                $cap = $child['__leaf'];
                $doc = !empty($cap['doc']) ? "/** {$cap['doc']} */\n" : '';
                $vars[] = $doc . '/** @var \Closure(' . $this->phpClosureParams($cap)
                    . '): ' . $this->phpDoc($cap['return']) . " \$$key */\n\$$key = null;";
            } else {
                $this->phpClass($child, [$key], $classes);
                $vars[] = '/** @var ' . $this->phpClassName([$key]) . " \$$key */\n\$$key = null;";
            }
        }
        return "<?php\n// Generated by Terrarium -- the SDK visible to guest PHP. Do not edit.\n\n"
            . ($classes === [] ? '' : implode("\n\n", $classes) . "\n\n")
            . implode("\n\n", $vars) . "\n";
    }

    /** Emit the class for one namespace node (children first, so refs resolve on read). */
    private function phpClass(array $node, array $path, array &$classes): void
    {
        $body = '';
        foreach ($node as $key => $child) {
            if (isset($child['__leaf'])) {
                $cap = $child['__leaf'];
                $lines = [];
                if (!empty($cap['doc'])) {
                    $lines[] = $cap['doc'];
                }
                foreach ($cap['params'] as $p) {
                    $lines[] = '@param ' . $this->phpDoc($p['node']) . ' $' . $p['name'];
                }
                $lines[] = '@return ' . $this->phpDoc($cap['return']);
                $body .= "    /**\n" . implode('', array_map(fn ($l) => "     * $l\n", $lines)) . "     */\n";
                $ps = array_map(
                    fn ($p) => $this->phpNative($p['node']) . ' $' . $p['name'] . ($p['opt'] ? ' = null' : ''),
                    $cap['params']
                );
                $ret = $this->phpNative($cap['return']);
                $body .= "    public function {$key}(" . implode(', ', $ps) . ")" . ($ret !== '' ? ": $ret" : '') . " {}\n";
            } else {
                $sub = [...$path, $key];
                $this->phpClass($child, $sub, $classes);
                $body .= '    public ' . $this->phpClassName($sub) . " \$$key;\n";
            }
        }
        $classes[] = 'final class ' . $this->phpClassName($path) . "\n{\n" . $body . '}';
    }

    private function phpClassName(array $path): string
    {
        return 'Terrarium_' . implode('_', array_map(fn ($p) => $this->camel($p), $path));
    }

    private function phpClosureParams(array $cap): string
    {
        return implode(', ', array_map(
            fn ($p) => $this->phpDoc($p['node']) . ($p['opt'] ? '=' : ''),
            $cap['params']
        ));
    }

    /** The native PHP type hint for a node ('' when PHP cannot express it). */
    private function phpNative(array $n): string
    {
        return match ($n['k']) {
            'scalar' => match ($n['n']) {
                'int', 'float', 'string', 'bool', 'void', 'object' => $n['n'],
                'null' => 'mixed',
                'callable' => 'callable',
                default => 'mixed',
            },
            'list', 'map', 'object' => 'array',
            'union' => implode('|', array_unique(array_map(
                fn ($x) => $this->phpNative($x) ?: 'mixed',
                $n['of']
            ))),
            'nullable' => ($inner = $this->phpNative($n['of'])) !== '' && $inner !== 'mixed' ? "?$inner" : 'mixed',
            default => 'mixed',
        };
    }

    /** The rich PHPDoc type for a node (shapes, element types). */
    private function phpDoc(array $n): string
    {
        return match ($n['k']) {
            'scalar' => match ($n['n']) {
                'int', 'float', 'string', 'bool', 'void', 'null', 'object', 'callable' => $n['n'],
                default => 'mixed',
            },
            'list' => $this->phpDoc($n['of']) . '[]',
            'map' => 'array<' . $this->phpDoc($n['key']) . ', ' . $this->phpDoc($n['val']) . '>',
            'object' => 'array{' . implode(', ', array_map(
                fn ($f) => $f['name'] . ($f['opt'] ? '?' : '') . ': ' . $this->phpDoc($f['type']),
                $n['fields']
            )) . '}',
            'union' => implode('|', array_map(fn ($x) => $this->phpDoc($x), $n['of'])),
            'nullable' => $this->phpDoc($n['of']) . '|null',
            default => 'mixed',
        };
    }

    // --- shared ---------------------------------------------------------------

    private function tree(): array
    {
        $tree = [];
        foreach ($this->caps as $name => $cap) {
            $ref = &$tree;
            $parts = explode('.', $name);
            $last = array_pop($parts);
            foreach ($parts as $p) {
                $ref[$p] ??= [];
                $ref = &$ref[$p];
            }
            $ref[$last] = ['__leaf' => $cap];
            unset($ref);
        }
        return $tree;
    }

    private function camel(string $s): string
    {
        $parts = preg_split('/[.\-_]/', $s) ?: [$s];
        return implode('', array_map('ucfirst', $parts));
    }
}
