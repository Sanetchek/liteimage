# Contributing to LiteImage

Thank you for your interest in contributing to LiteImage! This document provides guidelines and instructions for contributing to the project.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Coding Standards](#coding-standards)
- [Testing](#testing)
- [Pull Request Process](#pull-request-process)
- [Branch Naming](#branch-naming)
- [Commit Messages](#commit-messages)

## Code of Conduct

Please be respectful and considerate of others. We aim to maintain a welcoming and inclusive community.

## Getting Started

1. Fork the repository
2. Clone your fork: `git clone https://github.com/YOUR-USERNAME/liteimage.git`
3. Create a new branch: `git checkout -b feat/your-feature-name`
4. Make your changes
5. Test your changes
6. Commit and push
7. Create a pull request

## Development Setup

### Prerequisites

- PHP 8.0 or higher
- Composer
- Node.js (for any future frontend tooling)
- WordPress installation for testing

### Installation

```bash
# Install dependencies
composer install

# For development dependencies
composer install --dev
```

### Running Tests

```bash
# Run PHP CodeSniffer
composer cs-check

# Fix coding standards automatically
composer cs-fix

# Run PHPUnit tests
composer test
```

## Coding Standards

### PHP

- Follow **PSR-12** coding standard
- Use **PHP 8.0+** features (typed properties, arrow functions, etc.)
- All classes must use **namespaces** under `LiteImage\`
- Add **PHPDoc blocks** for all classes, methods, and properties
- Use **strict types** where appropriate

Example:

```php
<?php

namespace LiteImage\Example;

/**
 * Example class description
 *
 * @package LiteImage
 * @since 3.2.0
 */
class ExampleClass {
    /**
     * Method description
     *
     * @param string $param Parameter description
     * @return bool Return description
     */
    public function exampleMethod(string $param): bool {
        // Implementation
        return true;
    }
}
```

### WordPress Specific

- **Escape all output**: Use `esc_html()`, `esc_attr()`, `esc_url()`
- **Sanitize all input**: Use `sanitize_text_field()`, `sanitize_email()`, etc.
- **Validate nonces**: Always verify nonces for form submissions
- **Check capabilities**: Use `current_user_can()` for permission checks
- **Use WordPress functions**: Prefer WordPress APIs over PHP native functions when available

### JavaScript

- Follow modern ES6+ standards
- Use `const` and `let`, avoid `var`
- Add JSDoc comments for functions
- Use arrow functions where appropriate
- Handle errors gracefully

### Security Best Practices

1. **Never trust user input** - Always validate and sanitize
2. **Use nonces** for all forms and AJAX requests
3. **Check permissions** before sensitive operations
4. **Escape output** based on context (HTML, attributes, URLs, JS)
5. **Use prepared statements** for database queries
6. **Store sensitive data** outside public directories
7. **Rate limit** resource-intensive operations

## Testing

### Writing Tests

- Write unit tests for new features
- Ensure existing tests pass before submitting PR
- Aim for good code coverage
- Test edge cases and error conditions

### Manual Testing

1. Test on a clean WordPress installation
2. Test with different PHP versions (8.0, 8.1, 8.2)
3. Test with different WordPress versions
4. Test with common themes and plugins
5. Test multisite compatibility if applicable

## Pull Request Process

1. **Update documentation** - Update README.md, CHANGELOG.md if needed
2. **Add tests** - Include relevant tests for your changes
3. **Follow coding standards** - Run `composer cs-check` before submitting
4. **Write clear PR description** - Explain what, why, and how
5. **Link related issues** - Reference any related GitHub issues
6. **Be responsive** - Address review feedback promptly

### PR Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
Describe how you tested your changes

## Checklist
- [ ] Code follows PSR-12 standard
- [ ] Self-review completed
- [ ] Comments added for complex logic
- [ ] Documentation updated
- [ ] Tests added/updated
- [ ] All tests passing
- [ ] No new warnings or errors
```

## Branch Naming

Use the following prefixes:

- `feat/` - New features
- `fix/` - Bug fixes
- `refactor/` - Code refactoring
- `docs/` - Documentation changes
- `test/` - Test additions or modifications
- `chore/` - Maintenance tasks

Examples:
- `feat/add-avif-support`
- `fix/memory-leak-in-cleanup`
- `refactor/optimize-renderer`

## Commit Messages

Follow [Conventional Commits](https://www.conventionalcommits.org/) format:

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Types

- `feat`: New feature
- `fix`: Bug fix
- `refactor`: Code refactoring
- `docs`: Documentation changes
- `test`: Test changes
- `chore`: Maintenance tasks
- `perf`: Performance improvements
- `style`: Code style changes (formatting, etc.)

### Examples

```
feat(renderer): add AVIF format support

Add support for rendering AVIF images with fallback to WebP/JPEG

Closes #123
```

```
fix(cleanup): prevent memory exhaustion on large sites

Implement pagination in thumbnail cleanup to process images in batches
of 50 instead of loading all images at once

Fixes #456
```

## Code Review Guidelines

### For Reviewers

- Be respectful and constructive
- Focus on the code, not the person
- Explain your suggestions clearly
- Approve when satisfied or request changes
- Test the changes if possible

### For Contributors

- Don't take feedback personally
- Ask for clarification if needed
- Make requested changes promptly
- Update the PR description if scope changes
- Thank reviewers for their time

## Release Process

1. Update version number in:
   - `liteimage.php` (plugin header)
   - `composer.json`
   - `src/Plugin.php` (VERSION constant)
2. Update `CHANGELOG.md` with release notes
3. Create a git tag: `git tag v3.2.0`
4. Push tag: `git push origin v3.2.0`
5. Create GitHub release with changelog
6. Submit to WordPress.org (if applicable)

## Questions?

If you have questions or need help:

- Open an issue on GitHub
- Check existing documentation
- Review closed issues for similar questions

## License

By contributing, you agree that your contributions will be licensed under the GPL-2.0-or-later license.

---

Thank you for contributing to LiteImage! 🎉

