<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Report\Report;
use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when a bare translation call (`_x`/`__`/`_n`/`_nx`, no filter applied)
 * is printed as visible prose — the other half of the doctrine
 * `TranslationTypographyFilterRule` deliberately leaves uncovered.
 *
 * Convention: see `twig.md` § Typography-aware translations (tailwind-base, `.claude/rules/theme/twig.md`).
 * Visible prose printed as a raw text node should use the typography-aware
 * helper (`_xt`/`__t`/`_nt`/`_nxt`) instead of the plain translation call —
 * missing it is not just a typography gap: `_x()` is not `is_safe: html`
 * (unlike `_xt()`), so an entity like `&copy;` inside the source string gets
 * double-escaped by autoescape and prints literally instead of rendering.
 *
 * **Why token-based, not AST (`AbstractNodeRule`).** The predicate needs two
 * pieces of surrounding-HTML context an `AbstractNodeRule` cannot see at all:
 * whether the print sits in a text-node position vs. an attribute value, and
 * what the nearest enclosing element's tag name / `class` attribute are (for
 * the skip list below). `AbstractNodeRule::enterNode()` only receives the
 * parsed Twig AST — no raw source, no token stream, not even a column number
 * (`Node::getTemplateLine()` is the only position it exposes). None of that
 * carries HTML structure, because Twig's parser doesn't know about HTML at
 * all — `<span class="sr-only">{{ _x(…) }}</span>` parses to the same shape
 * of "print a function call" node regardless of what surrounds it. So this
 * rule is an `AbstractRule` (token stream), the same approach
 * `HardcodedI18nAttributeRule` uses to solve the analogous "is this an
 * attribute value" problem, extended with a small stateful single-pass HTML
 * scanner (open-tag depth + an element stack) because the attribute-context
 * check here has to hold across an entire file, not just within one token —
 * see § Detection below for why a token-local check isn't enough.
 *
 * **Detection:**
 *  1. Find a `FUNCTION_NAME_TYPE` token named `_x`/`__`/`_n`/`_nx` whose
 *     previous non-whitespace token is `VAR_START_TYPE` — i.e. the call is
 *     the *entire* print expression, not a sub-expression of something else
 *     (`{{ foo ~ _x(…) }}`, `{{ _x(…) ~ 'x' }}` are out of scope: the rule
 *     only judges bare, standalone prints).
 *  2. Track paren depth from the call's `(` to find its matching `)`, then
 *     check what follows: if the next non-whitespace token is `VAR_END_TYPE`,
 *     no filter was applied — candidate. If it's `|` (`OPERATOR_TYPE`), a
 *     filter is present (most commonly `|typography` —
 *     `TranslationTypographyFilterRule`'s territory) and this rule stays
 *     silent to avoid double-reporting the same call.
 *  3. In parallel, a single left-to-right pass over every `TEXT_TYPE` token
 *     in the file (the raw HTML/text runs between Twig constructs) maintains:
 *       - `insideOpenTag` — are we currently between an unclosed `<` and its
 *         `>` (i.e. would the next print land inside a tag's attribute list)?
 *       - `elementStack` — currently open elements, each with its lowercase
 *         tag name and whether its (statically visible) `class` attribute
 *         contains the substring `sr-only`.
 *     When a candidate call (step 1–2) is found, it fires only if
 *     `insideOpenTag` is false at that point (constraint 2 — text-node
 *     position) AND the top of `elementStack` is neither a skip-list tag
 *     (`title`/`option`/`script`/`style`) nor `sr-only`-classed (constraint 3).
 *
 * **Why the HTML scanner must be stateful across the whole file, not just
 * the single token immediately preceding the print.** A naive version that
 * only inspects the one `TEXT_TYPE` token right before the `{{` fails on a
 * realistic and common pattern in this codebase:
 *
 *   <div class="{{ wrapper_class }}" title="{{ _x('Hover hint', …) }}">
 *
 * The `TEXT_TYPE` token immediately before the `_x(...)` print here is just
 * ` title="` — it contains no literal `<` at all, so a token-local scan would
 * wrongly conclude "not inside a tag" and fire a false positive. The
 * `insideOpenTag` flag instead accumulates across the *entire* file in
 * source order, so it correctly remembers the tag opened several tokens
 * earlier (at the `<div`) and hasn't closed yet. This case is covered by
 * fixture case OK 8.
 *
 * **Value passed to a macro / `component_*()` call — no special-casing
 * needed.** The issue's third skip-list item (a translation handed to a
 * macro or `component_*()` call, e.g. `component_button({ title: _x(…) })`)
 * falls out of step 1 for free: in that shape the print's root expression is
 * the macro/`component_*` call, not the bare `_x(…)` — so the
 * "previous token before the call is `VAR_START_TYPE`" check in step 1
 * already excludes it structurally. See fixture OK 16/17.
 *
 * **Known limitations (accepted false negatives, by design — zero
 * false positives takes priority per `meta/linting.md` § 6):**
 *  - **Dynamically-built `class` attributes.** The `class` scan only reads
 *    the literal HTML text of the tag; a class assembled via Twig
 *    interpolation (`class="{{ classes|join(' ') }}"`) can't be inspected,
 *    so an element that is *actually* `sr-only` through a dynamic class is
 *    treated as unknown and — because element metadata defaults to
 *    "not sr-only" rather than "unknown, be conservative" — could in theory
 *    still fire. This mirrors the accepted-limitation style of the sibling
 *    rules; in practice `sr-only` is applied as a static utility class
 *    throughout this codebase (confirmed by the audit that motivated
 *    issue #380), so the gap is theoretical rather than observed.
 *  - **Malformed/unbalanced HTML** (a `<` inside a raw text run, e.g. a
 *    literal "1 < 2" outside any tag) can desync the open-tag tracker for
 *    the remainder of the file. Not seen in this codebase's templates
 *    (Twig escapes user content; raw `<`/`>` in static markup is rare and
 *    would already read oddly), but noted as the honest boundary of a
 *    regex-driven, non-parsing HTML scanner.
 *  - **`_x()` assigned to a variable and printed elsewhere**
 *    (`{% set label = _x(…) %}{{ label }}`) is out of scope by design, same
 *    as the issue's own scoping — a linter can't reliably trace variable
 *    flow across a template without becoming a much heavier analysis. Stays
 *    a code-review concern.
 *  - **Element-name/attribute matching is case-sensitive lowercase only**
 *    after `strtolower()` — fine for HTML, which this project writes
 *    lowercase throughout.
 */
final class TranslationMissingTypographyRule extends AbstractRule
{
	/**
	 * Plain translation function => its typography-aware twin. Mirrors
	 * `TranslationTypographyFilterRule::HELPER_MAP`.
	 */
	private const HELPER_MAP = [
		'_x'  => '_xt',
		'__'  => '__t',
		'_n'  => '_nt',
		'_nx' => '_nxt',
	];

	private const SKIP_TAGS = ['title', 'option', 'script', 'style'];

	/**
	 * HTML void elements never receive a matching closing tag and never
	 * wrap text content, so they're never pushed onto the element stack.
	 */
	private const VOID_ELEMENTS = [
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
		'link', 'meta', 'param', 'source', 'track', 'wbr',
	];

	/** @var bool Are we currently between an unclosed `<` and its `>`? */
	private bool $insideOpenTag = false;

	/** @var string Raw text accumulated since the currently-open tag's `<`. */
	private string $currentTagBuffer = '';

	/**
	 * @var list<array{name: string, srOnly: bool}> Stack of open elements,
	 *      innermost last.
	 */
	private array $elementStack = [];

	protected function init(?Report $report, array $ignoredViolations = []): void
	{
		parent::init($report, $ignoredViolations);

		// Reset per-file scanner state — the same rule instance is reused
		// across every file the linter processes (see Linter::run()).
		$this->insideOpenTag = false;
		$this->currentTagBuffer = '';
		$this->elementStack = [];
	}

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);

		if (Token::TEXT_TYPE === $token->getType()) {
			$this->scanText($token->getValue());

			return;
		}

		// The tokenizer splits raw markup into separate TEXT_TYPE tokens at
		// every run of whitespace (`<span class="…">` lexes as `<span`,
		// WHITESPACE_TYPE ' ', `class="…">` — see twig-cs-fixer.md § 6
		// "Tokenizer splits markup at whitespace"). scanText() must see that
		// whitespace too, or two adjacent TEXT_TYPE chunks glue together
		// without a separator (`spanclass="…"`, breaking the `\bclass\b`
		// attribute regex below).
		if ($this->isSkippable($token) && $this->insideOpenTag) {
			$this->currentTagBuffer .= (string) $token->getValue();

			return;
		}

		if (Token::FUNCTION_NAME_TYPE !== $token->getType()) {
			return;
		}

		$name = $token->getValue();
		if (!\is_string($name) || !isset(self::HELPER_MAP[$name])) {
			return;
		}

		if (!$this->isEntirePrintExpression($tokenIndex, $tokens)) {
			return;
		}

		if (!$this->hasNoTrailingFilter($tokenIndex, $tokens)) {
			return;
		}

		// Constraint 2 — must be a text-node position, not inside a tag.
		if ($this->insideOpenTag) {
			return;
		}

		// Constraint 3 — skip list.
		$parent = $this->elementStack[\count($this->elementStack) - 1] ?? null;
		if (null !== $parent) {
			if (\in_array($parent['name'], self::SKIP_TAGS, true)) {
				return;
			}

			if ($parent['srOnly']) {
				return;
			}
		}

		$helper = self::HELPER_MAP[$name];
		$this->addWarning(
			\sprintf(
				'%s(…) is printed as visible prose without a typography-aware helper — use %s(…) instead (translates and applies typography in one call). See twig.md § Typography-aware translations.',
				$name,
				$helper,
			),
			$token,
			'TranslationMissingTypography',
		);
	}

	/**
	 * True when the function-name token at `$tokenIndex` is the whole print
	 * expression — i.e. the previous non-whitespace token is `VAR_START_TYPE`.
	 */
	private function isEntirePrintExpression(int $tokenIndex, Tokens $tokens): bool
	{
		$prev = $this->previousMeaningfulIndex($tokenIndex, $tokens);
		if (null === $prev) {
			return false;
		}

		return Token::VAR_START_TYPE === $tokens->get($prev)->getType();
	}

	/**
	 * True when, after the call's matching closing `)`, the next
	 * non-whitespace token is `VAR_END_TYPE` (no filter chained on).
	 */
	private function hasNoTrailingFilter(int $tokenIndex, Tokens $tokens): bool
	{
		$total = \count($tokens->toArray());

		// Walk forward to the call's opening `(`.
		$i = $tokenIndex + 1;
		while ($i < $total && $this->isSkippable($tokens->get($i))) {
			++$i;
		}

		if ($i >= $total || !$this->isPunctuation($tokens->get($i), '(')) {
			// Not actually a call (shouldn't happen for FUNCTION_NAME_TYPE,
			// but stay defensive rather than assume).
			return false;
		}

		$depth = 1;
		++$i;
		while ($i < $total && $depth > 0) {
			$t = $tokens->get($i);
			if ($this->isPunctuation($t, '(')) {
				++$depth;
			} elseif ($this->isPunctuation($t, ')')) {
				--$depth;
			}
			++$i;
		}

		if ($depth > 0) {
			// Unbalanced — bail out rather than guess.
			return false;
		}

		while ($i < $total && $this->isSkippable($tokens->get($i))) {
			++$i;
		}

		if ($i >= $total) {
			return false;
		}

		return Token::VAR_END_TYPE === $tokens->get($i)->getType();
	}

	private function previousMeaningfulIndex(int $tokenIndex, Tokens $tokens): ?int
	{
		$i = $tokenIndex - 1;
		while ($i >= 0 && $this->isSkippable($tokens->get($i))) {
			--$i;
		}

		return $i >= 0 ? $i : null;
	}

	private function isSkippable(Token $token): bool
	{
		return \array_key_exists($token->getType(), Token::WHITESPACE_TOKENS)
			|| \array_key_exists($token->getType(), Token::TAB_TOKENS)
			|| \array_key_exists($token->getType(), Token::EOL_TOKENS);
	}

	private function isPunctuation(Token $token, string $value): bool
	{
		return Token::PUNCTUATION_TYPE === $token->getType() && $value === $token->getValue();
	}

	/**
	 * Advances the single-pass HTML scanner (`insideOpenTag` + `elementStack`)
	 * over one `TEXT_TYPE` token's raw text. See the class docblock for why
	 * this has to be stateful across the whole file.
	 */
	private function scanText(string $text): void
	{
		$len = \strlen($text);
		$i = 0;

		while ($i < $len) {
			if (!$this->insideOpenTag) {
				$ltPos = strpos($text, '<', $i);
				if (false === $ltPos) {
					break;
				}

				$this->insideOpenTag = true;
				$this->currentTagBuffer = '';
				$i = $ltPos + 1;

				continue;
			}

			$gtPos = strpos($text, '>', $i);
			if (false === $gtPos) {
				$this->currentTagBuffer .= substr($text, $i);
				$i = $len;

				break;
			}

			$this->currentTagBuffer .= substr($text, $i, $gtPos - $i);
			$this->closeTag($this->currentTagBuffer);
			$this->insideOpenTag = false;
			$this->currentTagBuffer = '';
			$i = $gtPos + 1;
		}
	}

	/**
	 * Called once a `<…>` tag has been fully captured (raw text between the
	 * `<` and the matching `>`, exclusive of both). Updates `elementStack`.
	 */
	private function closeTag(string $raw): void
	{
		$trimmed = trim($raw);

		if ('' === $trimmed) {
			return;
		}

		// Comments (`<!-- … -->`) and doctype (`<!doctype …>`) — ignore.
		if (str_starts_with($trimmed, '!')) {
			return;
		}

		// Processing instructions / other non-element markup — ignore.
		if (str_starts_with($trimmed, '?')) {
			return;
		}

		if (str_starts_with($trimmed, '/')) {
			$closingName = strtolower($this->extractTagName(substr($trimmed, 1)));
			$this->popElement($closingName);

			return;
		}

		$name = strtolower($this->extractTagName($trimmed));
		if ('' === $name) {
			return;
		}

		$selfClosing = str_ends_with($trimmed, '/') || \in_array($name, self::VOID_ELEMENTS, true);
		if ($selfClosing) {
			return;
		}

		$srOnly = false;
		if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $trimmed, $matches)) {
			$srOnly = str_contains(strtolower($matches[2]), 'sr-only');
		}

		$this->elementStack[] = ['name' => $name, 'srOnly' => $srOnly];
	}

	private function extractTagName(string $text): string
	{
		if (1 === preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)/', $text, $matches)) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Pops the matching element off the stack, walking down from the top
	 * to tolerate minor imbalance (a closing tag whose immediate parent
	 * isn't the top of the stack). If no match is found, the stack is left
	 * untouched — better to keep stale (over-conservative) ancestor
	 * context than to corrupt the stack by popping the wrong element.
	 */
	private function popElement(string $name): void
	{
		for ($i = \count($this->elementStack) - 1; $i >= 0; --$i) {
			if ($this->elementStack[$i]['name'] === $name) {
				\array_splice($this->elementStack, $i);

				return;
			}
		}
	}
}
