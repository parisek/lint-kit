<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when an element carrying the `stretched-link` utility class has no
 * ancestor with a `relative` class.
 *
 * Project convention (tailwindcss.md "Stretched Link"): the `.stretched-link`
 * utility applies `after:absolute after:inset-0 after:z-10` to a link so its
 * `::after` pseudo-element overlays the nearest *positioned* ancestor — that
 * ancestor becomes the click target. Without an ancestor of `position:
 * relative`, the overlay escapes upward to whatever positioned element it
 * finds (the page, a sticky header, a modal), silently breaking the click
 * affordance.
 *
 * Detection (hybrid):
 *  - **Token scan** (TEXT_TYPE only): triggers on any raw-HTML chunk that
 *    contains the substring `stretched-link`. STRING_TYPE (Twig string
 *    literals like `class: ['stretched-link']`) is intentionally NOT scanned
 *    as a *trigger* — the element using that class hash may open many lines
 *    later, and pinning the trigger to the literal's line rarely matches the
 *    actual ancestor chain. So the rule only fires on a literal
 *    `class="…stretched-link…"` in raw HTML; this keeps the trigger surface
 *    narrow and the change below can only ever *remove* warnings.
 *  - **Source scan**: the rule reads the template file and walks all HTML
 *    tags from start of file up to (and including) the trigger line, building
 *    an open-tags stack. The match for the `stretched-link` element
 *    interrupts the walk; ancestors are everything currently on the stack
 *    *before* that element is pushed. Self is excluded — the project rule
 *    explicitly says the *parent* must be relative.
 *  - **create_attribute resolution**: an element's class is read from a
 *    literal `class="…"` AND from any `{{ var }}` hash reference whose `var`
 *    was assigned via `{% set var = create_attribute({ class: … }) %}`. This
 *    is what lets the walk see `relative` on `<li{{ li_attributes }}>` — the
 *    mandated long-class-list pattern (create-attribute.md). Only unconditional
 *    static string literals are credited; conditional elements
 *    (`cond ? 'relative' : ''`) are skipped, and fully-dynamic classes stay
 *    unresolved. Resolution happens at the *usage* site (where the ancestor
 *    stack is correct), sidestepping the literal-line problem.
 *  - The match for `relative` allows Tailwind variant prefixes (`lg:relative`,
 *    `dark:relative`) — same convention as `ProseOnRichTextRule`.
 *
 * Skip conditions:
 *  - Templates inside `styleguide` (intentional out-of-context demos).
 *  - Templates that can't be read from disk (compiled-only mode).
 *  - Trigger token says "stretched-link" but the HTML walker can't locate
 *    a literal `class="…stretched-link…"` on the same line (e.g. the class
 *    is built across `{% if %}` branches). Treated as conservative pass —
 *    we err on the side of fewer warnings.
 *
 * Per-line dedupe: a single line with multiple matches fires once.
 */
final class StretchedLinkRelativeRule extends AbstractRule
{
	private const VOID_ELEMENTS = [
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
		'input', 'link', 'meta', 'source', 'track', 'wbr',
	];

	private ?string $cachedFilename = null;

	/** @var list<string> */
	private array $sourceLines = [];

	/** @var array<int, true> */
	private array $reportedLines = [];

	/**
	 * Map of `{% set <var> = create_attribute({ class: … }) %}` → static class
	 * tokens (space-joined). Built once per file in {@see loadSource()}; consumed
	 * by {@see elementHasRelativeAncestor()} to resolve `{{ var }}` hash refs.
	 *
	 * @var array<string, string>
	 */
	private array $attributeVarClasses = [];

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (Token::TEXT_TYPE !== $token->getType()) {
			return;
		}

		$value = $token->getValue();
		if (false === strpos($value, 'stretched-link')) {
			return;
		}

		$this->loadSource($token->getFilename());
		if ([] === $this->sourceLines) {
			return;
		}

