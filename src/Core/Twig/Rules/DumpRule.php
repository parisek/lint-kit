<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Flags any leftover `{% dump %}` tag or `dump()` function call.
 *
 * `dump` is a debugging surface from `Twig\Extension\DebugExtension`. When
 * Twig's `debug` mode is enabled it pretty-prints variable contents — but
 * shipping it to production leaks internal state into the rendered HTML
 * (or, worse, dumps the entire context when called with no arguments).
 *
 * The two surfaces:
 *  - `{% dump %}` / `{% dump foo, bar %}` — statement form, tokenizes as
 *    BLOCK_NAME_TYPE with value `dump` between BLOCK_START / BLOCK_END.
 *  - `{{ dump(foo) }}` — function form, tokenizes as FUNCTION_NAME_TYPE.
 *
 * Both name token types are handled in a single token check, so whitespace
 * controls (`{%- dump -%}`), filter chaining, and inline use inside any
 * expression all match without extra logic.
 *
 * Defensive only — the codebase currently has zero violations. The rule
 * exists to prevent a debugging session from leaking into a production
 * commit. Warn-only because the right action is "delete the line", which
 * the developer should do consciously.
 *
 * Tokens that legitimately contain the word `dump` (variable name, hash
 * key, plain text, comment, string literal) use different token types
 * (NAME_TYPE, HASH_KEY_NAME_TYPE, TEXT_TYPE, COMMENT_*, STRING_TYPE) and
 * are silently ignored.
 */
final class DumpRule extends AbstractRule
{
	private const DUMP_NAME = 'dump';

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		$type = $token->getType();

		if (Token::BLOCK_NAME_TYPE !== $type && Token::FUNCTION_NAME_TYPE !== $type) {
			return;
		}

		if (self::DUMP_NAME !== $token->getValue()) {
			return;
		}

		$this->addWarning(
			'Remove `dump` — debugging artifact (leaks internal state to rendered output)',
			$token,
			'Dump',
		);
	}
}
