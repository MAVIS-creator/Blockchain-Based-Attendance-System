# GitHub Issue Templates

This directory contains GitHub issue templates that help users report bugs, request features, and ask questions in a structured way.

## Available Templates

### 1. 🐛 Bug Report (`bug_report.yml`)
Used for reporting bugs or unexpected behavior in the attendance system.

**Key Features:**
- Bug description and reproduction steps
- Expected vs actual behavior sections
- Component selection (Check-in/out, Admin Panel, Log Management, etc.)
- Environment details (PHP version, OS, web server)
- Support for screenshots and error logs
- Checklist to ensure users have searched existing issues

### 2. 🚀 Feature Request (`feature_request.yml`)
Used for suggesting new features or enhancements.

**Key Features:**
- Feature description and problem statement
- Proposed solution section
- Priority levels (Low/Medium/High)
- Component selection for targeted features
- Alternative solutions consideration
- Use cases and examples
- Support for mockups and screenshots

### 3. ❓ Question or Support (`question.yml`)
Used for asking questions or getting help.

**Key Features:**
- Question categorization (Installation, Configuration, Usage, etc.)
- Context and background information
- Optional environment details
- Support for screenshots and code snippets
- Links to documentation resources

### 4. 🛠 Task / Improvement (`task.yml`)
Used for concrete implementation tasks, refactors, or scoped repo improvements.

**Key Features:**
- Task summary with clear motivation
- Scope definition (in-scope/out-of-scope)
- Acceptance criteria checklist
- Area and effort estimation
- Links to related issues/PRs

### 5. Configuration (`config.yml`)
Controls the issue creation experience.

**Features:**
- Disables blank issues to encourage structured reporting
- Provides quick links to:
  - Documentation (README)
  - Quick Start Guide
  - Email Support
  - Contributing Guide

## How It Works

When users click "New Issue" in your repository, they will see a choice of templates:
1. Bug Report
2. Feature Request
3. Question or Support

They can also access helpful resources through the links in the configuration.

## Testing the Templates

To test these templates:
1. Go to your repository on GitHub
2. Click on "Issues" tab
3. Click "New issue"
4. You should see the three template options

## Customization

To modify templates:
1. Edit the respective `.yml` files
2. Templates use GitHub's issue form schema
3. Documentation: https://docs.github.com/en/communities/using-templates-to-encourage-useful-issues-and-pull-requests/syntax-for-issue-forms

## Benefits

- **Consistent Information**: Ensures all bug reports contain necessary debugging information
- **Faster Resolution**: Structured data helps maintainers understand and fix issues quickly
- **Better Organization**: Labels are automatically applied to categorize issues
- **User Guidance**: Helps users provide relevant information upfront
- **Reduced Back-and-Forth**: Reduces need for maintainers to ask for additional details

## Maintenance

Regularly review and update templates based on:
- Common missing information in issues
- New components or features added to the system
- User feedback about the templates
- Changes in supported environments or versions
