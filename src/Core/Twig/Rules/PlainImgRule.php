<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when a plain `<img>` HTML tag is emitted in a template.
 *
 * Project convention: every image must go through the `component_picture`
 * macro (which calls `|resizer` to derive responsive sources). Token-level
 * port of the Python regex-based `no-plain-img` lint.
 *
 * twig-cs-fixer's tokenizer splits text on whitespace, so `<img` always
 * arrives as a standalone TEXT_TYPE token. The rule fires when:
 *  - the value matches `<img\s` directly (rare in-token whitespace), OR
 *  - the value ends with `<img` AND the next token is whitespace, EOL, or
 *    more raw text — i.e. the start of HTML attributes.
 *
 * Exempt: when `<img` is immediately followed by `{{` or `{%`, which is
 * the sanctioned `<img{{ image_attributes }}>` emission inside
 * `component/picture/picture.twig`. Comments and Twig strings are
 * naturally exempt because they tokenize as different types.
 */
final class PlainImgRule extends AbstractRule
{
	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (Token::TEXT_TYPE !== $token->getType()) {
			return;
		}

		$value = $token->getValue();

		if (1 === preg_match('/<img\s/i', $value)) {
			$this->fire($token);

			return;
		}

		if (1 !== preg_match('/<img$/i', $value)) {
			return;
		}

		// Token ends with `<img` — peek at the next token. A Twig delimiter
		// means the attributes are emitted by Twig (component_picture macro
		// pattern); anything else (whitespace, more text) means raw HTML.
		if (!$tokens->has($tokenIndex + 1)) {
			return;
		}

		$nextType = $tokens->get($tokenIndex + 1)->getType();
		if (Token::VAR_START_TYPE === $nextType || Token::BLOCK_START_TYPE === $nextType) {
			return;
		}

		$this->fire($token);
	}

	private function fire(Token $token): void
	{
		$this->addWarning(
			'Use component_picture with resizer filter instead of plain <img> tag',
			$token,
			'PlainImg',
		);
	}
}
