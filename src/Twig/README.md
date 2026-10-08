# Twig

`Preset::config(array $options): Config` builds the `twig-cs-fixer` config of a project. It is the only public entry point of the Twig side.

It reads no file of the project. Every input is an option. The options and their defaults are in the docblock of `Preset`.

What the preset does, in order:

1. Checks the options. A wrong value throws `InvalidArgumentException` with a message that names the option.
2. Applies the house style (`style`, default on): indent of 2 with tabs, spacing around braces, no `include()` rule.
3. Adds the Core rules, then the rules of each extra set, then the rules of the project (`rules`).
4. Removes the rules the project turns off (`removeRules`). This runs last, so it can remove any rule.
