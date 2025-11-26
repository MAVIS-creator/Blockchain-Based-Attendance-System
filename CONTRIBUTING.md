# Contributing to Blockchain-Based Attendance System

First off, thank you for considering contributing to the Blockchain-Based Attendance System! It's people like you that make this project such a great tool.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When you create a bug report, include as many details as possible:

- **Use a clear and descriptive title**
- **Describe the exact steps to reproduce the problem**
- **Provide specific examples**
- **Describe the behavior you observed and what you expected**
- **Include screenshots if applicable**
- **Include your environment details** (PHP version, OS, web server)

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, include:

- **Use a clear and descriptive title**
- **Provide a detailed description of the suggested enhancement**
- **Provide specific examples to demonstrate the steps**
- **Describe the current behavior and expected behavior**
- **Explain why this enhancement would be useful**

### Pull Requests

1. Fork the repository
2. Create a new branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Run tests if available
5. Commit your changes (`git commit -m 'Add some amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)
7. Open a Pull Request

#### Pull Request Guidelines

- Follow the existing code style
- Update documentation as needed
- Add tests for new features
- Ensure all tests pass
- Update the CHANGELOG if applicable

## Code Style

### PHP Code Style

- Use PSR-12 coding standards
- Use meaningful variable and function names
- Comment complex logic
- Use type hints where possible

Example:
```php
<?php

namespace MavisCreator\AttendanceSystem;

/**
 * Example class with proper documentation
 */
class Example
{
    /**
     * Example method
     * 
     * @param string $param Description
     * @return bool
     */
    public function exampleMethod(string $param): bool
    {
        // Implementation
        return true;
    }
}
```

### Security Guidelines

- Always sanitize user input
- Use CSRF protection for forms
- Never commit sensitive data (passwords, API keys)
- Use prepared statements for database queries (if added)
- Validate all file uploads
- Use HTTPS in production

### Git Commit Messages

- Use the present tense ("Add feature" not "Added feature")
- Use the imperative mood ("Move cursor to..." not "Moves cursor to...")
- Limit the first line to 72 characters or less
- Reference issues and pull requests liberally after the first line

Example:
```
Add email validation to support ticket form

- Validates email format before submission
- Shows user-friendly error messages
- Fixes #123
```

## Development Setup

1. Clone your fork:
```bash
git clone https://github.com/YOUR-USERNAME/Blockchain-Based-Attendance-System.git
cd Blockchain-Based-Attendance-System
```

2. Install dependencies:
```bash
composer install
```

3. Copy and configure environment:
```bash
cp .env.example .env
# Edit .env with your development settings
```

4. Start development server:
```bash
php -S localhost:8000
```

## Testing

Before submitting a pull request, ensure:

1. Your code follows the style guidelines
2. All existing tests pass
3. You've added tests for new features
4. Documentation is updated

Run tests:
```bash
composer test
```

## Project Structure

Familiarize yourself with the project structure in `PROJECT_STRUCTURE.md` before contributing.

## Community

- Be respectful and inclusive
- Follow the [Code of Conduct](CODE_OF_CONDUCT.md)
- Help others in issues and discussions
- Share your knowledge

## Recognition

Contributors will be added to the [CONTRIBUTORS.md](CONTRIBUTORS.md) file.

## Questions?

Feel free to contact the maintainers:
- MAVIS: mavisenquires@gmail.com
- Open an issue for discussion

Thank you for contributing! 🎉