		// A single TEXT_TYPE token can span many lines and contain multiple
		// `stretched-link` occurrences; locate each one's actual line by counting
		// newlines from the token's start to the substring offset, then check
		// each independently.
		$tokenStartLine = $token->getLine();
		$searchFrom = 0;
		while (false !== ($pos = strpos($value, 'stretched-link', $searchFrom))) {
			$searchFrom = $pos + 1;
			$line = $tokenStartLine + substr_count(substr($value, 0, $pos), "\n");

			if (isset($this->reportedLines[$line])) {
				continue;
			}
			if ($this->elementHasRelativeAncestor($line)) {
				continue;
			}

			$this->reportedLines[$line] = true;
			$this->addWarning(
				"Element with 'stretched-link' (line {$line}) must have an ancestor with 'relative' class — without it the ::after overlay escapes to the nearest positioned ancestor",
				$token,
				'StretchedLinkRelative',
			);
		}
	}

	private function loadSource(string $filename): void
	{
		if ($filename === $this->cachedFilename) {
			return;
		}

		$this->cachedFilename = $filename;
		$this->sourceLines = [];
		$this->reportedLines = [];
		$this->attributeVarClasses = [];

		if (str_contains(basename($filename), 'styleguide')) {
			return;
		}
		if (!is_readable($filename)) {
			return;
		}

		$contents = file_get_contents($filename);
		if (false === $contents) {
			return;
		}

		$this->sourceLines = explode("\n", $contents);

		// Build the create_attribute var→class map from a comment-stripped copy
		// so a `{% set … create_attribute … %}` written inside a `{# … #}` doc
		// comment doesn't leak into the resolution map.
		$stripped = preg_replace_callback(
			'/\{#.*?#\}/s',
			static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? $m[0],
			$contents,
		) ?? $contents;
		$this->attributeVarClasses = $this->extractAttributeVarClasses($stripped);
	}

	/**
	 * Walk every HTML tag from start of file up to and including `$line`,
	 * maintaining a stack of open elements. When the walker reaches the
	 * `<...stretched-link...>` element whose opening tag starts on `$line`,
	 * it returns whether any element currently on the stack (before that
	 * element is pushed) has a `relative` token.
	 *
	 * Other `stretched-link` elements encountered earlier in the walk
	 * (different cards in the same file) are pushed/popped like normal
	 * elements — they belong to other rule invocations, not this one.
	 *
	 * Returns true (skip warning) when no stretched-link element is found
	 * at `$line` — the trigger token may have been inside a Twig conditional
	 * we don't statically resolve. Conservative: fewer warnings.
	 */
	private function elementHasRelativeAncestor(int $line): bool
	{
		// Replace `{# … #}` content with same-length whitespace to neutralize
		// any HTML inside comments WITHOUT shifting line numbers.
		$source = preg_replace_callback(
			'/\{#.*?#\}/s',
			static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? $m[0],
			implode("\n", $this->sourceLines),
		) ?? implode("\n", $this->sourceLines);

		if (!preg_match_all(
			'#<(/?)([a-z][a-z0-9]*)\b([^>]*?)(/?)>#is',
			$source,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
		)) {
			return true;
		}

		/** @var list<array{tag: string, class: string}> $stack */
		$stack = [];

		foreach ($matches as $m) {
			$matchLine = 1 + substr_count(substr($source, 0, $m[0][1]), "\n");
			if ($matchLine > $line) {
				break;
			}

			$isClose = '/' === $m[1][0];
			$tag = strtolower($m[2][0]);
			$attrs = $m[3][0];
			$selfClosing = '/' === $m[4][0];

			if ($isClose) {
				for ($i = \count($stack) - 1; $i >= 0; --$i) {
					if ($stack[$i]['tag'] === $tag) {
						array_splice($stack, $i);
						break;
					}
				}
				continue;
			}

			$class = $this->resolveClass($attrs);
			$hasStretched = $this->classHasToken($class, 'stretched-link');

			if ($hasStretched && $matchLine === $line) {
				return $this->stackHasToken($stack, 'relative');
			}

			if ($selfClosing || \in_array($tag, self::VOID_ELEMENTS, true)) {
				continue;
			}

			$stack[] = ['tag' => $tag, 'class' => $class];
		}

		return true;
	}

	/**
	 * An element's effective class string = its literal `class="…"` PLUS the
	 * static classes of any `{{ var }}` hash it references whose `var` came from
	 * `{% set var = create_attribute({ class: … }) %}`. Lets the ancestor walk
	 * see `relative` / `stretched-link` on `<li{{ li_attributes }}>`.
	 */
	private function resolveClass(string $attrs): string
	{
		$class = $this->extractClassAttr($attrs);

		if ([] !== $this->attributeVarClasses
			&& preg_match_all('/\{\{\s*([a-zA-Z_]\w*)\b[^}]*\}\}/', $attrs, $vm)
		) {
			foreach ($vm[1] as $var) {
				if (isset($this->attributeVarClasses[$var])) {
					$class = '' === $class
						? $this->attributeVarClasses[$var]
						: $class . ' ' . $this->attributeVarClasses[$var];
				}
			}
		}

		return $class;
	}

	private function extractClassAttr(string $attrs): string
	{
		if (preg_match('/\bclass\s*=\s*"([^"]*)"/is', $attrs, $cm)
			|| preg_match("/\\bclass\\s*=\\s*'([^']*)'/is", $attrs, $cm)
		) {
			return $cm[1];
		}

		return '';
	}

	/**
	 * Map each `{% set <var> = create_attribute({ class: … }) %}` to the static
	 * class tokens in its `class:` slot (space-joined).
	 *
	 * Only UNCONDITIONAL static string literals are credited:
	 *  - A bare literal element (`'group relative'`) contributes its tokens.
	 *  - A conditional element (`cond ? 'relative' : ''`) is SKIPPED entirely —
	 *    the class is applied in only one branch, so crediting it would risk
	 *    silencing a genuine missing-`relative` warning in the other branch.
	 *  - Fully-dynamic entries (a bare variable) contribute nothing.
	 *
	 * The `class:` slot and array elements are located by a quote/bracket-aware
	 * scan, NOT a regex — so `]` / `)` inside a class literal (Tailwind arbitrary
	 * values like `grid-cols-[1fr_2fr]`, `w-[calc(100%-1rem)]`) never truncate
	 * the match. When the same var is assigned more than once the token sets are
	 * unioned — if any definition carries `relative`, the element gets it.
	 *
	 * @return array<string, string>
	 */
	private function extractAttributeVarClasses(string $source): array
	{
		$map = [];

		// Match only the safe prefix `{% set NAME = create_attribute(`; the args
		// are then read by a paren/quote-aware scan to the structural closing `)`,
		// so a `)` or `%}` inside a quoted value can't truncate the capture.
		//
		// Known limitation (accepted): this scans comment-stripped source but is
		// not Twig-string-aware, so the literal text `{% set x = create_attribute(`
		// appearing INSIDE a string value would also match. This is a pre-existing
		// property of the rule's text-based design and effectively never occurs in
		// real templates; the only airtight fix is full source-level string
		// tracking, which is itself unsafe here (an apostrophe in raw HTML text —
		// "it's" — is not a string quote), so it is deliberately not attempted.
		if (!preg_match_all(
			'/\{%-?\s*set\s+([a-zA-Z_]\w*)\s*=\s*create_attribute\s*\(/',
			$source,
			$starts,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
		)) {
			return $map;
		}

		foreach ($starts as $start) {
			$name = $start[1][0];
			$args = $this->readBalancedParen($source, $start[0][1] + \strlen($start[0][0]));
			if (null === $args) {
				continue;
			}

			$tokens = $this->classTokensFromArgs($args);
			if ([] === $tokens) {
				continue;
			}

			$joined = implode(' ', $tokens);
			$map[$name] = isset($map[$name]) ? $map[$name] . ' ' . $joined : $joined;
		}

		return $map;
	}

	/**
	 * Read the content between a `create_attribute(` opening paren (offset $i is
	 * the first char AFTER it) and its matching close paren, tracking paren depth
	 * and quote state (with `\` escapes). A `)` inside a string literal or a
	 * nested `(...)` doesn't close early, and a `%}` inside a value is irrelevant
	 * — the scan keys on the structural paren, not a text delimiter. Returns null
	 * when the parens are unbalanced (truncated source).
	 */
	private function readBalancedParen(string $source, int $i): ?string
	{
		$len = \strlen($source);
		$start = $i;
		$depth = 1;
		$quote = null;

		for (; $i < $len; ++$i) {
			$c = $source[$i];

			if (null !== $quote) {
				if ('\\' === $c) {
					++$i;
				} elseif ($c === $quote) {
					$quote = null;
				}
				continue;
			}

			if ("'" === $c || '"' === $c) {
				$quote = $c;
			} elseif ('(' === $c) {
				++$depth;
			} elseif (')' === $c && 0 === --$depth) {
				return substr($source, $start, $i - $start);
			}
		}

		return null;
	}

	/**
	 * Extract the static class tokens from a `create_attribute(...)` args string.
	 *
	 * @return list<string>
	 */
	private function classTokensFromArgs(string $args): array
	{
		$slot = $this->extractClassSlot($args);
		if (null === $slot) {
			return [];
		}

		$first = $slot[0];

		// String form: `class: 'a b c'` — one unconditional literal.
		if ("'" === $first || '"' === $first) {
			return $this->splitClassString(substr($slot, 1, -1));
		}

		// Array form: `class: [ … ]`.
		$tokens = [];
		foreach ($this->splitArrayElements(substr($slot, 1, -1)) as $element) {
			if ($element['conditional']) {
				continue;
			}
			if (preg_match_all('/([\'"])(.*?)\1/s', $element['text'], $lits)) {
				foreach ($lits[2] as $literal) {
					$tokens = array_merge($tokens, $this->splitClassString($literal));
				}
			}
		}

		return $tokens;
	}

	/**
	 * Return the value of the TOP-LEVEL `class:` key of a create_attribute args
	 * string — the `[ … ]` array (delimiters included) or the `'…'` string.
	 *
	 * Walks the args object tracking `{}` / `[]` / `()` depth and quote state, so
	 * the key is matched ONLY at the object's top level (brace depth 1). A nested
	 * `class:` inside another value — `{ 'data-x': { class: 'a' }, class: [...] }`
	 * — is ignored; the real top-level `class:` wins. Quote/bracket-aware so
	 * `]` / `)` inside a literal (Tailwind arbitrary values) never truncate, and
	 * `\'` / `\"` escapes inside literals don't flip quote state.
	 *
	 * Returns null when there is no top-level `class:` key.
	 */
	private function extractClassSlot(string $args): ?string
	{
		$len = \strlen($args);
		$brace = 0;
		$bracket = 0;
		$paren = 0;
		$quote = null;
		$atKey = false;

		for ($i = 0; $i < $len; ++$i) {
			$c = $args[$i];

			if (null !== $quote) {
				if ('\\' === $c) {
					++$i;
				} elseif ($c === $quote) {
					$quote = null;
				}
				continue;
			}

			if ("'" === $c || '"' === $c) {
				$quote = $c;
				$atKey = false;
				continue;
			}

			$topLevel = 1 === $brace && 0 === $bracket && 0 === $paren;

			if ('{' === $c) {
				++$brace;
				$atKey = 1 === $brace && 0 === $bracket && 0 === $paren;
				continue;
			}
			if ('}' === $c) {
				--$brace;
				$atKey = false;
				continue;
			}
			if ('[' === $c) {
				++$bracket;
				$atKey = false;
				continue;
			}
			if (']' === $c) {
				--$bracket;
				continue;
			}
			if ('(' === $c) {
				++$paren;
				$atKey = false;
				continue;
			}
			if (')' === $c) {
				--$paren;
				continue;
			}
			if (',' === $c) {
				$atKey = $topLevel;
				continue;
			}
			if (ctype_space($c)) {
				continue;
			}

			// First non-space char of a top-level key position.
			if ($atKey && $topLevel) {
				if (preg_match('/\Gclass\s*:\s*/', $args, $m, 0, $i)) {
					return $this->readSlotValue($args, $i + \strlen($m[0]));
				}
				$atKey = false;
				continue;
			}

			$atKey = false;
		}

		return null;
	}

	/**
	 * Read a `class:` value starting at offset $i — the `[ … ]` array or `'…'`
	 * string (delimiters included), quote/bracket-aware with `\` escapes honoured.
	 */
	private function readSlotValue(string $args, int $i): ?string
	{
		$len = \strlen($args);
		if ($i >= $len) {
			return null;
		}

		$ch = $args[$i];

		if ("'" === $ch || '"' === $ch) {
			for ($j = $i + 1; $j < $len; ++$j) {
				if ('\\' === $args[$j]) {
					++$j;
					continue;
				}
				if ($args[$j] === $ch) {
					return substr($args, $i, $j - $i + 1);
				}
			}

			return null;
		}

		if ('[' === $ch) {
			$depth = 0;
			$quote = null;
			for ($j = $i; $j < $len; ++$j) {
				$c = $args[$j];
				if (null !== $quote) {
					if ('\\' === $c) {
						++$j;
					} elseif ($c === $quote) {
						$quote = null;
					}
					continue;
				}
				if ("'" === $c || '"' === $c) {
					$quote = $c;
					continue;
				}
				if ('[' === $c) {
					++$depth;
				} elseif (']' === $c && 0 === --$depth) {
					return substr($args, $i, $j - $i + 1);
				}
			}
		}

		return null;
	}

	/**
	 * Split an array body (the text between `[` and `]`) into top-level elements,
	 * flagging any element that contains a top-level `?` as conditional. The scan
	 * tracks quote and bracket/paren depth, so commas and `?` inside a string
	 * literal or a nested `(…)` / `[…]` don't split or mis-flag.
	 *
	 * @return list<array{text: string, conditional: bool}>
	 */
	private function splitArrayElements(string $body): array
	{
		$elements = [];
		$buf = '';
		$conditional = false;
		$depth = 0;
		$quote = null;
		$len = \strlen($body);

		for ($i = 0; $i < $len; ++$i) {
			$c = $body[$i];

			if (null !== $quote) {
				$buf .= $c;
				if ('\\' === $c && $i + 1 < $len) {
					$buf .= $body[++$i];
				} elseif ($c === $quote) {
					$quote = null;
				}
				continue;
			}

			if ("'" === $c || '"' === $c) {
				$quote = $c;
				$buf .= $c;
				continue;
			}

			if ('[' === $c || '(' === $c || '{' === $c) {
				++$depth;
			} elseif (']' === $c || ')' === $c || '}' === $c) {
				--$depth;
			} elseif (0 === $depth && '?' === $c) {
				$conditional = true;
			} elseif (0 === $depth && ',' === $c) {
				if ('' !== trim($buf)) {
					$elements[] = ['text' => $buf, 'conditional' => $conditional];
				}
				$buf = '';
				$conditional = false;
				continue;
			}

			$buf .= $c;
		}

		if ('' !== trim($buf)) {
			$elements[] = ['text' => $buf, 'conditional' => $conditional];
		}

		return $elements;
	}

	/**
	 * Split a class-literal string on whitespace into individual class tokens.
	 *
	 * @return list<string>
	 */
	private function splitClassString(string $value): array
	{
		$tokens = [];
		foreach (preg_split('/\s+/', trim($value)) ?: [] as $token) {
			if ('' !== $token) {
				$tokens[] = $token;
			}
		}

		return $tokens;
	}

	/**
	 * Returns true when any whitespace-separated token in `$classAttr`
	 * matches `$needle` exactly OR ends with `:$needle` (Tailwind variant
	 * prefix). Examples for `relative`: `relative`, `lg:relative`,
	 * `dark:relative`. Skips: `relative-1`, `prose-relative`.
	 */
	private function classHasToken(string $classAttr, string $needle): bool
	{
		if ('' === $classAttr) {
			return false;
		}

		$pattern = '/(?:^|:)' . preg_quote($needle, '/') . '$/';
		foreach (preg_split('/\s+/', trim($classAttr)) ?: [] as $token) {
			if (1 === preg_match($pattern, $token)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array{tag: string, class: string}> $stack
	 */
	private function stackHasToken(array $stack, string $needle): bool
	{
		foreach ($stack as $ancestor) {
			if ($this->classHasToken($ancestor['class'], $needle)) {
				return true;
			}
		}

		return false;
	}
}
